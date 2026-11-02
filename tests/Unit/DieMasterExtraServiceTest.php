<?php

namespace Tests\Unit;

use App\Exports\FormDIE\DieMasterExtraTemplateExport;
use App\Exports\FormDIE\DieTrackingExport;
use App\Models\FormDIE\DieMasterExtra;
use App\Repositories\FormDIE\DieUsageEquipmentRepository;
use App\Repositories\FormDIE\WorkorderBlockRepository;
use App\Services\FormDIE\DieMasterExtraService;
use App\Services\FormDIE\DieTrackingService;
use App\Services\FormDIE\WoDetailService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DieMasterExtraServiceTest extends TestCase
{
    private $repo;
    private DieMasterExtraService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.workflow_connection' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        (require base_path('database/migrations/2026_11_02_000001_create_die_master_extras_table.php'))->up();
        Carbon::setTestNow('2026-11-02');

        $this->repo = Mockery::mock(DieUsageEquipmentRepository::class);
        $this->service = new DieMasterExtraService($this->repo);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function extra(string $site, string $die, ?string $date, ?string $notes = null): DieMasterExtra
    {
        return DieMasterExtra::create(['site' => $site, 'equipnumber' => $die, 'received_date' => $date, 'notes' => $notes, 'updated_by' => 'old']);
    }

    private function sheet(array $rows): string
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'die') . '.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    /** @dataProvider ageCases */
    public function test_format_age(string $received, string $expected): void
    {
        $this->assertSame($expected, DieMasterExtraService::formatAge(Carbon::parse($received)));
    }

    public function ageCases(): array
    {
        return [
            'รับวันนี้'        => ['2026-11-02', '0 วัน'],
            'ไม่ถึงเดือน'      => ['2026-10-03', '30 วัน'],
            'ครบหนึ่งเดือน'    => ['2026-10-02', '1 เดือน'],
            'ครบปีพอดีไม่มีเดือน' => ['2025-11-02', '1 ปี'],
            'ปีและเดือน'      => ['2024-08-02', '2 ปี 3 เดือน'],
        ];
    }

    /** @dataProvider dateCases */
    public function test_parse_date($input, ?string $expected): void
    {
        $this->assertSame($expected, DieMasterExtraService::parseDate($input));
    }

    public function dateCases(): array
    {
        return [
            'ISO'             => ['2024-01-05', '2024-01-05'],
            'ISO ไม่เติมศูนย์'   => ['2024-1-5', '2024-01-05'],
            'วัน/เดือน/ปี'       => ['05/01/2024', '2024-01-05'],
            'มีขีดคั่น'          => ['5-1-2024', '2024-01-05'],
            'ปี พ.ศ.'          => ['05/01/2567', '2024-01-05'],
            'serial ของ Excel' => [45296, '2024-01-05'],
            'DateTime'        => [new \DateTime('2024-01-05 13:30:00'), '2024-01-05'],
            'มีช่องว่างรอบค่า'   => [' 2024-01-05 ', '2024-01-05'],
            'วันนี้ยังรับได้'      => ['2026-11-02', '2026-11-02'],
            'พรุ่งนี้'            => ['2026-11-03', null],
            'ปี พ.ศ. ในอนาคต'  => ['01/01/2570', null],
            'วันไม่มีจริง'       => ['31/02/2024', null],
            'เดือนสลับกับวัน'    => ['13/13/2024', null],
            'ปีเก่าผิดปกติ'      => ['1949-12-31', null],
            'เลขที่ไม่ใช่วันที่'    => [5, null],
            'ข้อความ'          => ['ไม่ใช่วันที่', null],
        ];
    }

    public function test_save_returns_null_for_unknown_die_without_writing(): void
    {
        $this->repo->shouldReceive('getDieInfo')->with('NOPE', 'pgsqlpcmw')->once()->andReturn(null);

        $this->assertNull($this->service->save('W', 'NOPE', '2024-01-05', null, 'tester'));
        $this->assertSame(0, DieMasterExtra::count());
    }

    public function test_save_checks_the_connection_of_the_chosen_site(): void
    {
        $this->repo->shouldReceive('getDieInfo')->with('D1', 'pgsqlpcmp')->once()->andReturn((object) ['equipnumber' => 'D1']);

        $this->assertSame('2024-01-05', $this->service->save('P', 'D1', '2024-01-05', null, 'tester')['received_date']);
    }

    public function test_save_can_clear_values_and_keeps_one_row(): void
    {
        $this->repo->shouldReceive('getDieInfo')->andReturn((object) ['equipnumber' => 'D1']);
        $this->service->save('W', 'D1', '2024-01-05', 'เดิม', 'a');

        $extra = $this->service->save('W', 'D1', null, null, 'b');

        $this->assertNull($extra['received_date']);
        $this->assertNull($extra['age_text']);
        $this->assertNull($extra['notes']);
        $this->assertSame('b', $extra['updated_by']);
        $this->assertSame(1, DieMasterExtra::count());
    }

    public function test_same_die_number_is_kept_separately_per_site(): void
    {
        $this->extra('W', 'D1', '2024-01-05');
        $rows = [(object) ['site' => 'P', 'equipnumber' => 'D1'], (object) ['site' => 'W', 'equipnumber' => 'D1']];

        $this->assertTrue($this->service->attachToMasterRows($rows));

        $this->assertNull($rows[0]->received_date);
        $this->assertSame('2024-01-05', $rows[1]->received_date);
        $this->assertSame(1032, $rows[1]->age_days);
    }

    public function test_master_rows_show_blank_for_cleared_date(): void
    {
        $this->extra('W', 'D1', null, 'มีแต่หมายเหตุ');
        $rows = [(object) ['site' => 'W', 'equipnumber' => 'D1']];

        $this->service->attachToMasterRows($rows);

        $this->assertNull($rows[0]->received_date);
        $this->assertNull($rows[0]->age_days);
    }

    public function test_read_failure_is_reported_instead_of_thrown(): void
    {
        Schema::connection('sqlite')->drop('die_master_extras');
        $rows = [(object) ['site' => 'W', 'equipnumber' => 'D1']];

        $this->assertFalse($this->service->attachToMasterRows($rows));
        $this->assertNull($rows[0]->age_text);
        $this->assertSame(['available' => false, 'extra' => null], $this->service->forDie('W', 'D1'));
        $this->assertNull($this->service->attachToExportRows([['equipnumber' => 'D1']])[0]['received_date']);
    }

    public function test_export_rows_fall_back_to_site_from_workorder_prefix(): void
    {
        $this->extra('W', 'D1', '2024-01-05');
        $this->extra('P', 'D1', '2020-01-05');

        $rows = $this->service->attachToExportRows([
            ['equipnumber' => 'D1', 'workordernumber' => 'N2600136'],
            ['equipnumber' => 'D1', 'workordernumber' => '+2600136'],
            ['equipnumber' => 'D1', 'site' => 'P', 'workordernumber' => 'N2600136'],
            ['equipnumber' => 'D2'],
        ]);

        $this->assertSame('2024-01-05', $rows[0]['received_date']);
        $this->assertSame('2020-01-05', $rows[1]['received_date']);
        $this->assertSame('2020-01-05', $rows[2]['received_date']);
        $this->assertNull($rows[3]['received_date']);
    }

    public function test_import_rejects_file_without_required_headers(): void
    {
        $result = $this->service->import($this->sheet([['Site', 'Die#', 'วันที่'], ['W', 'D1', '2024-01-05']]), 'tester');

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame(0, DieMasterExtra::count());
    }

    public function test_import_reports_row_numbers_for_duplicates_and_blank_die(): void
    {
        $this->repo->shouldReceive('getExistingEquipnumbers')->andReturn(['D1']);

        $result = $this->service->import($this->sheet([
            ['Site', 'Die#', 'วันที่รับเข้า', 'หมายเหตุ'],
            ['W', 'D1', '2024-01-05', null],
            ['W', 'D1', '2024-02-05', null],
            ['W', null, '2024-01-05', null],
        ]), 'tester');

        $this->assertSame(['แถว 3: W / D1 ซ้ำในไฟล์', 'แถว 4: ไม่มี Die#'], $result['errors']);
        $this->assertSame(0, DieMasterExtra::count());
    }

    public function test_import_checks_each_site_against_its_own_connection(): void
    {
        $this->repo->shouldReceive('getExistingEquipnumbers')->with(['D1'], 'pgsqlpcmw')->once()->andReturn(['D1']);
        $this->repo->shouldReceive('getExistingEquipnumbers')->with(['D1'], 'pgsqlpcmp')->once()->andReturn([]);

        $result = $this->service->import($this->sheet([
            ['Site', 'Die#', 'วันที่รับเข้า', 'หมายเหตุ'],
            ['w', 'D1', '2024-01-05', null],
            ['P', 'D1', '2024-01-05', null],
        ]), 'tester');

        $this->assertSame(['แถว 3: ไม่พบ P / D1 ในฐาน PCM'], $result['errors']);
        $this->assertSame(0, DieMasterExtra::count());
    }

    public function test_import_merges_without_clearing_existing_values(): void
    {
        $this->extra('W', 'D1', '2020-01-01', 'เดิม');
        $this->extra('W', 'D2', '2020-01-01', 'เดิม');
        $this->repo->shouldReceive('getExistingEquipnumbers')->andReturn(['D1', 'D2']);

        $result = $this->service->import($this->sheet([
            ['หมายเหตุ', 'วันที่รับเข้า', 'Die#', 'Site'],
            ['ใหม่', null, 'D1', 'W'],
            [null, '2024-01-05', 'D2', 'W'],
            [null, null, 'D9', 'W'],
            [null, null, null, null],
        ]), 'importer');

        $this->assertSame(['imported' => 2, 'skipped' => 1, 'errors' => []], $result);
        $first = DieMasterExtra::where('equipnumber', 'D1')->first();
        $second = DieMasterExtra::where('equipnumber', 'D2')->first();
        $this->assertSame('2020-01-01', $first->received_date->toDateString());
        $this->assertSame('ใหม่', $first->notes);
        $this->assertSame('2024-01-05', $second->received_date->toDateString());
        $this->assertSame('เดิม', $second->notes);
        $this->assertSame('importer', $second->updated_by);
    }

    public function test_tracking_export_keeps_headings_and_values_aligned(): void
    {
        $export = new DieTrackingExport([['workordernumber' => 'N1', 'to_class' => 'DRAWING', 'received_date' => '2024-01-05', 'age_text' => '2 ปี']]);
        $headings = $export->headings();
        $row = $export->array()[0];

        $this->assertCount(count($headings), $row);
        $this->assertSame('2024-01-05', $row[array_search('วันที่รับเข้า', $headings, true)]);
        $this->assertSame('2 ปี', $row[array_search('อายุไดร์', $headings, true)]);
        $this->assertSame('DRAWING', $row[array_search('To Dept', $headings, true)]);
    }

    public function test_template_headings_are_accepted_by_import(): void
    {
        $result = $this->service->import($this->sheet([(new DieMasterExtraTemplateExport())->headings()]), 'tester');

        $this->assertSame(['imported' => 0, 'skipped' => 0, 'errors' => []], $result);
    }

    public function test_tracking_service_sums_real_totals_across_sites(): void
    {
        $repo = Mockery::mock(DieUsageEquipmentRepository::class);
        $tracking = new DieTrackingService(Mockery::mock(WorkorderBlockRepository::class), $repo, Mockery::mock(WoDetailService::class));
        $repo->shouldReceive('getDieMaster')->twice()->andReturn([]);
        $repo->shouldReceive('getDieMasterCount')->with('pgsqlpcmw', [])->andReturn(21931);
        $repo->shouldReceive('getDieMasterCount')->with('pgsqlpcmp', [])->andReturn(0);
        $repo->shouldReceive('getCurrentLocations')->with('pgsqlpcmw', [])->andReturn([(object) ['equipnumber' => 'D1']]);
        $repo->shouldReceive('getCurrentLocationCount')->with('pgsqlpcmw', [])->andReturn(1145);

        $master = $tracking->dieMaster(['pgsqlpcmw', 'pgsqlpcmp']);
        $location = $tracking->currentLocations('pgsqlpcmw');

        $this->assertSame(21931, $master['total_count']);
        $this->assertSame(1145, $location['total_count']);
        $this->assertSame('W', $location['rows'][0]->site);
    }
}

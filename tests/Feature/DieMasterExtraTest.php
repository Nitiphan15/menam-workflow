<?php

namespace Tests\Feature;

use App\Models\FormDIE\DieMasterExtra;
use App\Models\Users\User;
use App\Repositories\FormDIE\DieUsageEquipmentRepository;
use App\Services\FormDIE\DieMasterExtraService;
use App\Services\FormDIE\DieTrackingService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DieMasterExtraTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'http://localhost', 'database.workflow_connection' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        \Illuminate\Support\Facades\URL::forceRootUrl('http://localhost');
        DB::purge('sqlite');
        $migration = require base_path('database/migrations/2026_11_02_000001_create_die_master_extras_table.php');
        $migration->up();
        $this->withoutMiddleware(\App\Http\Middleware\LogUserActivity::class);
        $this->mock(DieUsageEquipmentRepository::class, function ($mock) {
            $mock->shouldReceive('getDieInfo')->andReturnUsing(fn($die) => $die === 'D0001' ? (object) ['equipnumber' => 'D0001'] : null);
            $mock->shouldReceive('getExistingEquipnumbers')->andReturn(['D0001', 'D0002']);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function login(bool $canEdit): void
    {
        $user = \Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id' => 42, 'name' => 'DIE Test', 'is_superadmin' => false]);
        $user->shouldReceive('hasRoleCode')->andReturn($canEdit);
        $this->actingAs($user);
    }

    private function xlsx(array $rows): UploadedFile
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'die') . '.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'dies.xlsx', null, null, true);
    }

    public function test_guest_and_user_without_role_cannot_write(): void
    {
        $body = ['site' => 'W', 'equipnumber' => 'D0001', 'received_date' => '2024-01-05'];
        $this->putJson('/die-tracking/api/extras', $body)->assertUnauthorized();
        $this->postJson('/die-tracking/extras/import')->assertUnauthorized();

        $this->login(false);
        $this->putJson('/die-tracking/api/extras', $body)->assertForbidden();
        $this->postJson('/die-tracking/extras/import')->assertForbidden();
        $this->assertSame(0, DieMasterExtra::count());
    }

    public function test_saves_and_updates_received_date_with_editor(): void
    {
        $this->login(true);
        $body = ['site' => 'W', 'equipnumber' => 'D0001', 'received_date' => '2024-01-05', 'notes' => 'ล็อตแรก'];
        $this->putJson('/die-tracking/api/extras', $body)->assertOk()
            ->assertJsonPath('extra.received_date', '2024-01-05')
            ->assertJsonPath('extra.updated_by', 'DIE Test');
        $this->putJson('/die-tracking/api/extras', ['received_date' => '2024-02-01'] + $body)->assertOk();

        $this->assertSame(1, DieMasterExtra::count());
        $this->assertSame('2024-02-01', DieMasterExtra::first()->received_date->toDateString());
    }

    public function test_rejects_future_date_and_unknown_die(): void
    {
        $this->login(true);
        $this->putJson('/die-tracking/api/extras', ['site' => 'W', 'equipnumber' => 'D0001', 'received_date' => Carbon::tomorrow()->toDateString()])
            ->assertStatus(422);
        $this->putJson('/die-tracking/api/extras', ['site' => 'W', 'equipnumber' => 'NOPE', 'received_date' => '2024-01-05'])
            ->assertStatus(422);
        $this->assertSame(0, DieMasterExtra::count());
    }

    public function test_import_merges_and_blank_cells_keep_existing_values(): void
    {
        DieMasterExtra::create(['site' => 'W', 'equipnumber' => 'D0001', 'received_date' => '2020-01-01', 'notes' => 'เดิม', 'updated_by' => 'old']);
        $this->login(true);

        $this->post('/die-tracking/extras/import', ['file' => $this->xlsx([
            ['Site', 'Die#', 'วันที่รับเข้า', 'หมายเหตุ'],
            ['W', 'D0001', null, 'ใหม่'],
            ['W', 'D0002', '05/01/2567', null],
            ['W', 'D0003', null, null],
        ])], ['Accept' => 'application/json'])->assertOk()
            ->assertJson(['imported' => 2, 'skipped' => 1, 'errors' => []]);

        $first = DieMasterExtra::where('equipnumber', 'D0001')->first();
        $this->assertSame('2020-01-01', $first->received_date->toDateString());
        $this->assertSame('ใหม่', $first->notes);
        $this->assertSame('2024-01-05', DieMasterExtra::where('equipnumber', 'D0002')->first()->received_date->toDateString());
    }

    public function test_import_with_any_bad_row_writes_nothing(): void
    {
        $this->login(true);
        $this->post('/die-tracking/extras/import', ['file' => $this->xlsx([
            ['Site', 'Die#', 'วันที่รับเข้า', 'หมายเหตุ'],
            ['W', 'D0001', '2024-01-05', null],
            ['W', 'D0009', '2024-01-05', null],
            ['X', 'D0002', 'ไม่ใช่วันที่', null],
        ])], ['Accept' => 'application/json'])->assertStatus(422)
            ->assertJsonPath('imported', 0)
            ->assertJsonCount(2, 'errors');

        $this->assertSame(0, DieMasterExtra::count());
    }

    public function test_master_shows_fresh_value_and_survives_when_extras_table_is_unavailable(): void
    {
        Cache::flush();
        $this->mock(DieTrackingService::class, fn($mock) => $mock->shouldReceive('dieMaster')
            ->andReturnUsing(fn() => ['rows' => [(object) ['site' => 'W', 'equipnumber' => 'D0001']]]));

        $this->getJson('/die-tracking/api/master')->assertOk()
            ->assertJsonPath('extras_available', true)
            ->assertJsonPath('rows.0.received_date', null);

        DieMasterExtra::create(['site' => 'W', 'equipnumber' => 'D0001', 'received_date' => Carbon::today()->subYears(2)->toDateString(), 'updated_by' => 'x']);
        $this->getJson('/die-tracking/api/master')->assertOk()
            ->assertJsonPath('rows.0.age_text', '2 ปี');

        \Illuminate\Support\Facades\Schema::connection('sqlite')->drop('die_master_extras');
        $this->getJson('/die-tracking/api/master')->assertOk()
            ->assertJsonPath('extras_available', false)
            ->assertJsonPath('rows.0.equipnumber', 'D0001');
    }

    public function test_import_buttons_render_only_for_editor(): void
    {
        \Illuminate\Support\Facades\View::share('errors', new \Illuminate\Support\ViewErrorBag());

        $this->login(false);
        $html = view('formdie.master')->render();
        $this->assertStringNotContainsString('dmExtraImportBtn', $html);
        $this->assertStringContainsString('window.DM_CAN_EDIT_EXTRA = false', $html);

        $this->login(true);
        $html = view('formdie.master')->render();
        $this->assertStringContainsString('dmExtraImportBtn', $html);
        $this->assertStringContainsString('window.DM_CAN_EDIT_EXTRA = true', $html);
    }

    public function test_date_parsing_and_age_text(): void
    {
        Carbon::setTestNow('2026-11-02');
        $this->assertSame('2024-01-05', DieMasterExtraService::parseDate('2024-01-05'));
        $this->assertSame('2024-01-05', DieMasterExtraService::parseDate('5/1/2024'));
        $this->assertSame('2024-01-05', DieMasterExtraService::parseDate('05/01/2567'));
        $this->assertSame('2024-01-05', DieMasterExtraService::parseDate(45296));
        $this->assertNull(DieMasterExtraService::parseDate('31/02/2024'));
        $this->assertNull(DieMasterExtraService::parseDate('2027-01-01'));
        $this->assertNull(DieMasterExtraService::parseDate(5));

        $this->assertSame('2 ปี 3 เดือน', DieMasterExtraService::formatAge(Carbon::parse('2024-08-02')));
        $this->assertSame('3 เดือน', DieMasterExtraService::formatAge(Carbon::parse('2026-08-02')));
        $this->assertSame('5 วัน', DieMasterExtraService::formatAge(Carbon::parse('2026-10-28')));
    }
}

<?php

namespace Tests\Unit;

use App\Services\FormWR\WireRodDeadstockReviewImporter;
use App\Services\FormWR\WireRodDeadstockSummary;
use App\Support\FormWR\WireRodDeadstockRule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class WireRodDeadstockSummaryImportTest extends TestCase
{
    private const CONN = 'wr_ds_import_test';

    public function test_incoming_is_displayed_as_normal_stock(): void
    {
        $this->assertSame('Normal Stock', WireRodDeadstockRule::stockStatusLabel('INCOMING'));
        $this->assertSame('Dead Stock', WireRodDeadstockRule::stockStatusLabel('DEAD'));
        $this->assertSame('Unknown', WireRodDeadstockRule::stockStatusLabel('UNKNOWN'));
    }

    public function test_aging_bucket_boundaries(): void
    {
        $this->assertSame('0_90', WireRodDeadstockRule::agingBucket(0));
        $this->assertSame('0_90', WireRodDeadstockRule::agingBucket(90));
        $this->assertSame('91_180', WireRodDeadstockRule::agingBucket(91));
        $this->assertSame('91_180', WireRodDeadstockRule::agingBucket(180));
        $this->assertSame('181_365', WireRodDeadstockRule::agingBucket(181));
        $this->assertSame('181_365', WireRodDeadstockRule::agingBucket(365));
        $this->assertSame('over_365', WireRodDeadstockRule::agingBucket(366));
        $this->assertSame('0_90', WireRodDeadstockRule::agingBucket(-3));
        $this->assertNull(WireRodDeadstockRule::agingBucket(null));
    }

    public function test_division_summary_key(): void
    {
        $this->assertSame('D2', WireRodDeadstockRule::divisionSummaryKey('d2', true));
        $this->assertSame('PLANNING', WireRodDeadstockRule::divisionSummaryKey(null, false));
        $this->assertSame('NO_SALES', WireRodDeadstockRule::divisionSummaryKey(null, true));
        $this->assertSame('OTHER', WireRodDeadstockRule::divisionSummaryKey('D4', true));
        $this->assertSame('OTHER', WireRodDeadstockRule::divisionSummaryKey('ASSADAPORN', true));
    }

    public function test_division_summary_totals_and_excludes_pcs(): void
    {
        $rows = collect([
            $this->row('D1', 'DEAD', 100, '["A"]'),
            $this->row('D1', 'INCOMING', 50, '["A"]'),
            $this->row(null, 'DEAD', 30, '[]'),
            $this->row(null, 'INCOMING', 20, null),
            $this->row(null, 'INCOMING', 7, '["B"]'),
            $this->row('D8', 'DEAD', 9, '["C"]', 'PCS'),
        ]);

        $summary = (new WireRodDeadstockSummary)->byDivision($rows);
        $byKey = collect($summary['rows'])->keyBy('key');

        $this->assertSame(100.0, $byKey['D1']['dead']);
        $this->assertSame(50.0, $byKey['D1']['normal']);
        $this->assertSame(150.0, $byKey['D1']['total']);
        $this->assertSame(50.0, $byKey['PLANNING']['total']);
        $this->assertSame(7.0, $byKey['NO_SALES']['normal']);
        $this->assertFalse($byKey->has('OTHER'));
        $this->assertSame(0.0, $byKey['D9']['total']);
        $this->assertSame(207.0, $summary['totals']['total']);
        $this->assertSame(1, $summary['excluded']['lots']);
        $this->assertSame(9.0, $summary['excluded']['pcs']);
    }

    public function test_aging_summary(): void
    {
        $rows = collect([
            $this->row('D1', 'INCOMING', 10, '[]', 'KG', 5, 100),
            $this->row('D1', 'INCOMING', 20, '[]', 'KG', 120, 200),
            $this->row('D1', 'DEAD', 30, '[]', 'KG', 200, 300),
            $this->row('D1', 'DEAD', 40, '[]', 'KG', 500, 400),
            $this->row('D8', 'DEAD', 3, '[]', 'PCS', 500, 50),
        ]);

        $summary = (new WireRodDeadstockSummary)->byAging($rows);
        $byKey = collect($summary['rows'])->keyBy('key');

        $this->assertSame(10.0, $byKey['0_90']['kg']);
        $this->assertSame(20.0, $byKey['91_180']['kg']);
        $this->assertSame(30.0, $byKey['181_365']['kg']);
        $this->assertSame(2, $byKey['over_365']['lots']);
        $this->assertSame(40.0, $byKey['over_365']['kg']);
        $this->assertSame(3.0, $byKey['over_365']['pcs']);
        $this->assertFalse($byKey->has('unknown'));
        $this->assertSame(100.0, $summary['totals']['kg']);
        $this->assertSame(1050.0, $summary['totals']['value']);
    }

    public function test_status_normalization_accepts_codes_and_thai_labels(): void
    {
        $this->assertSame('open', WireRodDeadstockReviewImporter::normalizeStatus('OPEN'));
        $this->assertSame('waiting_follow_up', WireRodDeadstockReviewImporter::normalizeStatus('waiting follow up'));
        $this->assertSame('in_progress', WireRodDeadstockReviewImporter::normalizeStatus('มีกำหนดส่งมอบแล้ว'));
        $this->assertSame('waiting_follow_up', WireRodDeadstockReviewImporter::normalizeStatus('กำลังติดตาม รอคำตอบ'));
        $this->assertNull(WireRodDeadstockReviewImporter::normalizeStatus('done'));
    }

    public function test_export_headings_are_recognised_by_import(): void
    {
        $map = WireRodDeadstockReviewImporter::headerMap();
        foreach (['Site' => 'site', 'Part Number' => 'part_number', 'Serial / Heat' => 'serial', 'Review Status' => 'review_status',
            'Next Follow-up' => 'next_follow_up_date', 'Review Detail' => 'review_detail', 'Sales Remark' => 'sales_remark'] as $heading => $key) {
            $this->assertSame($key, $map[WireRodDeadstockReviewImporter::normalizeHeader($heading)] ?? null, $heading);
        }
    }

    public function test_import_creates_updates_and_respects_permission(): void
    {
        $this->makeTables();
        $db = DB::connection(self::CONN);
        $db->table('wr_ds_item_reviews')->insert(['lifecycle_id' => 2, 'review_status' => 'open', 'review_detail' => 'old',
            'created_at' => now(), 'updated_at' => now()]);

        $path = $this->xlsx([
            WireRodDeadstockReviewImporter::templateHeaders(),
            ['WIRE', 'P1', 'S-001', 'กำลังติดตาม รอคำตอบ', '31/12/2026', 'โทรหาลูกค้าแล้ว', '', '', ''],
            ['WIRE', 'P2', 's-002', '', '', 'new detail', '', '', ''],
            ['PLUS', 'P3', 'S-003', 'in_progress', '', 'x', '', '', ''],
            ['WIRE', 'P9', 'S-404', 'open', '', 'x', '', '', ''],
            ['WIRE', 'P1', 'S-001', 'open', '', 'dup', '', '', ''],
            ['WIRE', 'P1', 'S-005', 'open', '', '', '', '', ''],
            ['WIRE', 'P1', 'S-006', 'finished', '', '', '', '', ''],
        ]);

        $importer = app(WireRodDeadstockReviewImporter::class);
        $canEdit = fn ($item) => $item->site_code === 'WIRE';

        $dry = $importer->import($path, $canEdit, 7, 'Tester', true);
        $this->assertSame(2, $dry['stats']['saved']);
        $this->assertSame(1, $db->table('wr_ds_item_reviews')->count());

        $result = $importer->import($path, $canEdit, 7, 'Tester', false);
        $s = $result['stats'];
        $this->assertSame(7, $s['read']);
        $this->assertSame(1, $s['created']);
        $this->assertSame(1, $s['updated']);
        $this->assertSame(1, $s['unauthorized']);
        $this->assertSame(1, $s['unmatched']);
        $this->assertSame(1, $s['duplicate']);
        $this->assertSame(1, $s['unchanged']); // S-005: only "open", no review yet -> not created
        $this->assertSame(1, $s['error']);     // S-006: unsupported status

        $created = $db->table('wr_ds_item_reviews')->where('lifecycle_id', 1)->first();
        $this->assertSame('waiting_follow_up', $created->review_status);
        $this->assertSame('2026-12-31', substr((string) $created->next_follow_up_date, 0, 10));
        $updated = $db->table('wr_ds_item_reviews')->where('lifecycle_id', 2)->first();
        $this->assertSame('new detail', $updated->review_detail);
        $this->assertSame('open', $updated->review_status);
        $this->assertNull($db->table('wr_ds_item_reviews')->where('lifecycle_id', 3)->first());
        $this->assertSame(2, $db->table('wr_ds_item_review_logs')->where('source', 'IMPORT')->count());

        $again = $importer->import($path, $canEdit, 7, 'Tester', false);
        $this->assertSame(0, $again['stats']['saved']);
        @unlink($path);
    }

    private function row(?string $division, string $status, float $qty, ?string $customers, string $unit = 'KG', ?int $age = 10, float $value = 0): object
    {
        return (object) ['effective_division_code' => $division, 'stock_status' => $status, 'current_qty' => $qty,
            'customer_json' => $customers, 'display_unit' => $unit, 'stock_age_days' => $age, 'current_value_thb' => $value];
    }

    private function makeTables(): void
    {
        config([
            'database.connections.'.self::CONN => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'database.workflow_connection' => self::CONN,
        ]);
        $schema = Schema::connection(self::CONN);
        $schema->create('wr_ds_item_lifecycles', function (Blueprint $t) {
            $t->id();
            $t->integer('cycle_no');
            $t->boolean('is_current');
        });
        $schema->create('wr_ds_current_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('lifecycle_id');
            $t->string('site_code');
            $t->string('part_number');
            $t->string('serial_number');
            $t->string('trace_status')->nullable();
            $t->string('effective_sales_key')->nullable();
        });
        $schema->create('wr_ds_item_reviews', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('lifecycle_id');
            $t->string('review_status');
            foreach (['review_detail', 'corrective_action', 'preventive_action', 'sales_remark'] as $c) {
                $t->text($c)->nullable();
            }
            $t->date('next_follow_up_date')->nullable();
            $t->integer('reviewed_by')->nullable();
            $t->string('reviewed_by_name')->nullable();
            $t->dateTime('reviewed_at')->nullable();
            $t->integer('created_by')->nullable();
            $t->integer('updated_by')->nullable();
            $t->dateTime('created_at');
            $t->dateTime('updated_at');
        });
        $schema->create('wr_ds_item_review_logs', function (Blueprint $t) {
            $t->id();
            foreach (['lifecycle_id', 'review_id', 'cycle_no', 'changed_by'] as $c) {
                $t->integer($c)->nullable();
            }
            foreach (['site_code', 'serial_number', 'action', 'source', 'changed_by_name'] as $c) {
                $t->string($c)->nullable();
            }
            foreach (['changed_fields', 'before_values', 'after_values'] as $c) {
                $t->text($c)->nullable();
            }
            $t->dateTime('changed_at');
            $t->dateTime('created_at');
        });

        $db = DB::connection(self::CONN);
        foreach ([[1, 'WIRE', 'P1', 'S-001'], [2, 'WIRE', 'P2', 'S-002'], [3, 'PLUS', 'P3', 'S-003'], [5, 'WIRE', 'P1', 'S-005'], [6, 'WIRE', 'P1', 'S-006']] as [$id, $site, $part, $serial]) {
            $db->table('wr_ds_item_lifecycles')->insert(['id' => $id, 'cycle_no' => 1, 'is_current' => 1]);
            $db->table('wr_ds_current_items')->insert(['lifecycle_id' => $id, 'site_code' => $site, 'part_number' => $part, 'serial_number' => $serial]);
        }
    }

    private function xlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'wr_import_test_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}

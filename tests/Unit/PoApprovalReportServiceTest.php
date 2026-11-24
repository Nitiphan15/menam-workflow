<?php

namespace Tests\Unit;

use App\Exports\Po\PoApprovalReportExport;
use App\Services\Po\PoApprovalReportService;
use App\Services\Po\PoErpCompletionLookup;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * รายงาน/แดชบอร์ดอนุมัติ PO: cycle timing from wf_action_histories, summary numbers,
 * department scope and the Excel sheets. sqlsrv_menam is an in-memory sqlite.
 */
class PoApprovalReportServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('database.workflow_connection', 'sqlsrv_menam');
        config()->set('database.connections.sqlsrv_menam', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
        DB::purge('sqlsrv_menam');
        Carbon::setTestNow('2026-11-24 12:00:00');
        $this->fakeErpDone([]);

        $schema = Schema::connection('sqlsrv_menam');
        $schema->create('po_headers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workflow_id')->nullable();
            $t->string('site')->nullable();
            $t->string('ordnumber');
            $t->date('transdate')->nullable();
            $t->string('f1')->nullable();
            $t->string('requester_name')->nullable();
            $t->decimal('amount', 18, 2)->nullable();
            $t->decimal('netamount', 18, 2)->nullable();
            $t->string('curr')->nullable();
        });
        $schema->create('wf_forms', function (Blueprint $t) {
            $t->id();
            $t->string('app_code');
            $t->string('form_status');
            $t->integer('current_step_no');
            $t->dateTime('request_dt')->nullable();
        });
        $schema->create('wf_action_histories', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('wf_form_id');
            $t->integer('step_no');
            $t->unsignedBigInteger('actor_user_id')->nullable();
            $t->string('action_type');
            $t->dateTime('created_at');
        });
        $schema->create('wf_form_authorizes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('wf_form_id');
            $t->integer('step_no');
            $t->unsignedBigInteger('approver_user_id');
            $t->string('status')->nullable();
        });
        $schema->create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        $schema->create('departments', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->string('name');
            $t->boolean('is_active')->default(true);
        });

        $db = DB::connection('sqlsrv_menam');
        $db->table('users')->insert([
            ['id' => 1, 'name' => 'Buyer'],
            ['id' => 2, 'name' => 'Purchase A'],
            ['id' => 3, 'name' => 'Purchase B'],
            ['id' => 4, 'name' => 'Head QA'],
            ['id' => 5, 'name' => 'Head PD'],
        ]);
        $db->table('departments')->insert([
            ['id' => 10, 'code' => 'QA', 'name' => 'QA', 'is_active' => 1],
            ['id' => 20, 'code' => 'PD', 'name' => 'PD', 'is_active' => 1],
        ]);

        // PO 1 (QA): approved, purchase 10h, dept 5h → 15h total, within SLA.
        $this->po(1, 'PO1001', 'QA - Lab', 'CLOESD', 999, '2026-11-01 08:00:00', [
            [1, 1, 'SUBMIT', '2026-11-01 08:00:00'],
            [2, 2, 'APPROVE', '2026-11-01 12:00:00'],
            [2, 3, 'APPROVE', '2026-11-01 18:00:00'],
            [3, 4, 'APPROVE', '2026-11-01 23:00:00'],
            [999, 4, 'COMPLETE', '2026-11-01 23:00:00'],
        ]);
        // PO 2 (QA): rejected once, resubmitted; measured from resubmission → 30h purchase + 3h dept (SLA miss).
        $this->po(2, 'PO1002', 'QA - Lab', 'CLOESD', 999, '2026-11-02 08:00:00', [
            [1, 1, 'SUBMIT', '2026-11-02 08:00:00'],
            [2, 2, 'APPROVE', '2026-11-02 09:00:00'],
            [2, 3, 'REJECT', '2026-11-02 10:00:00'],
            [1, 1, 'SUBMIT', '2026-11-03 00:00:00'],
            [2, 2, 'APPROVE', '2026-11-04 01:00:00'],
            [2, 3, 'APPROVE', '2026-11-04 06:00:00'],
            [3, 4, 'APPROVE', '2026-11-04 09:00:00'],
            [999, 4, 'COMPLETE', '2026-11-04 09:00:00'],
        ]);
        // PO 3 (PD): waiting at step 3 since purchase finished 2026-11-24 00:00 → 12h.
        $this->po(3, 'PO1003', 'PD - Wire', 'REQUESTED', 3, '2026-11-23 08:00:00', [
            [1, 1, 'SUBMIT', '2026-11-23 08:00:00'],
            [2, 2, 'APPROVE', '2026-11-23 20:00:00'],
            [2, 3, 'APPROVE', '2026-11-24 00:00:00'],
        ]);
        $db->table('wf_form_authorizes')->insert([
            ['wf_form_id' => 3, 'step_no' => 3, 'approver_user_id' => 5, 'status' => 'PENDING'],
            ['wf_form_id' => 3, 'step_no' => 2, 'approver_user_id' => 2, 'status' => 'APPROVED'],
        ]);
        // Outside date range, must not be counted.
        $this->po(4, 'PO0999', 'QA - Lab', 'CLOESD', 999, '2026-06-01 08:00:00', [
            [1, 1, 'SUBMIT', '2026-06-01 08:00:00'],
            [999, 4, 'COMPLETE', '2026-06-01 09:00:00'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** ERP is never called in tests: $done = ["wire::PO1003" => "รับของแล้ว"]. */
    private function fakeErpDone(array $done): void
    {
        $this->app->instance(PoErpCompletionLookup::class, new class ($done) extends PoErpCompletionLookup {
            public function __construct(private array $done) {}

            public function doneInErp(array $pos): array
            {
                return $this->done;
            }
        });
        $this->app->forgetInstance(PoApprovalReportService::class);
    }

    private function po(int $id, string $ordnumber, string $department, string $status, int $step, string $requestDt, array $histories): void
    {
        $db = DB::connection('sqlsrv_menam');
        $db->table('wf_forms')->insert(['id' => $id, 'app_code' => 'po', 'form_status' => $status, 'current_step_no' => $step, 'request_dt' => $requestDt]);
        $db->table('po_headers')->insert([
            'id' => $id, 'workflow_id' => $id, 'site' => 'wire', 'ordnumber' => $ordnumber, 'transdate' => substr($requestDt, 0, 10),
            'f1' => $department, 'requester_name' => 'Req', 'netamount' => 1000, 'curr' => 'THB',
        ]);
        foreach ($histories as [$stepNo, $actor, $action, $at]) {
            $db->table('wf_action_histories')->insert([
                'wf_form_id' => $id, 'step_no' => $stepNo, 'actor_user_id' => $actor, 'action_type' => $action, 'created_at' => $at,
            ]);
        }
    }

    private function report(?array $scope = null, array $filters = []): array
    {
        return app(PoApprovalReportService::class)->buildUncached(array_merge([
            'date_from' => '2026-11-01', 'date_to' => '2026-11-24', 'site' => null, 'department' => null, 'status' => null,
        ], $filters), $scope);
    }

    private function row(array $report, string $ordnumber): array
    {
        return collect($report['rows'])->firstWhere('ordnumber', $ordnumber);
    }

    public function test_cycle_timing_uses_latest_submission_after_reject(): void
    {
        $report = $this->report();

        $first = $this->row($report, 'PO1001');
        $this->assertSame(10.0, $first['purchase_hours']);
        $this->assertSame(5.0, $first['department_hours']);
        $this->assertSame(15.0, $first['total_hours']);
        $this->assertTrue($first['within_sla']);
        $this->assertStringContainsString('Purchase A 01/11/2026 12:00', $first['purchase_approvers']);

        $resubmitted = $this->row($report, 'PO1002');
        $this->assertSame(1, $resubmitted['reject_count']);
        $this->assertSame('03/11/2026 00:00', $resubmitted['submitted_at']);
        $this->assertSame(30.0, $resubmitted['purchase_hours']);
        $this->assertSame(33.0, $resubmitted['total_hours']);
        $this->assertFalse($resubmitted['within_sla']);
        $this->assertStringNotContainsString('09:00', $resubmitted['purchase_approvers'], 'approvals from the rejected round are dropped');
    }

    public function test_summary_cards_and_pending_list(): void
    {
        $report = $this->report();
        $s = $report['summary'];

        $this->assertSame(3, $s['total']);
        $this->assertSame(2, $s['approved']);
        $this->assertSame(1, $s['pending']);
        $this->assertSame(24.0, $s['avg_hours']);
        $this->assertSame(24.0, $s['median_hours']);
        $this->assertSame(50.0, $s['sla_rate']);

        $this->assertCount(1, $report['pending']);
        $this->assertSame('PO1003', $report['pending'][0]['ordnumber']);
        $this->assertSame(12.0, $report['pending'][0]['waiting_hours']);
        $this->assertSame('Head PD', $report['pending'][0]['waiting_for']);
        $this->assertSame('หัวหน้าแผนก', $report['pending'][0]['current_step_label']);

        $this->assertSame('11/2026', $report['monthly'][0]['label']);
        $this->assertSame(['PD', 'QA'], $report['department_options']);
    }

    public function test_department_scope_and_filters(): void
    {
        $qaOnly = $this->report(['department_id' => 10, 'position' => null]);
        $this->assertEqualsCanonicalizing(['PO1001', 'PO1002'], array_column($qaOnly['rows'], 'ordnumber'));

        $noDepartment = $this->report(['department_id' => 0, 'position' => null]);
        $this->assertSame([], $noDepartment['rows']);

        $pending = $this->report(null, ['status' => 'pending']);
        $this->assertSame(['PO1003'], array_column($pending['rows'], 'ordnumber'));

        $plus = $this->report(null, ['site' => 'plus']);
        $this->assertSame([], $plus['rows']);
    }

    public function test_excel_has_three_sheets_with_po_number_as_text(): void
    {
        $export = new PoApprovalReportExport($this->report(), 'ทุกแผนก');
        $sheets = $export->sheets();

        $this->assertSame(['รายงานอนุมัติ', 'สรุป', 'แยกตามแผนก'], array_map(fn ($s) => $s->title(), $sheets));
        $this->assertSame('PO1003', $sheets[0]->array()[0][0]);
        $this->assertSame('@', $sheets[0]->columnFormats()['A']);
    }

    public function test_pdf_view_renders_thai_report(): void
    {
        $html = view('pdf.po-approval-report', [
            'report' => $this->report(),
            'scopeLabel' => 'ทุกแผนก',
            'statusLabels' => PoApprovalReportService::STATUS_LABELS,
            'slaHours' => 24,
            'totalRows' => 3,
            'rowLimit' => 1500,
        ])->render();

        $this->assertStringContainsString('รายงานการอนุมัติ PO Online', $html);
        $this->assertStringContainsString('PO1002', $html);
        $this->assertStringContainsString('1 วัน 9 ชม.', $html);
    }

    public function test_cached_report_is_not_reused_after_switching_database(): void
    {
        $filters = ['date_from' => '2026-11-01', 'date_to' => '2026-11-24', 'site' => null, 'department' => null, 'status' => null];
        $service = app(PoApprovalReportService::class);

        $first = $service->build($filters);
        $this->assertSame(1, $this->row($first, 'PO1001')['po_id']);

        // Same app, other database (DB_PROFILE switch): same PO number lives under another id.
        config()->set('database.connections.sqlsrv_menam.database', 'other_db.sqlite');
        DB::connection('sqlsrv_menam')->table('po_headers')->where('ordnumber', 'PO1001')->update(['id' => 501]);

        $second = $service->build($filters);
        $this->assertSame(501, $this->row($second, 'PO1001')['po_id'], 'must not serve rows cached from the previous database');
    }

    public function test_pending_po_already_received_in_erp_is_not_counted_as_pending(): void
    {
        $this->fakeErpDone(['wire::PO1003' => 'รับของแล้ว · ตั้งหนี้ (invoice) แล้ว']);
        $report = $this->report();

        $row = $this->row($report, 'PO1003');
        $this->assertSame('erp_done', $row['status_key']);
        $this->assertSame('ปิด/รับของใน ERP แล้ว', $row['status_label']);
        $this->assertSame('ค้างขั้น หัวหน้าแผนก ใน PO Online', $row['current_step_label']);
        $this->assertSame('รับของแล้ว · ตั้งหนี้ (invoice) แล้ว', $row['erp_note']);
        $this->assertFalse($row['linkable']);
        $this->assertTrue($this->row($report, 'PO1001')['linkable']);

        $this->assertSame(0, $report['summary']['pending']);
        $this->assertSame(1, $report['summary']['erp_done']);
        $this->assertSame([], $report['pending']);
        $this->assertSame(['PO1003'], array_column($this->report(null, ['status' => 'erp_done'])['rows'], 'ordnumber'));
    }

    public function test_erp_lookup_flags_received_invoiced_closed_and_missing_pos(): void
    {
        config()->set('database.connections.pgsqlw', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge('pgsqlw');
        $erp = Schema::connection('pgsqlw');
        $erp->create('oe', function (Blueprint $t) {
            $t->id();
            $t->string('ordnumber');
            $t->date('transdate');
            $t->boolean('closed')->default(false);
            $t->boolean('cancelled')->default(false);
            $t->boolean('shipped_or_received')->default(false);
        });
        $erp->create('ap', function (Blueprint $t) {
            $t->id();
            $t->string('ordnumber');
        });
        DB::connection('pgsqlw')->table('oe')->insert([
            ['ordnumber' => 'PO_OPEN', 'transdate' => '2026-11-01', 'closed' => 0, 'cancelled' => 0, 'shipped_or_received' => 0],
            ['ordnumber' => 'PO_RECV', 'transdate' => '2026-07-01', 'closed' => 0, 'cancelled' => 0, 'shipped_or_received' => 1],
            ['ordnumber' => 'PO_CLOSED', 'transdate' => '2026-07-01', 'closed' => 1, 'cancelled' => 0, 'shipped_or_received' => 0],
        ]);
        DB::connection('pgsqlw')->table('ap')->insert([['ordnumber' => 'PO_RECV']]);

        $done = (new PoErpCompletionLookup())->doneInErp([
            ['ordnumber' => 'PO_OPEN', 'site' => 'wire'],
            ['ordnumber' => 'PO_RECV', 'site' => 'wire'],
            ['ordnumber' => 'PO_CLOSED', 'site' => 'Wire'],
            ['ordnumber' => 'PO_GONE', 'site' => null],
        ]);

        $this->assertSame([
            'wire::PO_RECV' => 'รับของแล้ว · ตั้งหนี้ (invoice) แล้ว',
            'wire::PO_CLOSED' => 'ปิดใน ERP',
            'wire::PO_GONE' => 'ไม่พบใน ERP',
        ], $done);
    }

    public function test_format_hours(): void
    {
        $this->assertSame('-', PoApprovalReportService::formatHours(null));
        $this->assertSame('5.5 ชม.', PoApprovalReportService::formatHours(5.5));
        $this->assertSame('1 วัน 9 ชม.', PoApprovalReportService::formatHours(33.0));
        $this->assertSame('2 วัน', PoApprovalReportService::formatHours(48.0));
    }
}

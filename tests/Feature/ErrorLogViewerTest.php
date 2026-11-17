<?php

namespace Tests\Feature;

use App\Models\Users\User;
use App\Services\OpsMonitoring\LogFileReader;
use App\Support\ModuleLogContext;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * ไม่แตะฐานข้อมูล: ใช้ User ที่ไม่ได้ save และเขียน log ลงโฟลเดอร์ชั่วคราว
 */
class ErrorLogViewerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'app.activity_log_enabled' => false, // กัน middleware เขียน activity log ลง DB จริง
            'services.error_alert.enabled' => false,
        ]);

        // APP_URL ของเครื่อง dev อาจมี sub-path (/menam-workflow/public) — ให้ test ยิงที่ root
        \Illuminate\Support\Facades\URL::forceRootUrl('http://localhost');

        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'errlog_test_' . uniqid();
        mkdir($this->dir . DIRECTORY_SEPARATOR . 'logs', 0777, true);
        $this->app->useStoragePath($this->dir);
        // path ของ channel ถูกคำนวณตอนโหลด config แล้ว ต้องชี้ใหม่ให้ลงโฟลเดอร์ชั่วคราว
        config(['logging.channels.client_errors.path' => $this->dir . '/logs/client-errors.log']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/logs/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/logs');
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function writeLog(string $name, string $content): void
    {
        file_put_contents($this->dir . '/logs/' . $name, $content);
    }

    private function user(int $id = 5): User
    {
        $user = new User();
        $user->id = $id;
        $user->name = 'Tester';

        return $user;
    }

    public function test_reader_parses_levels_context_and_stack_trace(): void
    {
        $this->writeLog('laravel-2026-11-16.log', implode("\n", [
            '[2026-11-16 08:00:00] production.INFO: started',
            '[2026-11-16 09:00:00] production.WARNING: FormVC: load ERP classinfo failed {"site":"WIRE","error":"timeout"}',
            '[2026-11-16 10:00:00] production.ERROR: Division by zero {"userId":7,"route":"formvc.index","exception":"[object] (DivisionByZeroError(code: 0): Division by zero at C:\\\\app\\\\X.php:10)',
            '[stacktrace]',
            '#0 C:\\app\\Y.php(20): foo()',
            '"} ',
        ]));

        $reader = new LogFileReader();
        $path = $reader->resolve('laravel-2026-11-16.log');
        $this->assertNotNull($path);

        $result = $reader->entries($path);
        $this->assertSame(['error' => 1, 'warning' => 1, 'info' => 1, 'debug' => 0], $result['counts']);

        [$error, $warning] = $result['entries'];
        $this->assertSame('ERROR', $error['level']);
        $this->assertSame('Division by zero', $error['message']);
        $this->assertSame('formvc.index', $error['context']['route']);
        $this->assertStringContainsString('DivisionByZeroError', $error['exception']);
        $this->assertStringContainsString('#0 C:\\app\\Y.php(20)', $error['detail']);

        $this->assertSame('WIRE', $warning['context']['site']);

        $onlyErrors = $reader->entries($path, ['level' => 'error']);
        $this->assertCount(1, $onlyErrors['entries']);

        $search = $reader->entries($path, ['q' => 'classinfo']);
        $this->assertCount(1, $search['entries']);
        $this->assertSame('WARNING', $search['entries'][0]['level']);
    }

    public function test_reader_rejects_files_outside_whitelist(): void
    {
        $this->writeLog('laravel.log', '[2026-11-16 10:00:00] local.ERROR: x');
        file_put_contents($this->dir . '/secret.txt', 'nope');

        $reader = new LogFileReader();

        $this->assertNotNull($reader->resolve('laravel.log'));
        $this->assertNull($reader->resolve('../secret.txt'));
        $this->assertNull($reader->resolve('..\\secret.txt'));
        $this->assertNull($reader->resolve('other.log'));
    }

    public function test_reader_reads_only_tail_of_large_file(): void
    {
        $lines = [];
        for ($i = 0; $i < 2000; $i++) {
            $lines[] = sprintf('[2026-11-16 10:%02d:%02d] local.ERROR: entry %d', intdiv($i, 60) % 60, $i % 60, $i);
        }
        $this->writeLog('laravel.log', implode("\n", $lines));

        $result = (new LogFileReader())->entries($this->dir . '/logs/laravel.log', [], 4096, 300);

        $this->assertTrue($result['truncated']);
        $this->assertSame('entry 1999', $result['entries'][0]['message']);
        $this->assertLessThan(2000, $result['counts']['error']);
    }

    public function test_error_log_page_requires_adminweb(): void
    {
        Gate::define('ADMINWEB', fn () => false);

        $this->actingAs($this->user())->get('/adminweb/error-logs')->assertForbidden();
    }

    public function test_guest_is_redirected_from_error_log_page(): void
    {
        $this->get('/adminweb/error-logs')->assertRedirect();
    }

    public function test_client_error_endpoint_writes_client_log_without_query_string(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->actingAs($this->user(9))
            ->postJson('/client-errors', [
                'type' => 'error',
                'message' => 'Uncaught TypeError: x is undefined',
                'source' => 'http://app.test/js/formdp/inquiry.js?v=123',
                'line' => 42,
                'column' => 7,
                'stack' => "TypeError: x is undefined\n    at foo (inquiry.js:42:7)",
                'page' => 'http://app.test/formdp/inquiry?token=secret',
            ])
            ->assertNoContent();

        $files = glob($this->dir . '/logs/client-errors-*.log');
        $this->assertCount(1, $files);
        $content = file_get_contents($files[0]);

        $this->assertStringContainsString('JS: Uncaught TypeError: x is undefined', $content);
        $this->assertStringContainsString('"userId":9', $content);
        $this->assertStringContainsString('at foo (inquiry.js:42:7)', $content);
        $this->assertStringNotContainsString('token=secret', $content);
        $this->assertStringNotContainsString('v=123', $content);

        // หน้า Error Log อ่านไฟล์นี้ได้ และแยก stack เป็นรายละเอียด
        $reader = new LogFileReader();
        $entry = $reader->entries($reader->resolve(basename($files[0])))['entries'][0];
        $this->assertSame('42', $entry['context']['line']);
        $this->assertStringContainsString('at foo', $entry['detail']);
    }

    public function test_client_error_endpoint_validates_and_requires_login(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->postJson('/client-errors', ['message' => 'x'])->assertUnauthorized();

        $this->actingAs($this->user())
            ->postJson('/client-errors', ['message' => '', 'type' => 'hack'])
            ->assertUnprocessable();
    }

    public function test_module_labels_from_route_command_and_message(): void
    {
        // 4 module เดิมต้องได้ค่าเดิม (ใช้ร่วมกับ audit log)
        $this->assertSame('DP', ModuleLogContext::labelForRouteName('dp.inquiry.index'));
        $this->assertSame('FC', ModuleLogContext::labelForRouteName('fc.index'));

        $this->assertSame('VC', ModuleLogContext::labelForRouteName('variable-cost.index'));
        $this->assertSame('WOS', ModuleLogContext::labelForRouteName('deadstock.dashboard'));
        $this->assertSame('ADMIN', ModuleLogContext::labelForRouteName('adminweb.error-logs.index'));
        $this->assertSame('PO', ModuleLogContext::labelForRouteName('po.index'));
        $this->assertNull(ModuleLogContext::labelForRouteName(null));

        $this->assertSame('WR', ModuleLogContext::labelForCommand('wr-deadstock:sync --snapshot=DAILY'));
        $this->assertSame('STOCK', ModuleLogContext::labelForCommand('stock-withdrawal:send-daily-alert'));
        $this->assertSame('SCHEDULER', ModuleLogContext::labelForCommand('schedule:run'));

        $this->assertSame('VC', ModuleLogContext::labelFromMessage('FormVC: load ERP classinfo failed'));
        $this->assertNull(ModuleLogContext::labelFromMessage('Something else'));

        // module อื่นที่ไม่ใช่ 4 ตัวหลัก ต้องไม่เปิด audit log ของ middleware
        $this->assertNull(ModuleLogContext::moduleForRouteName('variable-cost.index'));
    }

    public function test_reader_filters_and_counts_by_module(): void
    {
        $this->writeLog('laravel.log', implode("\n", [
            '[2026-11-17 08:00:00] production.ERROR: DP failed {"module":"DP","route":"dp.inquiry.index"}',
            '[2026-11-17 08:01:00] production.WARNING: FormVC: load ERP classinfo failed {"site":"WIRE","error":"timeout"}',
            '[2026-11-17 08:02:00] production.ERROR: VC query failed {"route":"variable-cost.index"}',
            '[2026-11-17 08:03:00] production.ERROR: sync failed {"command":"wr-deadstock:sync"}',
            '[2026-11-17 08:04:00] production.ERROR: unknown failure',
        ]));

        $reader = new LogFileReader();
        $path = $reader->resolve('laravel.log');

        $all = $reader->entries($path, ['level' => 'error']);
        $this->assertSame(['DP' => 1, 'VC' => 1, 'WR' => 1, LogFileReader::NO_MODULE => 1], $all['modules'] + []);
        $this->assertSame('DP', $all['entries'][3]['module']);
        $this->assertArrayNotHasKey('module', $all['entries'][3]['context']); // แสดงเป็น badge แยก

        $vc = $reader->entries($path, ['module' => 'vc']);
        $this->assertCount(2, $vc['entries']); // error + warning (จากข้อความ FormVC:)
        $this->assertSame(['error' => 1, 'warning' => 1, 'info' => 0, 'debug' => 0], $vc['counts']);

        $other = $reader->entries($path, ['module' => LogFileReader::NO_MODULE]);
        $this->assertSame('unknown failure', $other['entries'][0]['message']);
    }

    public function test_reader_falls_back_to_stack_trace_folder_for_old_logs(): void
    {
        $this->writeLog('laravel.log', implode("\n", [
            '[2026-11-10 08:00:00] production.ERROR: SQLSTATE[42S22] Invalid column {"userId":1,"exception":"[object] (PDOException(code: 42S22): x at C:\\\\xampp\\\\vendor\\\\laravel\\\\Connection.php:10)',
            '[stacktrace]',
            '#0 C:\\xampp\\vendor\\laravel\\Connection.php(20): foo()',
            '#1 C:\\xampp\\htdocs\\menam-workflow\\app\\Http\\Controllers\\FormDP\\DeliveryPlanController.php(88): bar()',
            '"} ',
        ]));

        $reader = new LogFileReader();
        $entry = $reader->entries($reader->resolve('laravel.log'))['entries'][0];

        $this->assertSame('DP', $entry['module']);
    }

    public function test_client_error_gets_module_from_page_route(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->actingAs($this->user())
            ->postJson('/client-errors', [
                'message' => 'Uncaught ReferenceError: chart is not defined',
                'page' => 'http://localhost/variable-cost?site=WIRE',
            ])
            ->assertNoContent();

        $reader = new LogFileReader();
        $file = basename(glob($this->dir . '/logs/client-errors-*.log')[0]);
        $entry = $reader->entries($reader->resolve($file))['entries'][0];

        $this->assertSame('VC', $entry['module']);
    }

    public function test_handler_context_contains_module(): void
    {
        $handler = new \App\Exceptions\Handler($this->app);
        $method = new \ReflectionMethod($handler, 'context');
        $method->setAccessible(true);

        $_SERVER['argv'] = ['artisan', 'wr-deadstock:sync', '--snapshot=DAILY'];
        $context = $method->invoke($handler);

        $this->assertSame('WR', $context['module']);
        $this->assertSame('wr-deadstock:sync --snapshot=DAILY', $context['command']);
    }
}

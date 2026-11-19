<?php

namespace Tests\Feature;

use App\Services\OpsMonitoring\ErrorAlertService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * ไม่แตะฐานข้อมูล และไม่ส่งข้อความไป Telegram จริง (ใช้ Http::fake ทั้งหมด)
 */
class ErrorAlertServiceTest extends TestCase
{
    private const TOKEN = '123456:TEST_PLACEHOLDER_TOKEN_ABCDEFGHIJ';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'services.error_alert.enabled' => true,
            'services.error_alert.bot_token' => self::TOKEN,
            'services.error_alert.chat_id' => '-1000000000',
            'services.error_alert.api_base' => 'https://telegram.test',
            'services.error_alert.throttle_minutes' => 10,
            'services.error_alert.max_per_window' => 20,
        ]);
        Cache::flush();

        // service ปิดตัวเองใน env testing — จำลองเป็น production ระหว่าง test
        $this->app['env'] = 'production';
        Http::fake(['telegram.test/*' => Http::response(['ok' => true])]);
    }

    public function test_disabled_config_sends_nothing(): void
    {
        config(['services.error_alert.enabled' => false]);

        app(ErrorAlertService::class)->notifyException(new RuntimeException('boom'));

        Http::assertNothingSent();
    }

    public function test_testing_environment_sends_nothing(): void
    {
        $this->app['env'] = 'testing';

        app(ErrorAlertService::class)->notifyException(new RuntimeException('boom'));

        Http::assertNothingSent();
    }

    public function test_sends_exception_with_context_and_masks_token(): void
    {
        $e = new RuntimeException('call failed https://api.telegram.org/bot' . self::TOKEN . '/x');

        app(ErrorAlertService::class)->notifyException($e, [
            'userId' => 7,
            'userName' => 'Tester',
            'route' => 'formvc.index',
            'method' => 'GET',
            'url' => '/formvc',
        ]);

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request) {
            $text = $request['text'];

            return str_contains($request->url(), '/sendMessage')
                && $request['chat_id'] === '-1000000000'
                && str_contains($text, 'RuntimeException')
                && str_contains($text, 'Route: formvc.index')
                && str_contains($text, 'User: Tester (#7)')
                && str_contains($text, 'tests/Feature/ErrorAlertServiceTest.php')
                && ! str_contains($text, self::TOKEN);
        });
    }

    public function test_same_error_is_throttled(): void
    {
        $service = app(ErrorAlertService::class);

        for ($i = 0; $i < 3; $i++) {
            $service->notifyException(new RuntimeException('same place')); // บรรทัดเดียวกัน = fingerprint เดียวกัน
        }

        Http::assertSentCount(1);
    }

    public function test_global_window_cap(): void
    {
        config(['services.error_alert.max_per_window' => 2]);
        $service = app(ErrorAlertService::class);

        $service->notifyScheduleFailure('cmd:a', 1);
        $service->notifyScheduleFailure('cmd:b', 1);
        $service->notifyScheduleFailure('cmd:c', 1);

        Http::assertSentCount(2);
    }

    public function test_schedule_failure_message(): void
    {
        app(ErrorAlertService::class)->notifyScheduleFailure('wr-deadstock:sync', 1);

        Http::assertSent(fn (HttpRequest $r) => str_contains($r['text'], 'Scheduled task failed')
            && str_contains($r['text'], 'Command: wr-deadstock:sync')
            && str_contains($r['text'], 'Exit code: 1'));
    }

    public function test_telegram_outage_does_not_throw(): void
    {
        Http::fake(['telegram.test/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('down')]);

        app(ErrorAlertService::class)->notifyException(new RuntimeException('boom'));

        $this->assertTrue(true);
    }

    public function test_report_helper_triggers_alert_through_handler(): void
    {
        report(new RuntimeException('reported from catch block'));

        Http::assertSent(fn (HttpRequest $r) => str_contains($r['text'], 'reported from catch block'));
    }
}

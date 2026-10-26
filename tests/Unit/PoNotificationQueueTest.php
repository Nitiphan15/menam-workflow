<?php

namespace Tests\Unit;

use App\Mail\PoClosedNotificationMail;
use App\Services\Po\PoNotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PoNotificationQueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlsrv_menam' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ], 'queue.po_mail_enabled' => true]);
        DB::purge('sqlsrv_menam');
        $migration = require base_path('database/migrations/2026_09_09_000001_create_po_mail_queue_tables.php');
        $migration->up();
        Mail::fake();
    }

    private function dispatch(): string
    {
        return app(PoNotificationDispatcher::class)->send('buyer@example.com', 'audit@example.com',
            new PoClosedNotificationMail('Buyer', ['ordnumber' => 'PO-TEST', 'show_url' => 'https://app.test/po/1']));
    }

    public function test_mail_is_persisted_without_smtp_then_delivered_by_worker(): void
    {
        $this->assertSame('queued', $this->dispatch());
        Mail::assertNothingSent();
        $this->assertSame(1, DB::connection('sqlsrv_menam')->table('po_mail_jobs')->count());

        $this->artisan('po:mail-work', ['--once' => true])->assertExitCode(0);
        $this->assertSame(0, DB::connection('sqlsrv_menam')->table('po_mail_jobs')->count());
        Mail::assertSent(PoClosedNotificationMail::class, fn ($mail) =>
            $mail->hasTo('buyer@example.com') && $mail->hasBcc('audit@example.com')
            && $mail->poItem['show_url'] === 'https://app.test/po/1');
    }

    public function test_queue_failure_falls_back_to_synchronous_delivery(): void
    {
        Queue::shouldReceive('connection')->once()->with('po-mail')->andThrow(new \RuntimeException('Queue unavailable'));
        $this->assertSame('sent', $this->dispatch());
        Mail::assertSent(PoClosedNotificationMail::class, 1);
    }

    public function test_feature_disabled_preserves_existing_delivery(): void
    {
        config(['queue.po_mail_enabled' => false]);
        $this->assertSame('sent', $this->dispatch());
        Mail::assertSent(PoClosedNotificationMail::class, 1);
        $this->assertSame(0, DB::connection('sqlsrv_menam')->table('po_mail_jobs')->count());
    }

    public function test_dispatcher_sends_one_mail_to_multiple_to_recipients(): void
    {
        config(['queue.po_mail_enabled' => false]);

        $poItem = [
            'ordnumber' => 'PO-TEST',
            'is_group_recipient' => true,
        ];

        app(PoNotificationDispatcher::class)->send(
            ['purchase1@example.com', 'purchase2@example.com'],
            'audit@example.com',
            new PoClosedNotificationMail('ทีม Purchase', $poItem)
        );

        Mail::assertSent(PoClosedNotificationMail::class, function ($mail) {
            return $mail->hasTo('purchase1@example.com')
                && $mail->hasTo('purchase2@example.com')
                && $mail->hasBcc('audit@example.com');
        });
        Mail::assertSent(PoClosedNotificationMail::class, 1);

        $html = view('emails.po.closed_notification', [
            'recipientName' => 'ทีม Purchase',
            'poItem' => $poItem,
        ])->render();
        $this->assertStringContainsString('เรียนทีม Purchase', $html);
        $this->assertStringNotContainsString('เรียนคุณ ทีม Purchase', $html);
    }

    public function test_exhausted_failure_is_stored_and_can_be_retried(): void
    {
        $this->dispatch();
        DB::connection('sqlsrv_menam')->table('po_mail_jobs')->update(['attempts' => 2]);
        Mail::swap(\Mockery::mock(\Illuminate\Contracts\Mail\Mailer::class));
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('Simulated SMTP failure'));
        $this->artisan('po:mail-work', ['--once' => true])->assertExitCode(0);
        $failed = DB::connection('sqlsrv_menam')->table('po_mail_failed_jobs')->first();
        $this->assertNotNull($failed);
        $this->assertSame(0, DB::connection('sqlsrv_menam')->table('po_mail_jobs')->count());

        Mail::fake();
        $this->artisan('po:mail-work', ['--once' => true, '--retry' => [$failed->uuid]])->assertExitCode(0);
        Mail::assertSent(PoClosedNotificationMail::class, 1);
        $this->assertSame(0, DB::connection('sqlsrv_menam')->table('po_mail_failed_jobs')->count());
    }

    public function test_first_smtp_failure_releases_job_with_backoff(): void
    {
        $this->dispatch();
        Mail::swap(\Mockery::mock(\Illuminate\Contracts\Mail\Mailer::class));
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('Temporary SMTP failure'));
        $before = time();
        $this->artisan('po:mail-work', ['--once' => true])->assertExitCode(0);
        $pending = DB::connection('sqlsrv_menam')->table('po_mail_jobs')->first();
        $this->assertNotNull($pending);
        $this->assertSame(1, (int) $pending->attempts);
        $this->assertGreaterThanOrEqual($before + 60, (int) $pending->available_at);
        $this->assertSame(0, DB::connection('sqlsrv_menam')->table('po_mail_failed_jobs')->count());
    }
}

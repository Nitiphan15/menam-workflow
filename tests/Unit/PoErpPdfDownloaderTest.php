<?php

namespace Tests\Unit;

use App\Services\Po\PoErpPdfDownloader;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PoErpPdfDownloaderTest extends TestCase
{
    private function download(): string
    {
        return app(PoErpPdfDownloader::class)->download('http://erp.test/report.php', 'test-session', ['po_id' => 1]);
    }

    public function test_pdf_is_returned_without_extra_requests(): void
    {
        Http::fake(['*' => Http::response('%PDF-1.4 test', 200)]);
        $this->assertSame('%PDF-1.4 test', $this->download());
        Http::assertSentCount(1);
    }

    public function test_unknown_html_preserves_session_and_is_not_retried(): void
    {
        session(['po_erp_phpsessid' => 'test-session']);
        Http::fake(['*' => Http::response('<html>Report unavailable</html>', 200)]);
        try {
            $this->download();
            $this->fail('Expected validation error');
        } catch (ValidationException $exception) {
            $this->assertSame('test-session', session('po_erp_phpsessid'));
            $this->assertFalse(session('po_erp_reconnect_required', false));
            Http::assertSentCount(1);
        }
    }

    public function test_login_page_clears_session_and_disables_shared_fallback(): void
    {
        session(['po_erp_phpsessid' => 'test-session']);
        Http::fake(['*' => Http::response('<form><input name="i_login"><input name="i_passwd"></form>', 200)]);
        try {
            $this->download();
            $this->fail('Expected validation error');
        } catch (ValidationException $exception) {
            $this->assertNull(session('po_erp_phpsessid'));
            $this->assertTrue(session('po_erp_reconnect_required'));
            Http::assertSentCount(1);
        }
    }

    public function test_temporary_erp_failure_retries_once_and_recovers(): void
    {
        Http::fake(['*' => Http::sequence()->push('Unavailable', 503)->push('%PDF-1.4 recovered', 200)]);
        $this->assertSame('%PDF-1.4 recovered', $this->download());
        Http::assertSentCount(2);
    }

    public function test_persistent_temporary_failure_stops_after_two_requests(): void
    {
        session(['po_erp_phpsessid' => 'test-session']);
        Http::fake(['*' => Http::response('Unavailable', 503)]);
        try {
            $this->download();
            $this->fail('Expected validation error');
        } catch (ValidationException $exception) {
            Http::assertSentCount(2);
            $this->assertSame('test-session', session('po_erp_phpsessid'));
        }
    }

    public function test_connection_failure_is_bounded_and_preserves_session(): void
    {
        session(['po_erp_phpsessid' => 'test-session']);
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new \Illuminate\Http\Client\ConnectionException('Simulated timeout');
        });
        try {
            $this->download();
            $this->fail('Expected validation error');
        } catch (ValidationException $exception) {
            $this->assertSame(2, $attempts);
            $this->assertSame('test-session', session('po_erp_phpsessid'));
        }
    }
}

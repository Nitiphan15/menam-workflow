<?php

namespace Tests\Unit;

use App\Models\Po\PoAttached;
use App\Models\Po\PoHeader;
use App\Services\Po\PoErpPdfDownloader;
use App\Services\Po\PoErpService;
use App\Services\Po\PoSubmitAttachmentService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class PoSubmitAttachmentServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_downloads_erp_pdf_and_keeps_the_next_submit_version(): void
    {
        Carbon::setTestNow('2026-11-18 15:00:00');
        Storage::fake('public');
        config([
            'services.erp.base_url' => 'http://erp.test/rp-forms.php',
            'services.erp.po_form_id_wire' => 60169,
        ]);

        $erp = Mockery::mock(PoErpService::class);
        $erp->shouldReceive('purchaseIdForPo')->once()->with('PO2026110019', 'wire')->andReturn(456);

        $downloader = Mockery::mock(PoErpPdfDownloader::class);
        $downloader->shouldReceive('download')->once()->with(
            'http://erp.test/rp-forms.php?form_type=purchase&form_id=60169&purchase_id=456',
            'erp-session',
            Mockery::on(fn (array $context) => $context['action'] === 'purchase_submit_attachment')
        )->andReturn("%PDF-1.4\ntest");

        $versionBuilder = Mockery::mock(Builder::class);
        $versionBuilder->shouldReceive('max')->once()->with('po_version')->andReturn(1);
        $relation = Mockery::mock(HasMany::class);
        $relation->shouldReceive('where')->once()->with('attachment_type', 'PO_SUBMIT')->andReturn($versionBuilder);

        $po = Mockery::mock(PoHeader::class)->makePartial();
        $po->id = 19;
        $po->site = 'wire';
        $po->ordnumber = 'PO2026110019';
        $po->shouldReceive('attachments')->once()->andReturn($relation);

        $created = new PoAttached();
        $builder = Mockery::mock(Builder::class);
        $builder->shouldReceive('create')->once()->with(Mockery::on(function (array $attributes): bool {
            return $attributes['file_name'] === 'PO2026110019_PO_v02.pdf'
                && $attributes['attachment_type'] === 'PO_SUBMIT'
                && $attributes['attachment_source'] === 'ERP'
                && $attributes['po_version'] === 2
                && $attributes['workflow_step_no'] === 1
                && $attributes['remark'] === 'Auto Attachment from Purchase Submit';
        }))->andReturn($created);
        $attachmentModel = Mockery::mock(PoAttached::class);
        $attachmentModel->shouldReceive('newQuery')->once()->andReturn($builder);

        $service = new PoSubmitAttachmentService($erp, $downloader, $attachmentModel);
        $this->assertSame($created, $service->attachFromErp($po, 'erp-session', 99));
        Storage::disk('public')->assertExists('po/2026/11/18/PO2026110019_PO_v02.pdf');
    }

    public function test_first_version_has_no_suffix_and_revisions_get_one(): void
    {
        $this->assertSame('PO2026110229_PO.pdf', PoSubmitAttachmentService::fileNameFor('PO2026110229', 1));
        $this->assertSame('PO2026110229_PO_v02.pdf', PoSubmitAttachmentService::fileNameFor('PO2026110229', 2));
        $this->assertSame('PO2026110229_PO_v10.pdf', PoSubmitAttachmentService::fileNameFor('PO2026110229', 10));
    }
}

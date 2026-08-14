<?php

namespace Tests\Unit;

use App\Models\Po\PoAttached;
use App\Models\Po\PoHeader;
use App\Services\Po\PoAttachmentService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class PoAttachmentServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_appends_a_new_attachment_after_existing_rows(): void
    {
        Carbon::setTestNow('2026-07-29 10:30:00');
        Storage::fake('public');
        Storage::disk('public')->put('po/2026/07/28/PO_123_01.pdf', 'existing attachment');

        $relation = Mockery::mock(HasMany::class);
        $relation->shouldReceive('count')->once()->andReturn(1);

        $po = Mockery::mock(PoHeader::class)->makePartial();
        $po->id = 42;
        $po->ordnumber = 'PO/123';
        $po->shouldReceive('attachments')->once()->andReturn($relation);

        $builder = Mockery::mock(Builder::class);
        $builder->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function (array $attributes): bool {
                return $attributes['po_header_id'] === 42
                    && $attributes['file_name'] === 'PO_123_02.pdf'
                    && $attributes['file_path'] === 'po/2026/07/29/PO_123_02.pdf'
                    && $attributes['remark'] === 'Approved quotation'
                    && $attributes['created_by'] === 99;
            }))
            ->andReturn(new PoAttached());

        $attachmentModel = Mockery::mock(PoAttached::class);
        $attachmentModel->shouldReceive('newQuery')->once()->andReturn($builder);

        $service = new PoAttachmentService($attachmentModel);
        $appended = $service->append(
            $po,
            [UploadedFile::fake()->create('quotation.pdf', 10, 'application/pdf')],
            ['Approved quotation'],
            99,
        );

        $this->assertSame(1, $appended);
        Storage::disk('public')->assertExists('po/2026/07/28/PO_123_01.pdf');
        Storage::disk('public')->assertExists('po/2026/07/29/PO_123_02.pdf');
    }

    public function test_manager_approval_attachment_keeps_original_name_after_po_prefix(): void
    {
        Carbon::setTestNow('2026-08-14 10:30:00');
        Storage::fake('public');
        Storage::disk('public')->put('po/2026/08/14/PO2026080053-quotation.pdf', 'existing attachment');

        $relation = Mockery::mock(HasMany::class);
        $relation->shouldReceive('count')->once()->andReturn(1);

        $po = Mockery::mock(PoHeader::class)->makePartial();
        $po->id = 53;
        $po->ordnumber = 'PO2026080053';
        $po->shouldReceive('attachments')->once()->andReturn($relation);

        $builder = Mockery::mock(Builder::class);
        $builder->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function (array $attributes): bool {
                return $attributes['po_header_id'] === 53
                    && $attributes['file_name'] === 'PO2026080053-quotation-2.pdf'
                    && $attributes['file_path'] === 'po/2026/08/14/PO2026080053-quotation-2.pdf'
                    && $attributes['created_by'] === 88;
            }))
            ->andReturn(new PoAttached());

        $attachmentModel = Mockery::mock(PoAttached::class);
        $attachmentModel->shouldReceive('newQuery')->once()->andReturn($builder);

        $service = new PoAttachmentService($attachmentModel);
        $appended = $service->append(
            $po,
            [UploadedFile::fake()->create('quotation.pdf', 10, 'application/pdf')],
            [],
            88,
            true,
        );

        $this->assertSame(1, $appended);
        Storage::disk('public')->assertExists('po/2026/08/14/PO2026080053-quotation.pdf');
        Storage::disk('public')->assertExists('po/2026/08/14/PO2026080053-quotation-2.pdf');
    }
}

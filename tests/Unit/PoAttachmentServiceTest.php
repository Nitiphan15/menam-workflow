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
    public function test_po_original_is_displayed_first_and_user_uploads_follow_in_original_order(): void
    {
        $supportingOne = (new PoAttached())->forceFill([
            'id' => 10,
            'attachment_type' => 'SUPPORTING',
            'file_name' => 'PO1_01.pdf',
        ]);
        $poOriginal = (new PoAttached())->forceFill([
            'id' => 12,
            'attachment_type' => 'PO_SUBMIT',
            'po_version' => 1,
            'file_name' => 'PO1_PO.pdf',
        ]);
        $supportingTwo = (new PoAttached())->forceFill([
            'id' => 11,
            'attachment_type' => 'SUPPORTING',
            'file_name' => 'quotation.pdf',
        ]);

        $ordered = PoAttachmentService::orderForDisplay(collect([
            $supportingOne,
            $poOriginal,
            $supportingTwo,
        ]));

        $this->assertSame(
            ['PO1_PO.pdf', 'PO1_01.pdf', 'quotation.pdf'],
            $ordered->pluck('file_name')->all(),
        );
    }

    public function test_newest_po_version_is_the_first_displayed_po(): void
    {
        $v1 = (new PoAttached())->forceFill(['id' => 20, 'attachment_type' => 'PO_SUBMIT', 'po_version' => 1]);
        $v2 = (new PoAttached())->forceFill(['id' => 30, 'attachment_type' => 'PO_SUBMIT', 'po_version' => 2]);

        $this->assertSame(
            [30, 20],
            PoAttachmentService::orderForDisplay(collect([$v1, $v2]))->pluck('id')->all(),
        );
    }

    public function test_display_names_are_renumbered_from_po_original(): void
    {
        $po = (new PoHeader())->forceFill(['ordnumber' => 'PO2026110239']);
        $poOriginal = (new PoAttached())->forceFill(['file_name' => 'PO2026110239_PO.pdf', 'file_ext' => 'pdf']);
        $userUpload = (new PoAttached())->forceFill(['file_name' => 'PO2026110239_01.pdf', 'file_ext' => 'pdf']);

        $this->assertSame('PO2026110239_01.pdf', PoAttachmentService::displayFileName($po, $poOriginal, 1));
        $this->assertSame('PO2026110239_02.pdf', PoAttachmentService::displayFileName($po, $userUpload, 2));
    }

    public function test_display_numbers_restart_at_two_for_each_upload_round(): void
    {
        $po = (new PoAttached())->forceFill(['id' => 1, 'attachment_type' => 'PO_SUBMIT']);
        $roundOne = (new PoAttached())->forceFill(['id' => 2, 'attachment_type' => 'SUPPORTING', 'remark' => 'อัปโหลดรอบที่ 1']);
        $roundTwoFirst = (new PoAttached())->forceFill(['id' => 3, 'attachment_type' => 'SUPPORTING', 'remark' => 'อัปโหลดรอบที่ 2']);
        $roundTwoSecond = (new PoAttached())->forceFill(['id' => 4, 'attachment_type' => 'SUPPORTING', 'remark' => 'อัปโหลดรอบที่ 2 — PR']);

        $this->assertSame(
            [1 => 1, 2 => 2, 3 => 2, 4 => 3],
            PoAttachmentService::displayPositions(collect([$po, $roundOne, $roundTwoFirst, $roundTwoSecond]))->all(),
        );
    }

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
        $relation->shouldReceive('get')->once()->with(['attachment_type', 'workflow_step_no', 'remark'])->andReturn(collect([
            (new PoAttached())->forceFill([
                'attachment_type' => 'SUPPORTING',
                'workflow_step_no' => 1,
                'remark' => 'อัปโหลดรอบที่ 1',
            ]),
        ]));

        $po = Mockery::mock(PoHeader::class)->makePartial();
        $po->id = 42;
        $po->ordnumber = 'PO/123';
        $po->shouldReceive('attachments')->twice()->andReturn($relation);

        $builder = Mockery::mock(Builder::class);
        $builder->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function (array $attributes): bool {
                return $attributes['po_header_id'] === 42
                    && $attributes['file_name'] === 'PO_123_02.pdf'
                    && $attributes['file_path'] === 'po/2026/07/29/PO_123_02.pdf'
                    && $attributes['remark'] === 'อัปโหลดรอบที่ 2 — Approved quotation'
                    && $attributes['workflow_step_no'] === 1
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
            workflowStepNo: 1,
        );

        $this->assertSame(1, $appended);
        Storage::disk('public')->assertExists('po/2026/07/28/PO_123_01.pdf');
        Storage::disk('public')->assertExists('po/2026/07/29/PO_123_02.pdf');
    }

    public function test_all_files_in_one_request_share_the_same_upload_round(): void
    {
        Carbon::setTestNow('2026-11-25 17:20:00');
        Storage::fake('public');

        $relation = Mockery::mock(HasMany::class);
        $relation->shouldReceive('count')->once()->andReturn(2);
        $relation->shouldReceive('get')->once()->with(['attachment_type', 'workflow_step_no', 'remark'])->andReturn(collect([
            (new PoAttached())->forceFill([
                'attachment_type' => 'SUPPORTING',
                'workflow_step_no' => 1,
                'remark' => 'อัปโหลดรอบที่ 2',
            ]),
        ]));

        $po = Mockery::mock(PoHeader::class)->makePartial();
        $po->id = 90;
        $po->ordnumber = 'PO2026110240';
        $po->shouldReceive('attachments')->twice()->andReturn($relation);

        $builder = Mockery::mock(Builder::class);
        $builder->shouldReceive('create')->twice()->with(Mockery::on(
            fn (array $attributes): bool => str_starts_with((string) $attributes['remark'], 'อัปโหลดรอบที่ 3')
        ))->andReturn(new PoAttached());

        $attachmentModel = Mockery::mock(PoAttached::class);
        $attachmentModel->shouldReceive('newQuery')->twice()->andReturn($builder);

        $appended = (new PoAttachmentService($attachmentModel))->append(
            $po,
            [
                UploadedFile::fake()->create('quotation.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('pr.pdf', 10, 'application/pdf'),
            ],
            ['', 'เอกสาร PR'],
            69,
            workflowStepNo: 1,
        );

        $this->assertSame(2, $appended);
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
                    && $attributes['workflow_step_no'] === 3
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
            keepOriginalName: true,
            workflowStepNo: 3,
        );

        $this->assertSame(1, $appended);
        Storage::disk('public')->assertExists('po/2026/08/14/PO2026080053-quotation.pdf');
        Storage::disk('public')->assertExists('po/2026/08/14/PO2026080053-quotation-2.pdf');
    }

    public function test_manual_submit_po_is_typed_and_gets_next_version(): void
    {
        Carbon::setTestNow('2026-11-18 14:20:00');
        Storage::fake('public');

        $versionBuilder = Mockery::mock(Builder::class);
        $versionBuilder->shouldReceive('max')->once()->with('po_version')->andReturn(2);

        $relation = Mockery::mock(HasMany::class);
        $relation->shouldReceive('count')->once()->andReturn(3);
        $relation->shouldReceive('where')->once()->with('attachment_type', 'PO_SUBMIT')->andReturn($versionBuilder);

        $po = Mockery::mock(PoHeader::class)->makePartial();
        $po->id = 77;
        $po->ordnumber = 'PO2026110010';
        $po->shouldReceive('attachments')->twice()->andReturn($relation);

        $builder = Mockery::mock(Builder::class);
        $builder->shouldReceive('create')->once()->with(Mockery::on(function (array $attributes): bool {
            return $attributes['attachment_type'] === 'PO_SUBMIT'
                && $attributes['attachment_source'] === 'MANUAL'
                && $attributes['po_version'] === 3
                && $attributes['workflow_step_no'] === 1;
        }))->andReturn(new PoAttached());

        $attachmentModel = Mockery::mock(PoAttached::class);
        $attachmentModel->shouldReceive('newQuery')->once()->andReturn($builder);

        $service = new PoAttachmentService($attachmentModel);
        $appended = $service->append(
            $po,
            [UploadedFile::fake()->create('manual-po.pdf', 10, 'application/pdf')],
            [],
            99,
            workflowStepNo: 1,
            attachmentType: 'PO_SUBMIT',
        );

        $this->assertSame(1, $appended);
        Storage::disk('public')->assertExists('po/2026/11/18/PO2026110010_04.pdf');
    }
}

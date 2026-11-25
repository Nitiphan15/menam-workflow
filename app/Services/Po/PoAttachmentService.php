<?php

namespace App\Services\Po;

use App\Models\Po\PoAttached;
use App\Models\Po\PoHeader;
use Illuminate\Support\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PoAttachmentService
{
    public function __construct(private readonly PoAttached $attachmentModel) {}

    /**
     * Put ERP/manual PO originals first, newest PO version first, then keep user uploads in upload order.
     *
     * @param  Collection<int, PoAttached>  $attachments
     * @return Collection<int, PoAttached>
     */
    public static function orderForDisplay(Collection $attachments): Collection
    {
        return $attachments
            ->sortBy(function (PoAttached $attachment): string {
                $isPo = strtoupper((string) ($attachment->attachment_type ?? '')) === 'PO_SUBMIT';

                return $isPo
                    ? sprintf('0-%010d-%020d', PHP_INT_MAX - (int) ($attachment->po_version ?? 0), (int) $attachment->id)
                    : sprintf('1-%020d', (int) $attachment->id);
            })
            ->values();
    }

    public static function displayFileName(PoHeader $po, PoAttached $attachment, int $position): string
    {
        $extension = (string) ($attachment->file_ext
            ?: pathinfo((string) $attachment->file_name, PATHINFO_EXTENSION));

        return sprintf(
            '%s_%02d%s',
            $po->ordnumber,
            $position,
            $extension !== '' ? ".{$extension}" : '',
        );
    }

    /**
     * PO is always 01. User-uploaded files start at 02 again for each upload round;
     * the remark distinguishes rounds instead of changing the displayed suffix.
     *
     * @param  Collection<int, PoAttached>  $attachments Already in display order
     * @return Collection<int|string, int>
     */
    public static function displayPositions(Collection $attachments): Collection
    {
        $nextByRound = [];

        return $attachments->mapWithKeys(function (PoAttached $attachment) use (&$nextByRound): array {
            if (strtoupper((string) ($attachment->attachment_type ?? '')) === 'PO_SUBMIT') {
                return [$attachment->getKey() => 1];
            }

            $roundKey = preg_match('/อัปโหลดรอบที่\s*(\d+)/u', (string) $attachment->remark, $matches)
                ? 'round-' . $matches[1]
                : 'legacy';
            $position = $nextByRound[$roundKey] ?? 2;
            $nextByRound[$roundKey] = $position + 1;

            return [$attachment->getKey() => $position];
        });
    }

    /**
     * Append new files to a PO without changing any existing attachment rows.
     *
     * @param  array<int, UploadedFile|null>  $files
     * @param  array<int, string|null>  $remarks
     */
    public function append(
        PoHeader $po,
        array $files,
        array $remarks,
        int $userId,
        bool $keepOriginalName = false,
        ?int $workflowStepNo = null,
        string $attachmentType = 'SUPPORTING',
        string $attachmentSource = 'MANUAL',
    ): int
    {
        $today = now();
        $directory = "po/{$today->year}/{$today->format('m')}/{$today->format('d')}";
        $safePoNumber = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $po->ordnumber);
        $sequence = $po->attachments()->count() + 1;
        $appended = 0;
        $poVersion = $attachmentType === 'PO_SUBMIT'
            ? (int) $po->attachments()->where('attachment_type', 'PO_SUBMIT')->max('po_version') + 1
            : null;
        $uploadRound = !$keepOriginalName && $workflowStepNo === 1 && $attachmentType === 'SUPPORTING'
            ? self::nextUploadRound($po->attachments()->get(['attachment_type', 'workflow_step_no', 'remark']))
            : null;

        foreach ($files as $index => $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                continue;
            }

            if ($keepOriginalName) {
                $fileName = $this->managerApprovalFileName($safePoNumber, $file, $directory);
            } else {
                $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'file');
                $extension = preg_replace('/[^a-z0-9]+/', '', $extension) ?: 'file';

                do {
                    $fileName = sprintf('%s_%02d.%s', $safePoNumber, $sequence, $extension);
                    $candidate = "{$directory}/{$fileName}";
                    $sequence++;
                } while (Storage::disk('public')->exists($candidate));
            }

            $stored = $file->storeAs($directory, $fileName, 'public');
            $remark = trim((string) ($remarks[$index] ?? ''));
            if ($uploadRound !== null) {
                $roundLabel = "อัปโหลดรอบที่ {$uploadRound}";
                $remark = $remark !== '' ? "{$roundLabel} — {$remark}" : $roundLabel;
            }

            try {
                $this->attachmentModel->newQuery()->create([
                    'po_header_id' => $po->id,
                    'file_name' => $fileName,
                    'file_path' => $stored,
                    'file_ext' => $file->getClientOriginalExtension(),
                    'mime_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                    'remark' => $remark !== '' ? $remark : null,
                    'workflow_step_no' => $workflowStepNo,
                    'attachment_type' => $attachmentType,
                    'attachment_source' => $attachmentSource,
                    'po_version' => $poVersion,
                    'created_at' => now(),
                    'created_by' => $userId,
                ]);
            } catch (Throwable $exception) {
                Storage::disk('public')->delete($stored);
                throw $exception;
            }

            $appended++;
            if ($poVersion !== null) {
                $poVersion++;
            }
        }

        return $appended;
    }

    /** @param Collection<int, PoAttached> $attachments */
    public static function nextUploadRound(Collection $attachments): int
    {
        $rounds = $attachments
            ->filter(fn (PoAttached $attachment) => (int) ($attachment->workflow_step_no ?? 0) === 1
                && strtoupper((string) ($attachment->attachment_type ?? 'SUPPORTING')) !== 'PO_SUBMIT')
            ->map(function (PoAttached $attachment): int {
                return preg_match('/อัปโหลดรอบที่\s*(\d+)/u', (string) $attachment->remark, $matches)
                    ? (int) $matches[1]
                    : 0;
            });

        $highestRound = (int) ($rounds->max() ?? 0);
        if ($highestRound > 0) {
            return $highestRound + 1;
        }

        return $rounds->isNotEmpty() ? 2 : 1;
    }

    private function managerApprovalFileName(string $safePoNumber, UploadedFile $file, string $directory): string
    {
        $originalName = trim(str_replace(['/', '\\'], '_', $file->getClientOriginalName()));
        $originalName = preg_replace('/[\x00-\x1F\x7F]+/u', '', $originalName) ?: 'attachment';
        $baseName = pathinfo($originalName, PATHINFO_FILENAME) ?: 'attachment';
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $suffix = $extension !== '' ? ".{$extension}" : '';
        $fileName = "{$safePoNumber}-{$baseName}{$suffix}";
        $duplicate = 2;

        while (Storage::disk('public')->exists("{$directory}/{$fileName}")) {
            $fileName = "{$safePoNumber}-{$baseName}-{$duplicate}{$suffix}";
            $duplicate++;
        }

        return $fileName;
    }
}

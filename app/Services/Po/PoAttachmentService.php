<?php

namespace App\Services\Po;

use App\Models\Po\PoAttached;
use App\Models\Po\PoHeader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PoAttachmentService
{
    public function __construct(private readonly PoAttached $attachmentModel) {}

    /**
     * Append new files to a PO without changing any existing attachment rows.
     *
     * @param  array<int, UploadedFile|null>  $files
     * @param  array<int, string|null>  $remarks
     */
    public function append(PoHeader $po, array $files, array $remarks, int $userId): int
    {
        $today = now();
        $directory = "po/{$today->year}/{$today->format('m')}/{$today->format('d')}";
        $safePoNumber = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $po->ordnumber);
        $sequence = $po->attachments()->count() + 1;
        $appended = 0;

        foreach ($files as $index => $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                continue;
            }

            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'file');
            $extension = preg_replace('/[^a-z0-9]+/', '', $extension) ?: 'file';
            $candidate = '';
            $fileName = '';

            do {
                $fileName = sprintf('%s_%02d.%s', $safePoNumber, $sequence, $extension);
                $candidate = "{$directory}/{$fileName}";
                $sequence++;
            } while (Storage::disk('public')->exists($candidate));

            $stored = $file->storeAs($directory, $fileName, 'public');

            try {
                $this->attachmentModel->newQuery()->create([
                    'po_header_id' => $po->id,
                    'file_name' => $fileName,
                    'file_path' => $stored,
                    'file_ext' => $file->getClientOriginalExtension(),
                    'mime_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                    'remark' => $remarks[$index] ?? null,
                    'created_at' => now(),
                    'created_by' => $userId,
                ]);
            } catch (Throwable $exception) {
                Storage::disk('public')->delete($stored);
                throw $exception;
            }

            $appended++;
        }

        return $appended;
    }
}

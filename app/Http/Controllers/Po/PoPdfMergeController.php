<?php

namespace App\Http\Controllers\Po;

use App\Http\Controllers\Controller;
use App\Services\Po\PdfMergeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PoPdfMergeController extends Controller
{
    public function __construct(private readonly PdfMergeService $pdfMergeService)
    {
    }

    public function index()
    {
        return view('po.pdf-merge');
    }

    public function merge(Request $request)
    {
        $data = $request->validate([
            'pdf_files' => ['required', 'array', 'min:2', 'max:20'],
            'pdf_files.*' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'output_name' => ['nullable', 'string', 'max:120'],
        ], [
            'pdf_files.required' => 'กรุณาเลือกไฟล์ PDF ที่ต้องการรวม',
            'pdf_files.min' => 'กรุณาเลือกไฟล์ PDF อย่างน้อย 2 ไฟล์',
            'pdf_files.max' => 'รวมไฟล์ได้ครั้งละไม่เกิน 20 ไฟล์',
            'pdf_files.*.mimes' => 'รองรับเฉพาะไฟล์ PDF เท่านั้น',
            'pdf_files.*.max' => 'ไฟล์ PDF แต่ละไฟล์ต้องมีขนาดไม่เกิน 20 MB',
        ]);

        $tempDirectory = storage_path('app/tmp/po-pdf-merge');
        File::ensureDirectoryExists($tempDirectory);
        $targetPath = $tempDirectory . DIRECTORY_SEPARATOR . Str::uuid() . '.pdf';
        $files = collect($data['pdf_files'])->values();

        try {
            $this->pdfMergeService->merge(
                $files->map(fn ($file) => $file->getRealPath())->all(),
                $targetPath,
                $files->map(fn ($file) => $file->getClientOriginalName())->all()
            );
        } catch (RuntimeException $exception) {
            File::delete($targetPath);
            Log::warning('PO PDF merge failed', [
                'files' => $files->map(fn ($file) => $file->getClientOriginalName())->all(),
                'error' => $exception->getMessage(),
                'previous' => $exception->getPrevious()?->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'pdf_files' => $exception->getMessage(),
            ]);
        }

        return response()
            ->download($targetPath, $this->downloadFileName($data['output_name'] ?? null), [
                'Content-Type' => 'application/pdf',
            ])
            ->deleteFileAfterSend(true);
    }

    private function downloadFileName(?string $requestedName): string
    {
        $name = preg_replace('/\.pdf$/i', '', trim((string) $requestedName));
        $name = preg_replace('/[\\\/:*?"<>|]+/u', '_', (string) $name);
        $name = trim((string) $name, " .\t\n\r\0\x0B");

        return ($name !== '' ? $name : 'merged-purchase-orders') . '.pdf';
    }
}

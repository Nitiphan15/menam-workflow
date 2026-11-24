<?php

namespace App\Http\Controllers\Po;

use App\Http\Controllers\Controller;
use App\Models\Po\PoHeader;
use App\Services\Po\PdfMergeService;
use App\Services\Po\PoErpService;
use App\Services\Po\PoPdfBundleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PoPdfMergeController extends Controller
{
    public function __construct(
        private readonly PdfMergeService $pdfMergeService,
        private readonly PoPdfBundleService $bundleService,
    ) {}

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
                $files->map(fn($file) => $file->getRealPath())->all(),
                $targetPath,
                $files->map(fn($file) => $file->getClientOriginalName())->all()
            );
        } catch (RuntimeException $exception) {
            File::delete($targetPath);
            Log::warning('PO PDF merge failed', [
                'files' => $files->map(fn($file) => $file->getClientOriginalName())->all(),
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

    /** หน้า "รวมไฟล์ PDF" แบบใส่เลข PO (POPUR) — same access as the PO list. */
    public function bundleByNumber(Request $request)
    {
        $data = $request->validate([
            'po_number' => ['required', 'string', 'max:30', 'regex:/^POR?[0-9]+$/i'],
            'site' => ['nullable', 'in:wire,plus'],
        ], [
            'po_number.required' => 'กรุณาใส่เลข PO',
            'po_number.regex' => 'รูปแบบเลข PO ไม่ถูกต้อง เช่น PO2026110229',
        ]);

        $matches = PoHeader::query()
            ->where('ordnumber', strtoupper(trim($data['po_number'])))
            ->limit(5)
            ->get();
        if (!empty($data['site'])) {
            // Filter in PHP: the site accessor normalizes legacy values (and works without the column).
            $matches = $matches->filter(fn (PoHeader $po) => $po->site === $data['site'])->values();
        }

        if ($matches->isEmpty()) {
            throw ValidationException::withMessages(['po_number' => 'ไม่พบ PO นี้ใน PO Online']);
        }
        if ($matches->count() > 1) {
            throw ValidationException::withMessages(['po_number' => 'พบเลข PO นี้ทั้ง Wire และ Plus กรุณาเลือก Site']);
        }

        return $this->bundleResponse($matches->first());
    }

    /** ปุ่ม "ดาวน์โหลดชุด PO" บนหน้า PO (PO / POPUR) — same access as opening each attachment. */
    public function bundle($id)
    {
        return $this->bundleResponse(PoHeader::query()->findOrFail($id));
    }

    /** ปุ่มเดียวกันบนหน้า "ติดตาม PO แผนกของฉัน" (POV) — own department only. */
    public function departmentTrackingBundle($id)
    {
        $po = PoHeader::query()->findOrFail($id);
        $user = auth()->user();

        abort_unless(
            PoErpService::canDepartmentViewerAccess(
                $po->f1,
                (int) ($user?->department_id ?? 0),
                (string) ($user?->position ?? ''),
            ),
            403,
            'You are not authorized to view PO documents from another department',
        );

        return $this->bundleResponse($po);
    }

    private function bundleResponse(PoHeader $po)
    {
        try {
            $bundle = $this->bundleService->build($po);
        } catch (RuntimeException $exception) {
            Log::warning('PO set PDF failed', [
                'po_id' => $po->id,
                'error' => $exception->getMessage(),
                'previous' => $exception->getPrevious()?->getMessage(),
            ]);

            if (request()->expectsJson()) {
                throw ValidationException::withMessages(['po_number' => $exception->getMessage()]);
            }

            return back()->with('error', $exception->getMessage());
        }

        $headers = ['Content-Type' => 'application/pdf'];
        if (!empty($bundle['skipped'])) {
            // Shown by the merge page; rawurlencode keeps Thai file names header-safe.
            $headers['X-Po-Set-Skipped'] = rawurlencode(implode(', ', $bundle['skipped']));
        }

        return response()
            ->download($bundle['path'], $bundle['file_name'], $headers)
            ->deleteFileAfterSend(true);
    }

    private function downloadFileName(?string $requestedName): string
    {
        $name = preg_replace('/\.pdf$/i', '', trim((string) $requestedName));
        $name = preg_replace('~[\\\\/:*?"<>|]+~u', '_', (string) $name);
        $name = trim((string) $name, " .\t\n\r\0\x0B");

        return ($name !== '' ? $name : 'merged-purchase-orders') . '.pdf';
    }
}

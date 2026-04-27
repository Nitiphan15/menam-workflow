<?php

namespace App\Http\Controllers\Po;

use App\Http\Controllers\Controller;
use App\Models\Po\PoHeader;
use App\Services\Po\PoErpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;

class PoExportController extends Controller
{
    public function __construct(private readonly PoErpService $erpService)
    {
    }

    public function list(Request $request): StreamedResponse
    {
        $rows = $this->erpService->listOpenPos(
            department: $request->query('department') ?: null,
            search: $request->query('search') ?: null,
            dateFrom: $request->query('date_from') ?: null,
            dateTo: $request->query('date_to') ?: null,
            status: $request->query('status') ?: null,
            source: $request->query('source') ?: null,
        );

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Source', 'Department Group', 'Department', 'PO No', 'Invoice No', 'PO Date', 'Qty', 'Status', 'Attached']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->source_label,
                    $row->department_group,
                    $row->department,
                    $row->ordnumber,
                    $row->invnumber,
                    $row->transdate,
                    $row->qty,
                    $row->status_code,
                    $row->has_attachment ? 'YES' : 'NO',
                ]);
            }

            fclose($out);
        }, 'po-list.csv');
    }

    public function print($id)
    {
        $po = PoHeader::query()->with(['attachments', 'workflow'])->findOrFail($id);
        $detailRows = $this->erpService->getDetailRows($po->ordnumber, $po->site);
        $headerRow = $detailRows->first();
        $signatures = $this->erpService->printWorkflowSignatures($po->workflow_id);

        $html = view('po.print', compact('po', 'detailRows', 'headerRow', 'signatures'))->render();

        return $this->downloadPdfFromHtml($html, "Purchase Order {$po->ordnumber}.pdf");
    }

    public function printDepartment(Request $request)
    {
        $department = trim((string) $request->query('department'));
        abort_if($department === '', 422, 'department is required');

        $rows = $this->erpService->listOpenPos(
            department: $department,
            search: $request->query('search') ?: null,
            dateFrom: $request->query('date_from') ?: null,
            dateTo: $request->query('date_to') ?: null,
            status: $request->query('status') ?: null,
            source: $request->query('source') ?: null,
        );

        $documents = $rows->map(function ($row) {
            $po = $row->po_header_id
                ? PoHeader::query()->with(['attachments', 'workflow'])->find($row->po_header_id)
                : null;

            if (!$po) {
                $po = $this->erpService->syncHeaderFromErp($row->ordnumber, auth()->id(), $row->site);
            }

            $detailRows = $this->erpService->getDetailRows($po->ordnumber, $po->site);

            return [
                'po' => $po,
                'detailRows' => $detailRows,
                'headerRow' => $detailRows->first(),
                'signatures' => $this->erpService->printWorkflowSignatures($po->workflow_id),
            ];
        })->values();

        abort_if($documents->isEmpty(), 404, 'No PO found for this department group');

        $html = view('po.print-batch', [
            'department' => $department,
            'documents' => $documents,
        ])->render();

        return $this->downloadPdfFromHtml($html, "Purchase Order {$department}.pdf");
    }

    private function downloadPdfFromHtml(string $html, string $fileName)
    {
        $tempDir = storage_path('app/tmp/po-pdf');
        File::ensureDirectoryExists($tempDir);

        $token = (string) Str::uuid();
        $htmlPath = "{$tempDir}/{$token}.html";
        $pdfPath = "{$tempDir}/{$token}.pdf";

        File::put($htmlPath, $html);

        $process = new Process([
            $this->chromePath(),
            '--headless=new',
            '--disable-gpu',
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--no-pdf-header-footer',
            "--print-to-pdf={$pdfPath}",
            'file:///' . str_replace('\\', '/', $htmlPath),
        ]);
        $process->setTimeout(60);
        $process->run();

        File::delete($htmlPath);

        abort_if(!$process->isSuccessful() || !File::exists($pdfPath), 500, 'Cannot generate PO PDF');

        return response()
            ->download($pdfPath, $this->safePdfFileName($fileName), ['Content-Type' => 'application/pdf'])
            ->deleteFileAfterSend(true);
    }

    private function chromePath(): string
    {
        foreach ([
            'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
            'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
        ] as $path) {
            if (File::exists($path)) {
                return $path;
            }
        }

        abort(500, 'Chrome or Edge is required to generate PO PDF');
    }

    private function safePdfFileName(string $fileName): string
    {
        $fileName = preg_replace('/[\\\\\\/\\:\\*\\?\\"\\<\\>\\|]+/', '_', $fileName);

        return trim((string) $fileName) !== '' ? $fileName : 'purchase-order.pdf';
    }
}

<?php

namespace App\Http\Controllers\Po;

use App\Http\Controllers\Controller;
use App\Models\Po\PoHeader;
use App\Services\Po\PoErpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function saveErpSession(Request $request)
    {
        $data = $request->validate([
            'erp_phpsessid' => ['required', 'string', 'max:500'],
        ]);

        $sessionId = $this->normalizeErpSessionInput($data['erp_phpsessid']);
        if ($sessionId === '') {
            return back()->withErrors(['erp_phpsessid' => 'Please enter a valid ERP PHPSESSID.']);
        }

        session(['po_erp_phpsessid' => $sessionId]);

        return back()->with('ok', 'ERP session saved for this login.');
    }

    public function connectErp(Request $request)
    {
        $data = $request->validate([
            'erp_username' => ['required', 'string', 'max:120'],
            'erp_password' => ['required', 'string', 'max:120'],
            'erp_dataset' => ['required', 'string', 'max:40'],
        ]);

        $loginUrl = (string) config('services.erp.login_url');
        $loginHost = parse_url($loginUrl, PHP_URL_HOST) ?: '';

        $initialResponse = Http::withOptions(['verify' => false, 'allow_redirects' => false])
            ->timeout((int) config('services.erp.timeout', 30))
            ->get($loginUrl);

        $sessionId = $this->extractPhpSessionId($initialResponse->headers());
        if ($sessionId === '') {
            return back()->withErrors(['erp_phpsessid' => 'Cannot open ERP login page. ERP did not return a session.']);
        }

        // Old login attempt kept for quick rollback/testing:
        // To test session-only behavior, skip the POST below and keep the PHPSESSID from GET login.php.
        $response = Http::asForm()
            ->withCookies(['PHPSESSID' => $sessionId], $loginHost)
            ->withOptions(['verify' => false, 'allow_redirects' => false])
            ->timeout((int) config('services.erp.timeout', 30))
            ->post($loginUrl, [
                'i_login' => $data['erp_username'],
                'i_passwd' => $data['erp_password'],
                'dataset' => $data['erp_dataset'],
                'host' => '',
                'stdmenu' => 't',
            ]);
        $responseSessionId = $this->extractPhpSessionId($response->headers());
        if ($responseSessionId !== '') {
            $sessionId = $responseSessionId;
        }

        session(['po_erp_phpsessid' => $sessionId]);

        return back()->with('ok', 'ERP connected for this login. Download PDF will verify access.');
    }

    public function clearErpSession()
    {
        session()->forget('po_erp_phpsessid');

        return back()->with('ok', 'ERP session cleared.');
    }

    public function print($id)
    {
        if (!$this->erpSessionId()) {
            return back()->withErrors(['erp_phpsessid' => 'Please save your ERP PHPSESSID before downloading PO PDF.']);
        }

        $po = PoHeader::query()->with(['attachments', 'workflow'])->findOrFail($id);
        $signatures = $this->erpService->printWorkflowSignatures($po->workflow_id);

        return $this->downloadSignedErpPdf($po, $signatures, "Purchase Order {$po->ordnumber}.pdf");
    }

    public function printDepartment(Request $request)
    {
        if (!$this->erpSessionId()) {
            return back()->withErrors(['erp_phpsessid' => 'Please save your ERP PHPSESSID before downloading PO PDF.']);
        }

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

            return [
                'po' => $po,
                'signatures' => $this->erpService->printWorkflowSignatures($po->workflow_id),
            ];
        })->values();

        abort_if($documents->isEmpty(), 404, 'No PO found for this department group');

        return $this->downloadSignedErpPdfBatch($documents->all(), "Purchase Order {$department}.pdf");
    }

    public function printSelected(Request $request)
    {
        if (!$this->erpSessionId()) {
            return back()->withErrors(['erp_phpsessid' => 'Please save your ERP PHPSESSID before downloading PO PDF.']);
        }

        $data = $request->validate([
            'po_ids' => ['required', 'array', 'min:1'],
            'po_ids.*' => ['integer'],
        ]);

        $ids = collect($data['po_ids'])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $headers = PoHeader::query()
            ->with(['attachments', 'workflow'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $documents = $ids
            ->map(fn ($id) => $headers->get($id))
            ->filter()
            ->map(fn (PoHeader $po) => [
                'po' => $po,
                'signatures' => $this->erpService->printWorkflowSignatures($po->workflow_id),
            ])
            ->values();

        abort_if($documents->isEmpty(), 404, 'No selected PO found');

        return $this->downloadSignedErpPdfBatch($documents->all(), 'Purchase Order Selected.pdf');
    }

    private function downloadSignedErpPdf(PoHeader $po, array $signatures, string $fileName)
    {
        $tempDir = storage_path('app/tmp/po-pdf');
        File::ensureDirectoryExists($tempDir);

        $token = (string) Str::uuid();
        $sourcePdfPath = "{$tempDir}/{$token}-erp.pdf";
        $signedPdfPath = "{$tempDir}/{$token}-signed.pdf";

        try {
            $this->downloadErpPdf($po, $sourcePdfPath);
            $this->writeSignedPdf($signedPdfPath, [
                ['pdf_path' => $sourcePdfPath, 'signatures' => $signatures],
            ]);
        } finally {
            File::delete($sourcePdfPath);
        }

        return response()
            ->download($signedPdfPath, $this->safePdfFileName($fileName), ['Content-Type' => 'application/pdf'])
            ->deleteFileAfterSend(true);
    }

    private function downloadSignedErpPdfBatch(array $documents, string $fileName)
    {
        $tempDir = storage_path('app/tmp/po-pdf');
        File::ensureDirectoryExists($tempDir);

        $token = (string) Str::uuid();
        $signedPdfPath = "{$tempDir}/{$token}-signed.pdf";
        $pdfs = [];

        try {
            foreach ($documents as $index => $document) {
                $sourcePdfPath = "{$tempDir}/{$token}-erp-{$index}.pdf";
                $pdfs[] = [
                    'pdf_path' => $sourcePdfPath,
                    'signatures' => $document['signatures'],
                ];
                $this->downloadErpPdf($document['po'], $sourcePdfPath);
            }

            $this->writeSignedPdf($signedPdfPath, $pdfs);
        } finally {
            foreach ($pdfs as $pdf) {
                File::delete($pdf['pdf_path']);
            }
        }

        return response()
            ->download($signedPdfPath, $this->safePdfFileName($fileName), ['Content-Type' => 'application/pdf'])
            ->deleteFileAfterSend(true);
    }

    private function downloadErpPdf(PoHeader $po, string $targetPath): void
    {
        $url = $this->erpPdfUrl($po);
        $request = Http::withOptions(['verify' => false])
            ->timeout((int) config('services.erp.timeout', 30));

        $sessionId = $this->erpSessionId();
        abort_if(!$sessionId, 422, 'Please save your ERP PHPSESSID before downloading PO PDF');

        if ($sessionId) {
            $request = $request->withCookies(
                ['PHPSESSID' => $sessionId],
                parse_url((string) config('services.erp.base_url'), PHP_URL_HOST) ?: ''
            );
        }

        $response = $request->get($url);
        $body = $response->body();

        if (!$response->successful()) {
            throw ValidationException::withMessages([
                'erp_phpsessid' => 'Cannot download PO PDF from ERP. Please connect ERP again.',
            ]);
        }

        if (!str_starts_with(ltrim($body), '%PDF')) {
            $this->logUnexpectedErpPdfResponse($po, $url, $response->status(), $response->header('Content-Type', ''), $body);
            session()->forget('po_erp_phpsessid');

            throw ValidationException::withMessages([
                'erp_phpsessid' => 'ERP session is invalid or expired. Please connect ERP again.',
            ]);
        }

        File::put($targetPath, $body);
    }

    private function logUnexpectedErpPdfResponse(PoHeader $po, string $url, int $status, string $contentType, string $body): void
    {
        $sample = preg_replace('/\s+/', ' ', strip_tags(substr($body, 0, 1000)));

        Log::warning('ERP PO PDF response was not a PDF', [
            'po_id' => $po->id,
            'ordnumber' => $po->ordnumber,
            'site' => $po->site,
            'url' => $url,
            'status' => $status,
            'content_type' => $contentType,
            'body_sample' => $sample,
        ]);
    }

    private function erpSessionId(): ?string
    {
        $sessionId = trim((string) session('po_erp_phpsessid', ''));

        return $sessionId !== '' ? $sessionId : config('services.erp.phpsessid');
    }

    private function normalizeErpSessionInput(string $value): string
    {
        $value = trim($value);

        if (preg_match('/PHPSESSID=([^;\s]+)/i', $value, $matches)) {
            $value = $matches[1];
        }

        return preg_match('/^[A-Za-z0-9,-]+$/', $value) ? $value : '';
    }

    private function extractPhpSessionId(array $headers): string
    {
        $cookies = $headers['Set-Cookie'] ?? $headers['set-cookie'] ?? [];
        $cookies = is_array($cookies) ? $cookies : [$cookies];

        foreach ($cookies as $cookie) {
            if (preg_match('/PHPSESSID=([^;\s]+)/i', (string) $cookie, $matches)) {
                return $this->normalizeErpSessionInput($matches[1]);
            }
        }

        return '';
    }

    private function erpPdfUrl(PoHeader $po): string
    {
        $source = PoErpService::normalizeSource($po->site) ?? PoErpService::SOURCE_WIRE;
        $formId = (string) config("services.erp.po_form_id_{$source}", config('services.erp.po_form_id_wire'));
        $purchaseId = $this->erpService->purchaseIdForPo((string) $po->ordnumber, $source);
        abort_if(!$purchaseId, 404, 'PO purchase_id not found in ERP');

        $baseUrl = (string) config('services.erp.base_url');
        $replacements = [
            '{form_id}' => rawurlencode($formId),
            '{purchase_id}' => rawurlencode((string) $purchaseId),
            '{po_no}' => rawurlencode((string) $po->ordnumber),
            '{ordnumber}' => rawurlencode((string) $po->ordnumber),
            '{site}' => rawurlencode($source),
        ];

        $url = strtr($baseUrl, $replacements);
        if ($url !== $baseUrl) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query([
            'form_type' => 'purchase',
            'form_id' => $formId,
            'purchase_id' => $purchaseId,
        ]);
    }

    private function writeSignedPdf(string $targetPath, array $documents): void
    {
        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(false);
        $tempImages = [];

        try {
            foreach ($documents as $document) {
                $pageCount = $pdf->setSourceFile($document['pdf_path']);
                $pagesToImport = $this->pagesToImportFromErpPdf($document['pdf_path'], $pageCount);
                for ($pageNo = 1; $pageNo <= $pagesToImport; $pageNo++) {
                    $templateId = $pdf->importPage($pageNo);
                    $size = $pdf->getTemplateSize($templateId);
                    $orientation = $size['width'] > $size['height'] ? 'L' : 'P';

                    $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                    $pdf->useTemplate($templateId);

                    if ($pageNo === $pagesToImport) {
                        $this->overlaySignatures($pdf, $document['signatures'], $size['width'], $size['height'], $tempImages);
                    }
                }
            }

            $pdf->Output('F', $targetPath);
        } finally {
            foreach ($tempImages as $path) {
                File::delete($path);
            }
        }
    }

    private function pagesToImportFromErpPdf(string $pdfPath, int $pageCount): int
    {
        if ($pageCount <= 1) {
            return $pageCount;
        }

        $contents = @file_get_contents($pdfPath);
        if ($contents === false) {
            return $pageCount;
        }

        // ERP sometimes appends a mostly blank second page even though the PO itself says 1/1.
        // Keep the full file for real multi-page PDFs, but trim the ERP trailing artifact for 1-page POs.
        if (preg_match('/\b1\s*\/\s*1\b/', $contents)) {
            return 1;
        }

        return $pageCount;
    }

    private function overlaySignatures(Fpdi $pdf, array $signatures, float $pageWidth, float $pageHeight, array &$tempImages): void
    {
        $orderedBy = collect($signatures['ordered_by'] ?? [])->first();
        $authorizedBy = $signatures['authorized_by'] ?? null;

        $this->overlaySignatureSlot($pdf, $orderedBy, 0.13 * $pageWidth, 0.878 * $pageHeight, 0.20 * $pageWidth, 0.041 * $pageHeight, 0.955 * $pageHeight, $tempImages);
        $this->overlaySignatureSlot($pdf, $authorizedBy, 0.43 * $pageWidth, 0.878 * $pageHeight, 0.20 * $pageWidth, 0.041 * $pageHeight, 0.955 * $pageHeight, $tempImages);
    }

    private function overlaySignatureSlot(Fpdi $pdf, ?object $signature, float $x, float $y, float $width, float $height, float $dateY, array &$tempImages): void
    {
        if (!$signature || empty($signature->signature_data_uri)) {
            return;
        }

        $imagePath = $this->signatureTempImage((string) $signature->signature_data_uri);
        if (!$imagePath) {
            return;
        }

        $tempImages[] = $imagePath;
        $pdf->Image($imagePath, $x, $y, $width, $height, 'PNG');

        if (!empty($signature->created_at)) {
            $pdf->SetFont('Arial', '', 7);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetXY($x + (0.20 * $width), $dateY - 0.2);
            $pdf->Cell(0.56 * $width, 4, date('d-M-Y', strtotime((string) $signature->created_at)), 0, 0, 'C');
        }
    }

    private function signatureTempImage(string $dataUri): ?string
    {
        if (!preg_match('/^data:image\/png;base64,(.+)$/', $dataUri, $matches)) {
            return null;
        }

        $contents = base64_decode($matches[1], true);
        if ($contents === false) {
            return null;
        }

        $path = storage_path('app/tmp/po-pdf/' . Str::uuid() . '-signature.png');
        File::put($path, $contents);

        return $path;
    }

    private function safePdfFileName(string $fileName): string
    {
        $fileName = preg_replace('/[\\\\\\/\\:\\*\\?\\"\\<\\>\\|]+/', '_', $fileName);

        return trim((string) $fileName) !== '' ? $fileName : 'purchase-order.pdf';
    }
}

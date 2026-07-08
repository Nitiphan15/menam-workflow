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
            $sourceFonts = $this->inspectPdfFonts($sourcePdfPath, $po);
            $this->writeSignedPdf($signedPdfPath, [
                ['pdf_path' => $sourcePdfPath, 'po' => $po, 'signatures' => $signatures, 'source_fonts' => $sourceFonts],
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
                $this->downloadErpPdf($document['po'], $sourcePdfPath);
                $pdfs[] = [
                    'pdf_path' => $sourcePdfPath,
                    'po' => $document['po'],
                    'signatures' => $document['signatures'],
                    'source_fonts' => $this->inspectPdfFonts($sourcePdfPath, $document['po']),
                ];
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

    private function inspectPdfFonts(string $pdfPath, PoHeader $po): array
    {
        $contents = @file_get_contents($pdfPath);
        if ($contents === false || $contents === '') {
            return [];
        }

        preg_match_all('/\/BaseFont\s*\/([A-Za-z0-9\+\-_,\.]+)/', $contents, $matches);
        $fonts = collect($matches[1] ?? [])
            ->map(fn ($font) => preg_replace('/^[A-Z]{6}\+/', '', (string) $font))
            ->filter()
            ->unique()
            ->values()
            ->all();

        Log::info('CPA PO PDF fonts inspected', [
            'po_id' => $po->id,
            'ordnumber' => $po->ordnumber,
            'site' => $po->site,
            'fonts' => $fonts,
            'thai_overlay_font' => $this->preferredThaiOverlayFont($fonts),
            'font_match_note' => $this->pdfFontMatchNote($fonts),
        ]);

        return $fonts;
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
        $pdf = new class extends Fpdi {
            public function useFontPath(string $path): void
            {
                $this->fontpath = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            }
        };
        $pdf->SetAutoPageBreak(false);
        $pdf->useFontPath(public_path('fonts/fpdf'));
        $pdf->AddFont('THSarabunNew', '', 'THSarabunNew.php');
        $pdf->AddFont('PoTahomaThai', '', 'PoTahomaThai.php');
        $pdf->AddFont('PoAngsanaThai', '', 'PoAngsanaThai.php');
        $tempImages = [];

        try {
            foreach ($documents as $document) {
                $pageCount = $pdf->setSourceFile($document['pdf_path']);
                $pagesToImport = $this->pagesToImportFromErpPdf($document['pdf_path'], $pageCount);
                $po = $document['po'] ?? null;
                $descriptionPages = $this->resolveOverridePages($po->pdf_description_override_pages ?? null, $pagesToImport);
                $commentsPages = $this->resolveOverridePages($po->pdf_comments_override_pages ?? null, $pagesToImport);

                for ($pageNo = 1; $pageNo <= $pagesToImport; $pageNo++) {
                    $templateId = $pdf->importPage($pageNo);
                    $size = $pdf->getTemplateSize($templateId);
                    $orientation = $size['width'] > $size['height'] ? 'L' : 'P';

                    $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                    $pdf->useTemplate($templateId);

                    $this->overlayPoTextOverrides(
                        $pdf,
                        $po,
                        $size['width'],
                        $size['height'],
                        $document['source_fonts'] ?? [],
                        $document['pdf_path'],
                        in_array($pageNo, $descriptionPages, true),
                        in_array($pageNo, $commentsPages, true),
                        $pageNo
                    );
                    $this->overlaySignatures($pdf, $document['signatures'], $size['width'], $size['height'], $tempImages);
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

    /**
     * Parse a page selection string ("2", "1,3", "1-3", "all"/"ทุกหน้า") into a
     * list of page numbers clamped to the document. Blank selects the last page.
     */
    private function resolveOverridePages($selection, int $pagesToImport): array
    {
        $selection = trim((string) ($selection ?? ''));

        if ($selection === '') {
            return [$pagesToImport];
        }

        if (in_array(mb_strtolower($selection), ['all', 'ทุกหน้า'], true)) {
            return range(1, $pagesToImport);
        }

        $pages = [];
        foreach (preg_split('/\s*,\s*/', $selection) ?: [] as $token) {
            if ($token === '') {
                continue;
            }

            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $token, $range)) {
                $from = max(1, min((int) $range[1], (int) $range[2]));
                $to = min($pagesToImport, max((int) $range[1], (int) $range[2]));
                for ($page = $from; $page <= $to; $page++) {
                    $pages[] = $page;
                }
            } elseif (preg_match('/^\d+$/', $token)) {
                $pages[] = min(max(1, (int) $token), $pagesToImport);
            }
        }

        $pages = array_values(array_unique($pages));
        sort($pages);

        return $pages !== [] ? $pages : [$pagesToImport];
    }

    private function overlayPoTextOverrides(
        Fpdi $pdf,
        ?PoHeader $po,
        float $pageWidth,
        float $pageHeight,
        array $sourceFonts = [],
        ?string $sourcePdfPath = null,
        bool $renderDescriptions = true,
        bool $renderComments = true,
        ?int $pageNo = null
    ): void {
        if (!$po || (!$renderDescriptions && !$renderComments)) {
            return;
        }

        $descriptionOverrides = $renderDescriptions
            ? collect($po->pdf_description_overrides ?? [])
                ->map(fn ($value) => trim((string) $value))
                ->values()
            : collect();
        $commentsOverride = $renderComments ? trim((string) ($po->pdf_comments_override ?? '')) : '';

        if ($descriptionOverrides->filter()->isEmpty() && $commentsOverride === '') {
            return;
        }

        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetTextColor(0, 0, 0);
        $baselines = $sourcePdfPath
            ? $this->extractPdfTextBaselines($sourcePdfPath, $pageHeight, $pageNo)
            : [];

        $descriptionText = $descriptionOverrides->filter()->implode("\n");

        if ($descriptionText !== '') {
            $x = 0.123 * $pageWidth;
            $tableTop = 0.386 * $pageHeight;
            $w = 0.356 * $pageWidth;

            // Append below the last text line in the description column of this
            // page (read from the source PDF) so existing lines never overlap.
            $lastLineY = $this->lowestTextBaseline($baselines, 0.115 * $pageWidth, 0.485 * $pageWidth, $tableTop - 3.0, 0.63 * $pageHeight);

            if ($lastLineY !== null) {
                $appendY = $lastLineY + 2.0;
            } else {
                $detailRows = $this->erpService->getDetailRows((string) $po->ordnumber, $po->site)
                    ->take(5)
                    ->values();
                $totalLines = $detailRows->sum(
                    fn ($row) => max(1, min(4, count($this->wrapPdfText((string) ($row->description ?? ''), $w - 2.0, 4))))
                );
                $appendY = $tableTop + (max(1, $totalLines - 1) * 4.9);
            }

            $this->writePoTextBox($pdf, $descriptionText, $x, $appendY, $w - 2.0, max(14.5, (0.645 * $pageHeight) - $appendY), 10.8, 4.8, 8, $sourceFonts, 'description');
        }

        if ($commentsOverride !== '') {
            $x = 0.169 * $pageWidth;
            $baseY = 0.674 * $pageHeight;
            $w = 0.457 * $pageWidth;
            $h = 0.067 * $pageHeight;

            // Comments area spans from the label row down to just above the
            // grand-amount baht text; append below the last existing line.
            $lastLineY = $this->lowestTextBaseline($baselines, 0.08 * $pageWidth, 0.64 * $pageWidth, $baseY - 3.0, 0.758 * $pageHeight);

            if ($lastLineY !== null) {
                $appendY = $lastLineY + 4.0;
            } else {
                $existingComments = trim((string) ($po->notes ?? ''));
                $existingLineCount = $existingComments !== ''
                    ? max(1, min(3, count($this->wrapPdfText($existingComments, $w - 2.0, 3))))
                    : 0;
                $appendY = $baseY + max(10.0, $existingLineCount * 9.0);
            }

            $this->writePoTextBox($pdf, $commentsOverride, $x + 1.0, $appendY, $w - 2.0, max(12.0, $h - ($appendY - $baseY)), 12, 5.8, 3, $sourceFonts, 'comments');
        }
    }

    /**
     * Extract the baseline positions (mm, top-left origin) of every text draw
     * in the PDF's content streams, so overrides can be placed on empty lines.
     */
    private function extractPdfTextBaselines(string $pdfPath, float $pageHeightMm, ?int $pageNo = null): array
    {
        $raw = @file_get_contents($pdfPath);
        if ($raw === false || $raw === '') {
            return [];
        }

        $ptPerMm = 72 / 25.4;
        $pageHeightPt = $pageHeightMm * $ptPerMm;
        $baselines = [];

        $contents = [];
        if ($pageNo !== null) {
            $pageContent = $this->pdfPageContentStream($raw, $pageNo);
            if ($pageContent !== null && str_contains($pageContent, 'BT')) {
                $contents[] = $pageContent;
            }
        }

        if ($contents === []) {
            // Fallback: scan every stream in the file (single-page PDFs and
            // documents whose page tree could not be mapped).
            if (!preg_match_all('/<<(.*?)>>\s*stream\r?\n(.*?)endstream/s', $raw, $matches, PREG_SET_ORDER)) {
                return [];
            }

            foreach ($matches as $match) {
                $content = $match[2];
                if (str_contains($match[1], 'FlateDecode')) {
                    $content = @gzuncompress(rtrim($content, "\r\n"));
                    if ($content === false) {
                        continue;
                    }
                }
                $contents[] = $content;
            }
        }

        foreach ($contents as $content) {
            if (!str_contains($content, 'BT')) {
                continue;
            }

            foreach ($this->parsePdfTextBaselines($content) as [$xPt, $yPt]) {
                $baselines[] = [
                    'x' => $xPt / $ptPerMm,
                    'y' => ($pageHeightPt - $yPt) / $ptPerMm,
                ];
            }
        }

        return $baselines;
    }

    private function pdfPageContentStream(string $raw, int $pageNo): ?string
    {
        if (!preg_match_all('/\/Type\s*\/Page(?!s)[^>]{0,600}?\/Contents\s+(?:\[\s*)?(\d+)\s+0\s+R/s', $raw, $matches)) {
            return null;
        }

        $contentObjectId = $matches[1][$pageNo - 1] ?? null;
        if ($contentObjectId === null) {
            return null;
        }

        if (!preg_match('/(?<!\d)' . $contentObjectId . '\s+0\s+obj\b(.*?)endobj/s', $raw, $object)) {
            return null;
        }

        if (!preg_match('/<<(.*?)>>\s*stream\r?\n(.*?)endstream/s', $object[1], $stream)) {
            return null;
        }

        $content = $stream[2];
        if (str_contains($stream[1], 'FlateDecode')) {
            $content = @gzuncompress(rtrim($content, "\r\n"));
            if ($content === false) {
                return null;
            }
        }

        return $content;
    }

    private function parsePdfTextBaselines(string $content): array
    {
        $num = '[-+]?[0-9]*\.?[0-9]+';
        $str = '(?:\((?:\\\\.|[^\\\\()])*\)|<[0-9A-Fa-f\s]*>)';
        // String-show ops come first so string bytes are never parsed as operators.
        $pattern = '/(?<show>' . $str . '\s*(?:Tj|\'|"))'
            . '|(?<tjarr>\[[^\]]*\]\s*TJ)'
            . '|(?<cm>' . $num . '\s+' . $num . '\s+' . $num . '\s+' . $num . '\s+' . $num . '\s+' . $num . '\s+cm)'
            . '|(?<tm>' . $num . '\s+' . $num . '\s+' . $num . '\s+' . $num . '\s+' . $num . '\s+' . $num . '\s+Tm)'
            . '|(?<td>' . $num . '\s+' . $num . '\s+T[dD])'
            . '|(?<tl>' . $num . '\s+TL)'
            . '|(?<tstar>T\*)'
            . '|(?<bt>\bBT\b)'
            . '|(?<qpush>\bq\b)'
            . '|(?<qpop>\bQ\b)/s';

        if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $identity = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $multiply = fn (array $a, array $b): array => [
            $a[0] * $b[0] + $a[1] * $b[2],
            $a[0] * $b[1] + $a[1] * $b[3],
            $a[2] * $b[0] + $a[3] * $b[2],
            $a[2] * $b[1] + $a[3] * $b[3],
            $a[4] * $b[0] + $a[5] * $b[2] + $b[4],
            $a[4] * $b[1] + $a[5] * $b[3] + $b[5],
        ];
        $numbers = function (string $op) use ($num): array {
            preg_match_all('/' . $num . '/', $op, $found);
            return array_map('floatval', $found[0]);
        };

        $ctm = $identity;
        $ctmStack = [];
        $textLineMatrix = $identity;
        $leading = 0.0;
        $baselines = [];

        foreach ($matches as $match) {
            $op = $match[0];

            if (($match['show'] ?? '') !== '' || ($match['tjarr'] ?? '') !== '') {
                // The ' and " operators move to the next line before showing text.
                if (str_ends_with(rtrim($op), "'") || str_ends_with(rtrim($op), '"')) {
                    $textLineMatrix = $multiply([1.0, 0.0, 0.0, 1.0, 0.0, -$leading], $textLineMatrix);
                }
                if (preg_match('/\(.*[^\s].*\)|<[0-9A-Fa-f]/s', $op)) {
                    $device = $multiply($textLineMatrix, $ctm);
                    $baselines[] = [$device[4], $device[5]];
                }
            } elseif (($match['cm'] ?? '') !== '') {
                $ctm = $multiply($numbers($op), $ctm);
            } elseif (($match['tm'] ?? '') !== '') {
                $textLineMatrix = $numbers($op);
            } elseif (($match['td'] ?? '') !== '') {
                $n = $numbers($op);
                if (str_contains($op, 'TD')) {
                    $leading = -$n[1];
                }
                $textLineMatrix = $multiply([1.0, 0.0, 0.0, 1.0, $n[0], $n[1]], $textLineMatrix);
            } elseif (($match['tl'] ?? '') !== '') {
                $leading = $numbers($op)[0];
            } elseif (($match['tstar'] ?? '') !== '') {
                $textLineMatrix = $multiply([1.0, 0.0, 0.0, 1.0, 0.0, -$leading], $textLineMatrix);
            } elseif (($match['bt'] ?? '') !== '') {
                $textLineMatrix = $identity;
            } elseif (($match['qpush'] ?? '') !== '') {
                $ctmStack[] = $ctm;
            } elseif (($match['qpop'] ?? '') !== '') {
                $ctm = array_pop($ctmStack) ?? $identity;
            }
        }

        return $baselines;
    }

    private function lowestTextBaseline(array $baselines, float $x0, float $x1, float $y0, float $y1): ?float
    {
        $lowest = null;

        foreach ($baselines as $baseline) {
            if ($baseline['x'] < $x0 || $baseline['x'] > $x1 || $baseline['y'] < $y0 || $baseline['y'] > $y1) {
                continue;
            }

            $lowest = $lowest === null ? $baseline['y'] : max($lowest, $baseline['y']);
        }

        return $lowest;
    }

    private function writePoTextBox(
        Fpdi $pdf,
        string $text,
        float $x,
        float $y,
        float $width,
        float $height,
        float $fontSize,
        float $lineHeight,
        int $maxLines,
        array $sourceFonts = [],
        string $field = 'default'
    ): void {
        $fontFamily = $this->overlayFontForText($text, $field, $sourceFonts);
        $fontSize = $this->overlayFontSizeForText($text, $fontFamily, $fontSize);
        $pdf->SetFont($fontFamily, '', $fontSize);
        $lines = $this->wrapPdfText($text, $width, $maxLines);

        foreach ($lines as $index => $line) {
            $lineY = $y + ($index * $lineHeight);
            if ($lineY > ($y + $height - $lineHeight)) {
                break;
            }

            $pdf->SetXY($x, $lineY);
            $pdf->Cell($width, $lineHeight, $this->pdfText($line), 0, 0, 'L');
        }
    }

    private function wrapPdfText(string $text, float $width, int $maxLines): array
    {
        $segments = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];
        $lines = [];

        foreach ($segments as $segment) {
            $segment = trim((string) $segment);
            if ($segment === '') {
                continue;
            }

            $words = preg_split('/(\s+)/u', $segment, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$segment];
            $line = '';

            foreach ($words as $word) {
                $candidate = $line . $word;
                if ($line !== '' && mb_strlen($candidate) > $this->pdfTextCharacterLimit($width)) {
                    $lines[] = trim($line);
                    $line = ltrim($word);
                } else {
                    $line = $candidate;
                }

                if (count($lines) >= $maxLines) {
                    return $lines;
                }
            }

            if (trim($line) !== '') {
                $lines[] = trim($line);
            }

            if (count($lines) >= $maxLines) {
                return array_slice($lines, 0, $maxLines);
            }
        }

        return array_slice($lines, 0, $maxLines);
    }

    private function pdfTextCharacterLimit(float $width): int
    {
        return max(12, (int) floor($width / 2.35));
    }

    private function pdfText(string $text): string
    {
        if ($this->isAsciiText($text)) {
            return $text;
        }

        $converted = iconv('UTF-8', 'CP874//IGNORE', $text);

        return $converted !== false ? $converted : $text;
    }

    private function isAsciiText(string $text): bool
    {
        return !preg_match('/[^\x00-\x7F]/', $text);
    }

    private function overlayFontForText(string $text, string $field, array $sourceFonts): string
    {
        if ($this->isAsciiText($text)) {
            return 'times';
        }

        if ($field === 'description') {
            return $this->preferredThaiOverlayFont($sourceFonts);
        }

        return $this->preferredThaiOverlayFont($sourceFonts);
    }

    private function overlayFontSizeForText(string $text, string $fontFamily, float $fontSize): float
    {
        if ($this->isAsciiText($text)) {
            return max(9, $fontSize - 2);
        }

        // Angsana glyphs are drawn much smaller than sans fonts at the same point
        // size; the CPA PDF body text is ~16pt Angsana New, so match that.
        if ($fontFamily === 'PoAngsanaThai') {
            return 16.0;
        }

        return $fontSize;
    }

    private function preferredThaiOverlayFont(array $sourceFonts): string
    {
        $fontText = strtolower(implode(' ', $sourceFonts));

        if (str_contains($fontText, 'sarabun')) {
            return 'THSarabunNew';
        }

        if (str_contains($fontText, 'tahoma')) {
            return 'PoTahomaThai';
        }

        // Exact reuse of embedded CPA subset fonts is not available in FPDF.
        // CPA PO PDFs use Angsana New for the body text, so the Angsana-based
        // Thai font keeps appended text matching the source typeface.
        return 'PoAngsanaThai';
    }

    private function pdfFontMatchNote(array $sourceFonts): string
    {
        $fontText = strtolower(implode(' ', $sourceFonts));

        foreach (['angsana', 'cordia', 'browallia', 'times', 'tahoma', 'sarabun'] as $needle) {
            if (str_contains($fontText, $needle)) {
                return "CPA PDF appears to use {$needle}; overlay can match only if the local TTF exists and supports CP874.";
            }
        }

        return 'CPA PDF font appears embedded/subset or unavailable by name; overlay uses the configured Thai fallback.';
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
        [$imageX, $imageY, $imageWidth, $imageHeight] = $this->signatureImageBox($imagePath, $x, $y, $width, $height);
        $pdf->Image($imagePath, $imageX, $imageY, $imageWidth, $imageHeight, 'PNG');

        if (!empty($signature->created_at)) {
            $pdf->SetFont('Arial', '', 7);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetXY($x, $dateY - 0.2);
            $pdf->Cell($width, 4, date('d-M-Y', strtotime((string) $signature->created_at)), 0, 0, 'C');
        }
    }

    private function signatureImageBox(string $imagePath, float $x, float $y, float $width, float $height): array
    {
        $size = @getimagesize($imagePath);
        $imageWidthPx = (float) ($size[0] ?? 0);
        $imageHeightPx = (float) ($size[1] ?? 0);

        if ($imageWidthPx <= 0 || $imageHeightPx <= 0) {
            return [$x, $y, $width, $height];
        }

        $maxWidth = $width * 0.82;
        $maxHeight = $height * 0.78;
        $scale = min($maxWidth / $imageWidthPx, $maxHeight / $imageHeightPx);
        $drawWidth = $imageWidthPx * $scale;
        $drawHeight = $imageHeightPx * $scale;

        return [
            $x + (($width - $drawWidth) / 2),
            $y + (($height - $drawHeight) / 2),
            $drawWidth,
            $drawHeight,
        ];
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

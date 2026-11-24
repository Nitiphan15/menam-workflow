<?php

namespace App\Services\Po;

use App\Models\Po\PoAttached;
use App\Models\Po\PoHeader;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PoSubmitAttachmentService
{
    public function __construct(
        private readonly PoErpService $erpService,
        private readonly PoErpPdfDownloader $downloader,
        private readonly PoAttached $attachmentModel,
    ) {}

    public function attachFromErp(PoHeader $po, string $sessionId, int $userId): PoAttached
    {
        $source = PoErpService::normalizeSource($po->site) ?? PoErpService::SOURCE_WIRE;
        $body = $this->downloader->download($this->erpPdfUrl($po, $source), $sessionId, [
            'po_id' => $po->id,
            'ordnumber' => $po->ordnumber,
            'site' => $po->site,
            'action' => 'purchase_submit_attachment',
        ]);

        $version = (int) $po->attachments()->where('attachment_type', 'PO_SUBMIT')->max('po_version') + 1;
        $now = now();
        $directory = "po/{$now->year}/{$now->format('m')}/{$now->format('d')}";
        $safePoNumber = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $po->ordnumber);
        $fileName = self::fileNameFor($safePoNumber, $version);
        $path = "{$directory}/{$fileName}";
        while (Storage::disk('public')->exists($path)) {
            $version++;
            $fileName = self::fileNameFor($safePoNumber, $version);
            $path = "{$directory}/{$fileName}";
        }

        Storage::disk('public')->put($path, $body);

        try {
            return $this->attachmentModel->newQuery()->create([
                'po_header_id' => $po->id,
                'file_name' => $fileName,
                'file_path' => $path,
                'file_ext' => 'pdf',
                'mime_type' => 'application/pdf',
                'file_size' => strlen($body),
                'remark' => 'Auto Attachment from Purchase Submit',
                'workflow_step_no' => 1,
                'attachment_type' => 'PO_SUBMIT',
                'attachment_source' => 'ERP',
                'po_version' => $version,
                'created_at' => $now,
                'created_by' => $userId,
            ]);
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($path);
            throw $exception;
        }
    }

    /** First version: PO2026110229_PO.pdf; later revisions: PO2026110229_PO_v02.pdf, _v03 … */
    public static function fileNameFor(string $safePoNumber, int $version): string
    {
        return $version <= 1
            ? "{$safePoNumber}_PO.pdf"
            : sprintf('%s_PO_v%02d.pdf', $safePoNumber, $version);
    }

    private function erpPdfUrl(PoHeader $po, string $source): string
    {
        $formId = (string) config("services.erp.po_form_id_{$source}", config('services.erp.po_form_id_wire'));
        $purchaseId = $this->erpService->purchaseIdForPo((string) $po->ordnumber, $source);
        abort_if(!$purchaseId, 404, 'PO purchase_id not found in ERP');

        $baseUrl = (string) config('services.erp.base_url');
        $url = strtr($baseUrl, [
            '{form_id}' => rawurlencode($formId),
            '{purchase_id}' => rawurlencode((string) $purchaseId),
            '{po_no}' => rawurlencode((string) $po->ordnumber),
            '{ordnumber}' => rawurlencode((string) $po->ordnumber),
            '{site}' => rawurlencode($source),
        ]);
        if ($url !== $baseUrl) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query([
            'form_type' => 'purchase',
            'form_id' => $formId,
            'purchase_id' => $purchaseId,
        ]);
    }
}

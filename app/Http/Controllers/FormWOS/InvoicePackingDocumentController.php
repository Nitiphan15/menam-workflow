<?php

namespace App\Http\Controllers\FormWOS;

use App\Http\Controllers\Controller;
use App\Services\FormWOS\InvoicePackingDocumentService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InvoicePackingDocumentController extends Controller
{
    private const MAX_INVOICES = 10;
    private const MAX_ROWS = 10;
    private const ROWS_PER_PAGE = 5;

    public function index()
    {
        return view('formwos.invoice_packing_document.index');
    }

    public function invoices(Request $request, InvoicePackingDocumentService $service)
    {
        $query = mb_substr(trim((string) $request->query('q', '')), 0, 50);

        return response()->json([
            'results' => $service->suggestInvoiceNumbers($query),
        ]);
    }

    public function preview(Request $request, InvoicePackingDocumentService $service)
    {
        if (is_array($request->input('invoice_numbers'))) {
            $request->merge([
                'invoice_numbers' => collect($request->input('invoice_numbers'))
                    ->filter(fn ($number) => is_scalar($number))
                    ->implode(','),
            ]);
        }

        $validated = $request->validate([
            'invoice_numbers' => ['required', 'string', 'max:1000'],
            'signer_name' => ['required', 'string', 'max:150'],
        ], [
            'invoice_numbers.required' => 'กรุณากรอกเลข Invoice อย่างน้อย 1 เลข',
            'signer_name.required' => 'กรุณากรอกชื่อ–นามสกุลผู้ลงชื่อ',
        ]);

        $invoiceNumbers = $service::normalizeInvoiceNumbers($validated['invoice_numbers']);

        if (count($invoiceNumbers) > self::MAX_INVOICES) {
            throw ValidationException::withMessages([
                'invoice_numbers' => 'กรอก Invoice ได้ไม่เกิน ' . self::MAX_INVOICES . ' เลขต่อครั้ง',
            ]);
        }

        $rows = $service->lookup($invoiceNumbers);
        $foundInvoices = collect($rows)
            ->pluck('invoice_no')
            ->map(fn ($number) => strtoupper(trim((string) $number)))
            ->unique();
        $missingInvoices = collect($invoiceNumbers)->reject(fn ($number) => $foundInvoices->contains($number))->values();

        if ($missingInvoices->isNotEmpty()) {
            throw ValidationException::withMessages([
                'invoice_numbers' => 'ไม่พบ Invoice หรือไม่มีข้อมูลน้ำหนัก: ' . $missingInvoices->implode(', '),
            ]);
        }

        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'invoice_numbers' => 'Invoice ที่เลือกมีรวม ' . count($rows)
                    . ' รายการ กรุณาเลือกใหม่ให้ไม่เกิน ' . self::MAX_ROWS . ' รายการ',
            ]);
        }

        return view('formwos.invoice_packing_document.print', [
            'pages' => $service::pages($rows, self::ROWS_PER_PAGE),
            'signerName' => trim($validated['signer_name']),
            'invoiceNumbers' => $invoiceNumbers,
        ]);
    }
}

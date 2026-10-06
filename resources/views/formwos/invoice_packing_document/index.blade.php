@extends('layouts.layout')

@section('title', 'Invoice Packing List')
@section('page-title', 'Invoice Packing List')

@section('content')
    @php
        $initialInvoices = collect(preg_split('/[\s,;]+/', strtoupper((string) old('invoice_numbers', 'D2026080106'))) ?: [])
            ->map(fn ($number) => trim($number))
            ->filter()
            ->unique()
            ->take(10);
    @endphp

    <div class="container py-4" style="max-width: 920px;">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3">
                <h4 class="mb-1">สร้างรายการ Invoice / Packing List</h4>

            </div>

            <div class="card-body p-4">
                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('wos.invoice_packing_document.preview') }}" target="_blank">
                    @csrf

                    <div class="mb-3">
                        <label for="invoice_numbers_picker" class="form-label fw-semibold">เลข Invoice</label>
                        <select id="invoice_numbers_picker" name="invoice_numbers[]" multiple required
                            class="form-select @error('invoice_numbers') is-invalid @enderror"
                            placeholder="พิมพ์เลข Invoice อย่างน้อย 2 ตัว">
                            @foreach ($initialInvoices as $invoiceNumber)
                                <option value="{{ $invoiceNumber }}" selected>{{ $invoiceNumber }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">
                            ค้นหาและเลือกได้สูงสุด 10 Invoice หรือพิมพ์เลขเองได้
                        </div>
                        @error('invoice_numbers')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-4">
                        <label for="signer_name" class="form-label fw-semibold">ชื่อ–นามสกุลใต้ช่องลงชื่อ</label>
                        <input id="signer_name" name="signer_name" type="text" maxlength="150"
                            class="form-control @error('signer_name') is-invalid @enderror" value="{{ old('signer_name') }}"
                            placeholder="ชื่อ นามสกุล" required>
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="fa-solid fa-file-lines me-1"></i>
                            แสดงตัวอย่างเอกสาร
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const lookupUrl = @json(route('wos.invoice_packing_document.invoices'));

            new TomSelect('#invoice_numbers_picker', {
                valueField: 'invoice_no',
                labelField: 'text',
                searchField: ['invoice_no', 'customer_name', 'text'],
                plugins: ['remove_button'],
                maxItems: 10,
                create: function (input) {
                    const invoiceNumber = String(input || '').trim().toUpperCase();
                    return invoiceNumber ? {
                        invoice_no: invoiceNumber,
                        text: invoiceNumber,
                        customer_name: '',
                        invoice_date: '',
                        site: ''
                    } : false;
                },
                persist: false,
                preload: false,
                closeAfterSelect: false,
                loadThrottle: 300,
                shouldLoad: query => String(query || '').trim().length >= 2,
                load: function (query, callback) {
                    fetch(`${lookupUrl}?q=${encodeURIComponent(query)}`, {
                        headers: { 'Accept': 'application/json' }
                    })
                        .then(response => response.ok ? response.json() : Promise.reject())
                        .then(json => callback(json.results || []))
                        .catch(() => callback());
                },
                render: {
                    option: function (item, escape) {
                        const details = [item.invoice_date, item.customer_name, item.site]
                            .filter(Boolean)
                            .map(escape)
                            .join(' | ');
                        return `<div>
                            <div class="fw-semibold">${escape(item.invoice_no)}</div>
                            ${details ? `<div class="small text-muted">${details}</div>` : ''}
                        </div>`;
                    },
                    item: function (item, escape) {
                        return `<div>${escape(item.invoice_no)}</div>`;
                    }
                }
            });
        });
    </script>
@endpush

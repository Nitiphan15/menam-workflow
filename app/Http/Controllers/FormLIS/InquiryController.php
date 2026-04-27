<?php

// app/Http/Controllers/InquiryController.php
namespace App\Http\Controllers\FormLIS;

use App\Http\Controllers\Controller;
use App\Models\FormLIS\Inquiry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class InquiryController extends Controller
{
    public function create()
    {
        return view('formlis.inquiries.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer'       => ['required', 'string', 'max:255'],
            'partnumber'     => ['required', 'string', 'max:255'],
            'qty'            => ['required', 'integer', 'min:1'],
            'delivery_date'  => ['required', 'date'],
            'priority'       => ['required', 'integer', 'in:1,2,3'],
            'remark'         => ['nullable', 'string', 'max:2000'],
            'attachments'    => ['nullable', 'array'],
            'attachments.*'  => ['file', 'max:10240'], // 10MB/ไฟล์ (ปรับได้)
        ]);

        DB::transaction(function () use ($data, $request) {
            $inquiry = Inquiry::create([
                'customer'      => $data['customer'],
                'partnumber'    => $data['partnumber'],
                'qty'           => $data['qty'],
                'delivery_date' => $data['delivery_date'],
                'priority'      => $data['priority'],
                'remark'        => $data['remark'] ?? null,
            ]);

            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $path = $file->store("inquiries/{$inquiry->id}", 'public');

                    $inquiry->files()->create([
                        'original_name' => $file->getClientOriginalName(),
                        'path'          => $path,
                        'mime'          => $file->getClientMimeType(),
                        'size'          => $file->getSize(),
                    ]);
                }
            }
        });

        return redirect()
            ->route('lis.index')
            ->with('success', 'บันทึก Inquiry เรียบร้อยแล้ว');
    }

    public function index(Request $request)
    {
        $partnumber = trim((string)$request->query('partnumber', ''));
        $dateFrom   = trim((string)$request->query('date_from', ''));
        $dateTo     = trim((string)$request->query('date_to', ''));

        $q = Inquiry::query()->with('files')->orderBy('delivery_date')->orderByDesc('id');

        if ($partnumber !== '') {
            $q->where('partnumber', 'like', "%{$partnumber}%");
        }

        // filter วันที่ต้องส่งมอบ (ช่วงวันที่)
        if ($dateFrom !== '') $q->whereDate('delivery_date', '>=', $dateFrom);
        if ($dateTo !== '')   $q->whereDate('delivery_date', '<=', $dateTo);

        $rows = $q->paginate(20)->withQueryString();

        return view('formlis.inquiries.index', compact('rows', 'partnumber', 'dateFrom', 'dateTo'));
    }
}

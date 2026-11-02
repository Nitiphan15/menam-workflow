<?php

namespace App\Http\Controllers\FormDIE;

use App\Exports\FormDIE\DieMasterExtraTemplateExport;
use App\Http\Controllers\Controller;
use App\Services\FormDIE\DieMasterExtraService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class DieMasterExtraController extends Controller
{
    public function __construct(private DieMasterExtraService $service) {}

    public function update(Request $request)
    {
        $data = $request->validate([
            'site'          => 'required|in:W,P',
            'equipnumber'   => 'required|string|max:100',
            'received_date' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'notes'         => 'nullable|string|max:1000',
        ]);

        $extra = $this->service->save(
            $data['site'],
            $data['equipnumber'],
            $data['received_date'] ?? null,
            $data['notes'] ?? null,
            (string) $request->user()->name
        );
        if ($extra === null) {
            return response()->json(['message' => 'ไม่พบไดร์นี้ในฐาน PCM'], 422);
        }

        return response()->json(['extra' => $extra]);
    }

    public function template()
    {
        return Excel::download(new DieMasterExtraTemplateExport(), 'die-received-date-template.xlsx');
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls|max:10240']);

        $result = $this->service->import($request->file('file')->getRealPath(), (string) $request->user()->name);

        return response()->json($result, $result['errors'] ? 422 : 200);
    }
}

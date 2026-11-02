<?php

namespace App\Http\Controllers\FormDIE;

use App\Http\Controllers\Controller;
use App\Models\FormDIE\DieDamage;
use App\Services\FormDIE\DieDamageLookupService;
use App\Services\FormDIE\DieDamageReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class DieDamageController extends Controller
{
    private function ready(): bool
    {
        return Schema::connection(config('database.workflow_connection'))->hasTable('die_damages');
    }

    private function filtered(Request $request)
    {
        $f = $request->validate([
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d'.($request->filled('from') ? '|after_or_equal:from' : ''),
            'site' => ['nullable', Rule::in(['W', 'P'])], 'q' => 'nullable|string|max:100',
            'reason' => 'nullable|string|max:255', 'die_type' => 'nullable|string|max:255',
            'report' => ['nullable', Rule::in(array_keys(DieDamageReportService::REPORTS))],
        ]);
        $q = DieDamage::query();
        foreach (['site', 'reason', 'die_type'] as $field) {
            if (! empty($f[$field])) {
                $q->where($field, $f[$field]);
            }
        }
        if (! empty($f['from'])) {
            $q->where('damaged_at', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->where('damaged_at', '<=', $f['to']);
        }
        if (! empty($f['q'])) {
            $q->where(fn ($q) => $q->where('die_code', 'like', '%'.$f['q'].'%')->orWhere('workorder', 'like', '%'.$f['q'].'%'));
        }

        return $q;
    }

    public function index(Request $request)
    {
        $ready = $this->ready();
        $records = $ready ? $this->filtered($request)->orderByDesc('damaged_at')->orderByDesc('id')->paginate(30)->withQueryString() : null;

        return view('formdie.damage.index', compact('ready', 'records'));
    }

    public function create()
    {
        abort_unless($this->ready(), 503, 'กรุณาติดตั้งตาราง DIE Reports ก่อน');

        return view('formdie.damage.form', ['record' => new DieDamage(['site' => 'W', 'damaged_at' => today()])]);
    }

    public function edit(DieDamage $damage)
    {
        return view('formdie.damage.form', ['record' => $damage]);
    }

    private function validated(Request $request): array
    {
        $rules = ['site' => ['required', Rule::in(['W', 'P'])], 'damaged_at' => 'required|date_format:Y-m-d|before_or_equal:today', 'die_code' => 'required|string|max:100', 'notes' => 'nullable|string|max:4000'];
        foreach (DieDamageReportService::FIELDS as $field => $label) {
            $rules[$field] = in_array($field, DieDamageReportService::NUMBERS, true) ? 'nullable|numeric|min:0|max:999999999999.9999' : 'nullable|string|max:255';
        }
        $rules['usage_count'] = 'nullable|integer|min:0|max:2147483647';
        $rules['reason'] = 'required|string|max:255';
        $rules['die_type'] = 'required|string|max:255';
        if ($request->filled('tensile_min')) {
            $rules['tensile_max'] .= '|gte:tensile_min';
        }
        $data = $request->validate($rules);
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = trim($value);
            }
        }
        $data['die_code'] = strtoupper($data['die_code']);
        if (! empty($data['workorder'])) {
            $data['workorder'] = strtoupper($data['workorder']);
        }
        $data['updated_by'] = (string) $request->user()->getAuthIdentifier();

        return $data;
    }

    private function snapshot(Request $request, array $data): ?array
    {
        $snapshot = $request->session()->get('die_damage_lookup');
        $source = $snapshot['source'] ?? [];

        return ($source['site'] ?? null) === $data['site'] && ($source['die_code'] ?? null) === $data['die_code'] && ($source['as_of'] ?? null) === $data['damaged_at'] ? $snapshot : null;
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['source_snapshot'] = $this->snapshot($request, $data);
        $data['created_by'] = $data['updated_by'];
        DieDamage::create($data);
        $request->session()->forget('die_damage_lookup');

        return redirect()->route('die-reports.index')->with('success', 'บันทึกเหตุการณ์ DIE เสียแล้ว');
    }

    public function update(Request $request, DieDamage $damage)
    {
        $data = $this->validated($request);
        $request->validate(['version' => 'required|integer|min:1']);
        $data['version'] = (int) $request->version + 1;
        $snapshot = $this->snapshot($request, $data);
        if ($snapshot) {
            $data['source_snapshot'] = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
        } elseif ($damage->site !== $data['site'] || $damage->die_code !== $data['die_code'] || $damage->damaged_at->format('Y-m-d') !== $data['damaged_at']) {
            $data['source_snapshot'] = null;
        }
        $count = DieDamage::whereKey($damage->id)->where('version', $request->version)->update($data);
        abort_unless($count, 409, 'รายการถูกแก้ไขแล้ว กรุณาเปิดรายการใหม่ก่อนบันทึก');
        $request->session()->forget('die_damage_lookup');

        return redirect()->route('die-reports.index')->with('success', 'แก้ไขรายการแล้ว');
    }

    public function destroy(Request $request, DieDamage $damage)
    {
        $request->validate(['version' => 'required|integer|min:1']);
        $count = DieDamage::whereKey($damage->id)->where('version', $request->version)->update(['deleted_at' => now(), 'updated_by' => (string) $request->user()->getAuthIdentifier(), 'version' => $damage->version + 1]);
        abort_unless($count, 409, 'รายการถูกแก้ไขแล้ว กรุณาโหลดหน้าใหม่');

        return redirect()->route('die-reports.index')->with('success', 'ลบรายการแล้ว (เก็บประวัติในฐานข้อมูล)');
    }

    public function lookup(Request $request, DieDamageLookupService $service)
    {
        $data = $request->validate(['site' => ['required', Rule::in(['W', 'P'])], 'die_code' => 'required|string|max:100', 'damaged_at' => 'required|date_format:Y-m-d|before_or_equal:today', 'workorder' => 'nullable|string|max:100']);
        $request->session()->forget('die_damage_lookup');
        try {
            $result = $service->lookup($data['site'], strtoupper(trim($data['die_code'])), $data['damaged_at'], $data['workorder'] ?? null);
            if ($result['found']) {
                $request->session()->put('die_damage_lookup', $result);
            }

            return response()->json($result);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'เชื่อมต่อ PCM ไม่สำเร็จ สามารถกรอกข้อมูลเองได้'], 503);
        }
    }

    public function reports(Request $request, DieDamageReportService $service)
    {
        $ready = $this->ready();
        $records = $ready ? $this->filtered($request)->orderBy('damaged_at')->get() : collect();
        $report = $request->query('report') ?: 'month';
        abort_unless(isset(DieDamageReportService::REPORTS[$report]), 422);
        $pivot = $service->pivot($records, $report);

        return view('formdie.damage.reports', compact('ready', 'records', 'report', 'pivot', 'service'));
    }

    public function export(Request $request, DieDamageReportService $service)
    {
        abort_unless($this->ready(), 503, 'กรุณาติดตั้งตาราง DIE Reports ก่อน');
        $records = $this->filtered($request)->orderBy('damaged_at')->orderBy('id')->get();
        $rows = [];
        $write = function (array $row) use (&$rows) {
        $rows[] = $row;
        };
        $report = $request->query('report');
        if ($report && $report !== 'weight') {
            $pivot = $service->pivot($records, $report);
            $write([$pivot['title'], ...$pivot['columns'], 'รวม']);
            foreach ($pivot['rows'] as $label => $cells) {
                $write([$label, ...array_map(fn ($c) => $cells[$c] ?? 0, $pivot['columns']), array_sum($cells)]);
            }
            $write(['รวมทั้งหมด', ...array_map(fn ($c) => array_sum(array_column($pivot['rows'], $c)), $pivot['columns']), $pivot['total']]);
        } else {
            $write(['ID', 'Site', 'วันที่เสีย', 'DIE CODE', ...array_values(DieDamageReportService::FIELDS), 'THB/Kg', 'Size Diff', 'หมายเหตุ']);
            foreach ($records as $r) {
                $write([$r->id, $r->site, $r->damaged_at->format('Y-m-d'), $r->die_code, ...array_map(fn ($f) => $r->$f !== null && in_array($f, DieDamageReportService::NUMBERS, true) ? (float) $r->$f : $r->$f, array_keys(DieDamageReportService::FIELDS)), ...array_values($service->metrics($r->toArray())), $r->notes]);
            }
        }

        return \Maatwebsite\Excel\Facades\Excel::download(new \App\Exports\FormDIE\DieDamageExport($rows), 'die-report-'.now()->format('Ymd-His').'.xlsx');
    }
}

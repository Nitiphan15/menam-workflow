<?php
// app/Http/Controllers/PaMasterController.php
namespace App\Http\Controllers\FormPA;

use App\Http\Controllers\Controller;

use App\Models\FormPA\PaSection;
use App\Models\FormPA\PaQuestion;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PaMasterController extends Controller
{
    public function index(Request $r)
    {
        $sections  = PaSection::orderBy('order_no')->get();
        $sectionId = (int) $r->query('section_id', $sections->first()->id ?? 0);

        $questions = $sectionId
            ? PaQuestion::where('section_id', $sectionId)->orderBy('order_no')->get()
            : collect();

        return view('formpa.master', compact('sections', 'questions', 'sectionId'));
    }

    /* ---------- Sections ---------- */

    public function sectionStore(Request $r)
    {

        $data = $r->validate([
            'code'      => 'required|string|max:20|unique:pa_sections,code',
            'name'      => 'required|string|max:200',
            'order_no'  => 'nullable|integer|min:1',
            'weight'    => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        if (empty($data['order_no'])) {
            $data['order_no'] = (int) (PaSection::max('order_no') + 1);
        }
        $data['weight']    = $data['weight'] ?? 1;
        $data['is_active'] = $r->boolean('is_active');
        //dd($data['is_active']);
        PaSection::create($data);
        return back()->with('ok', 'เพิ่มหัวข้อใหญ่แล้ว');
    }

    public function sectionUpdate(Request $r, PaSection $sec)
    {
        $data = $r->validate([
            'code'      => 'required|string|max:20|unique:pa_sections,code,' . $sec->id,
            'name'      => 'required|string|max:200',
            'order_no'  => 'required|integer|min:1',
            'weight'    => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);
        $data['is_active'] = $r->boolean('is_active');

        $sec->update($data);
        return back()->with('ok', 'อัปเดตหัวข้อใหญ่แล้ว');
    }

    public function sectionDestroy(PaSection $sec)
    {
        try {
            $sec->delete();
            return back()->with('ok', 'ลบหัวข้อใหญ่แล้ว');
        } catch (QueryException $e) {
            return back()->with('err', 'ลบไม่ได้: มีหัวข้อย่อยผูกอยู่ (หรือถูกใช้งาน)');
        }
    }

    public function sectionReorder(Request $r)
    {
        $orders = $r->input('orders', []); // ['id' => newOrderNo, ...]
        DB::transaction(function () use ($orders) {
            foreach ($orders as $id => $ord) {
                PaSection::where('id', (int)$id)->update(['order_no' => (int)$ord]);
            }
        });
        return back()->with('ok', 'บันทึกลำดับหัวข้อใหญ่แล้ว');
    }

    /* ---------- Questions ---------- */

    public function questionStore(Request $r)
    {
        $data = $r->validate([
            'section_id' => 'required|integer|exists:pa_sections,id',
            'text'      => 'required|string|max:500',
            'order_no'  => 'nullable|integer|min:1',
            'weight'    => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        if (empty($data['order_no'])) {
            $data['order_no'] = (int) (PaQuestion::where('section_id', $data['section_id'])->max('order_no') + 1);
        }
        $data['weight']    = $data['weight'] ?? 1;
        $data['is_active'] = $r->boolean('is_active');

        PaQuestion::create($data);
        return back()->with('ok', 'เพิ่มหัวข้อย่อยแล้ว')->with('section_id', $data['section_id']);
    }

    public function questionUpdate(Request $r, PaQuestion $q)
    {
        $data = $r->validate([
            'section_id' => 'required|integer|exists:pa_sections,id',
            'text'      => 'required|string|max:500',
            'order_no'  => 'required|integer|min:1',
            'weight'    => 'required|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);
        $data['is_active'] = $r->boolean('is_active');

        $q->update($data);
        return back()->with('ok', 'อัปเดตหัวข้อย่อยแล้ว')->with('section_id', $data['section_id']);
    }

    public function questionDestroy(PaQuestion $q)
    {
        try {
            $sid = $q->section_id;
            $q->delete();
            return back()->with('ok', 'ลบหัวข้อย่อยแล้ว')->with('section_id', $sid);
        } catch (QueryException $e) {
            return back()->with('err', 'ลบไม่ได้: มีคำตอบอ้างอิงอยู่');
        }
    }

    public function questionReorder(Request $r)
    {
        $orders = $r->input('orders', []);  // ['id' => newOrderNo]
        DB::transaction(function () use ($orders) {
            foreach ($orders as $id => $ord) {
                PaQuestion::where('id', (int)$id)->update(['order_no' => (int)$ord]);
            }
        });
        return back()->with('ok', 'บันทึกลำดับหัวข้อย่อยแล้ว')
            ->with('section_id', (int)$r->input('section_id'));
    }
}

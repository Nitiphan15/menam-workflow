<?php
// app/Http/Controllers/FormISR/InspectionDecisionController.php
namespace App\Http\Controllers\FormISR;

use App\Http\Controllers\Controller;
use App\Models\FormISR\IsrManualDecision;
use Illuminate\Http\Request;

class InspectionDecisionController extends Controller
{

    public function store(Request $request)
    {
        // ✅ สิทธิ์: ปรับตามระบบ role ของคุณได้
        abort_unless($this->canManualDecide(), 403, 'No permission');

        $data = $request->validate([
            'mfg_no'   => 'required|string|max:50',
            'wo_id'    => 'required|integer',
            'step_seq' => 'required|integer',
            'decision' => 'required|in:PASS,NG',
            'remark'   => 'nullable|string|max:2000',
        ]);

        IsrManualDecision::create([
            'mfg_no'     => $data['mfg_no'],
            'wo_id'      => $data['wo_id'],
            'step_seq'   => $data['step_seq'],
            'decision'   => $data['decision'],
            'remark'     => $data['remark'] ?? null,
            'decided_by' => auth()->id(),
            'decided_at' => now(),
        ]);

        return back()->with('success', 'บันทึกผลการตัดสินแบบ Manual เรียบร้อยแล้ว');
    }

    private function canManualDecide(): bool
    {
        $user = auth()->user();
        if (!$user) return false;

        // ตัวอย่าง: ให้คนที่มี deptRoles บาง role ทำได้
        // ปรับ field/ชื่อ role ให้ตรงกับของจริงคุณ
        return $user->deptRoles()
            ->where('dept_roles.code', 'ISR')
            ->exists();
    }
}

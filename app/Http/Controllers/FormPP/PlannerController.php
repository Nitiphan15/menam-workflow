<?php

namespace App\Http\Controllers\FormPP;

use App\Http\Controllers\Controller;

use App\Models\FormPP\PpData;
use App\Services\WorkflowEngine;
use App\Support\WorkflowDb;

use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlannerController extends Controller
{

    public function showPlanner($id)
    {
        $step = 2;
        $ppData = PpData::query()
            ->where('form_id', $id)
            ->firstOrFail();   // ไม่เจอ => 404
        $sku    = $ppData->part_no;
        //$customer = $ppData->customer;
        //if ($customer  == 'ทั้งหมด') {
        //    $customer = '';
        //}

        $rawCust   = $ppData->customer;
        // แปลง "ทั้งหมด" ให้เป็นค่าว่าง (เท่ากับไม่ฟิลเตอร์)
        if (is_string($rawCust) && trim($rawCust) === 'ทั้งหมด') {
            $rawCust = '';
        }

        //dd($id);
        $wfForm = WorkflowDb::findForm('pp', (int) $id);
        abort_unless($wfForm, 404);

        $history = WorkflowDb::historyWithActors('pp', (int) $wfForm->id);
        $canApprove = WorkflowDb::canApprove('pp', (int) $wfForm->id, (int) auth()->id());

        if ($wfForm->current_step_no != $step) {
            $canApprove = false;
        }
        $customers = $this->normalizeCustomers($rawCust);   // <- ได้เป็น array ที่สะอาดแล้ว

        /*$customers = is_array($customer)
            ? $customer
            : (is_string($customer) ? array_filter(array_map('trim', explode(',', $customer))) : []);

        $customers = array_values(array_filter($customers, fn($v) => $v !== '__ALL__' && $v !== ''));
*/
        // reuse logic index() เพื่อให้ได้ result/datasets/customers
        /*$request = Request::create('/pp', 'GET', [
            'sku'      => $sku,
            'customers' => (is_array($customer) && $customer !== []) ? $customer : $customers,
        ]);*/

        $request = Request::create('/pp', 'GET', [
            'sku'       => $sku,
            'customers' => $customers, // ส่งเป็น array ตรง ๆ
        ]);

        $skuSummary = app(ProductionPlanController::class)
            ->index($request)
            ->getData();



        return view('formpp.planner', [
            // ของเดิมที่ index ต้องการ
            'sku'              => $sku,
            'result'           => $skuSummary['result'] ?? null,
            'datasets'         => $skuSummary['datasets'] ?? [],
            'selectedSrc'      => $skuSummary['selectedSrc'] ?? [],

            // ตัวแปรสำหรับ "โหมด Planner"
            'plannerMode' => true,
            'ppData'      => $ppData,
            'form'        => $wfForm,
            'canApprove'  => $canApprove,
        ]);
    }

    /** แปลงค่า customers จาก string/array -> array ที่ปลอดภัย (กัน CO., LTD.) */
    private function normalizeCustomers($input): array
    {
        // helper รวม "CO." + "LTD." กลับเป็น "CO., LTD."
        $mergeCoLtd = function (array $parts): array {
            $out = [];
            for ($i = 0; $i < count($parts); $i++) {
                $cur = trim($parts[$i]);
                if (
                    isset($parts[$i + 1]) &&
                    preg_match('/\bCO\.$/i', $cur) &&
                    preg_match('/^LTD\.?$/i', trim($parts[$i + 1]))
                ) {
                    $out[] = $cur . ', ' . trim($parts[$i + 1]);
                    $i++;
                    continue;
                }
                $out[] = $cur;
            }
            return $out;
        };

        if (is_array($input)) {
            $items = array_map('trim', $input);
        } elseif (is_string($input)) {
            $s = trim($input);
            if ($s === '') return [];

            // พยายาม parse แบบ CSV ก่อน (รองรับกรณีมี "..." ครอบชื่อ)
            $items = array_map('trim', str_getcsv($s, ',', '"'));

            // ถ้าไม่มี quote เลย ให้รวม CO. + LTD. กลับ (กันแตกผิด)
            if (strpos($s, '"') === false) {
                $items = $mergeCoLtd($items);
            }
        } else {
            $items = [];
        }

        // กรองค่าพิเศษ / ค่าว่าง และ unique
        $items = array_values(array_unique(array_filter($items, fn($v) => $v !== '' && $v !== '__ALL__')));

        return $items;
    }

    public function planner_action(Request $request, $id)
    {
        $userId = $request->user()->id;

        //dd($request, $id);
        $data = $request->validate([
            // Planner input
            'planner_fg'        => 'nullable|numeric',
            'planner_wip'       => 'nullable|numeric',
            'planner_mfg'       => 'nullable|numeric',
            'planner_order'     => 'nullable|numeric',
            'planner_prod'      => 'nullable|numeric',
            'planner_rm'        => 'nullable|numeric',
        ]);

        $payload = [
            // Planner (ถ้ามีคอลัมน์เหล่านี้ในตาราง ให้เติมด้วย)
            'planner_fg'    => $data['planner_fg']    ?? null,
            'planner_wip'   => $data['planner_wip']   ?? null,
            'planner_mfg'   => $data['planner_mfg']   ?? null,
            'planner_order' => $data['planner_order'] ?? null,
            'planner_prod'  => $data['planner_prod']  ?? null,
            'planner_rm'    => $data['planner_rm']    ?? null,
        ];

        // ดึงฟอร์ม + เช็คสิทธิ์เป็นผู้อนุมัติของ step ปัจจุบัน
        $form = WorkflowDb::findForm('pp', (int) $id);
        abort_unless($form, 404);

        $auth = WorkflowDb::table('pp', 'wf_form_authorizes')
            ->where('wf_form_id', $form->id)
            ->where('step_no', $form->current_step_no)
            ->where('approver_user_id', $userId)
            ->first();

        if (!$auth) abort(403, 'คุณไม่มีสิทธิ์อนุมัติฟอร์มนี้');
        if (strtoupper($auth->status) !== 'PENDING') {
            return back()->with('warning', 'ฟอร์มนี้ถูกดำเนินการแล้ว')->withInput();
        }
        $action = strtolower((string) $request->input('action')); // 'approve' | 'reject'

        // dd($action);
        if ($action === 'reject') {
            // ต้องมีเหตุผลเวลา Reject
            $request->validate([
                'reason' => 'required|string|max:2000',
            ], [
                'reason.required' => 'กรุณาระบุเหตุผลการ Reject',
            ]);

            WorkflowEngine::reject((int)$form->id, (int)$userId, (string) $request->input('reason'), 'pp');

            return redirect()
                ->route('pp.view', ['id' => $id])
                ->with('ok', 'ปฏิเสธคำขอเรียบร้อย ');
            //return back()->with('success', 'ปฏิเสธคำขอเรียบร้อย');
        }

        if ($action === 'approve') {
            //dd($action, $payload, $id);
            // -------- UPDATE --------
            DB::table('pp_data')
                ->where('form_id', $id)
                ->update($payload);

            // comment เป็น optional
            $comment = (string) $request->input('reason', '');
            WorkflowEngine::approve((int)$form->id, (int)$userId, $comment, 'pp');

            return redirect()
                ->route('pp.view', ['id' => $id])
                ->with('ok', 'อนุมัติสำเร็จ');
            //return back()->with('success', 'อนุมัติสำเร็จ');
        }

        // action ไม่ถูกต้อง
        return back()->with('warning', 'ไม่รู้จักคำสั่งที่ส่งมา')->withInput();
    }
}

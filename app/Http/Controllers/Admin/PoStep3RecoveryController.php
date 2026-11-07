<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Po\PoStep3RecoveryService;
use Illuminate\Http\Request;
use RuntimeException;

class PoStep3RecoveryController extends Controller
{
    public function index(Request $request, PoStep3RecoveryService $service)
    {
        $ordnumber = trim((string) $request->query('ordnumber', ''));
        $userSearch = trim((string) $request->query('user_search', ''));
        $selectedUserId = $request->filled('approver_user_id') ? (int) $request->query('approver_user_id') : null;
        $result = null;
        $error = null;

        if ($ordnumber !== '') {
            try {
                $result = $service->inspect($ordnumber, $selectedUserId);
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            }
        }

        $users = $result && $userSearch !== ''
            ? $service->searchActiveUsers($userSearch)
            : collect();

        return view('adminweb.po_step3_recovery', compact(
            'ordnumber',
            'userSearch',
            'selectedUserId',
            'result',
            'users',
            'error',
        ));
    }

    public function send(Request $request, PoStep3RecoveryService $service)
    {
        $data = $request->validate([
            'ordnumber' => ['required', 'string', 'max:100'],
            'approver_user_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $result = $service->recoverAndSend(
                $data['ordnumber'],
                (int) $data['approver_user_id'],
                (int) $request->user()->id,
                $data['reason'],
            );
        } catch (RuntimeException $exception) {
            return back()->withInput()->withErrors(['ordnumber' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors([
                'ordnumber' => 'กู้ Step 3 หรือส่งเมลไม่สำเร็จ กรุณาตรวจ log ก่อนลองใหม่',
            ]);
        }

        $message = $result['delivery'] === 'already'
            ? 'รายการนี้เคยส่งเมลกู้ Step 3 แล้ว ระบบไม่ส่งซ้ำ'
            : 'กู้ PO กลับ Step 3 และส่งเมลให้ผู้อนุมัติแล้ว';

        return redirect()
            ->route('adminweb.po-step3-recovery.index', [
                'ordnumber' => $data['ordnumber'],
                'approver_user_id' => $data['approver_user_id'],
            ])
            ->with('ok', $message);
    }
}

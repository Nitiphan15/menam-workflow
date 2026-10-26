<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Po\RetroactivePoApprovalMailService;
use Illuminate\Http\Request;
use RuntimeException;

class PoRetroactiveMailController extends Controller
{
    public function index(Request $request, RetroactivePoApprovalMailService $service)
    {
        $ordnumber = trim((string) $request->query('ordnumber', ''));
        $result = null;
        $error = null;

        if ($ordnumber !== '') {
            try {
                $result = $service->inspect($ordnumber);
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            }
        }

        return view('adminweb.po_retroactive_mail', compact('ordnumber', 'result', 'error'));
    }

    public function send(Request $request, RetroactivePoApprovalMailService $service)
    {
        $data = $request->validate([
            'ordnumber' => ['required', 'string', 'max:100'],
        ]);

        try {
            $result = $service->send($data['ordnumber']);
        } catch (RuntimeException $exception) {
            return back()->withInput()->withErrors(['ordnumber' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            report($exception);
            return back()->withInput()->withErrors([
                'ordnumber' => 'ส่งเมลไม่สำเร็จ กรุณาตรวจ log หรือลองใหม่',
            ]);
        }

        $message = $result['delivery'] === 'already'
            ? 'รายการนี้เคยส่งเมลย้อนหลังแล้ว ระบบจึงไม่ส่งซ้ำ'
            : 'ส่งเมลย้อนหลังให้ Jittinan แล้ว';

        return redirect()
            ->route('adminweb.po-retroactive-mail.index', ['ordnumber' => $data['ordnumber']])
            ->with('ok', $message);
    }
}

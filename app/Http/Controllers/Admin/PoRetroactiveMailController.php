<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Po\RetroactivePoApprovalMailService;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\Mailer\Exception\ExceptionInterface as MailerException;

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
            // Manual reminders may be sent repeatedly while the existing approval is still pending.
            $result = $service->send($data['ordnumber'], allowDuplicate: true);
        } catch (\PDOException | MailerException $exception) {
            // Both extend RuntimeException, so they must be caught before the validation messages below.
            return $this->sendFailed($exception);
        } catch (RuntimeException $exception) {
            return back()->withInput()->withErrors(['ordnumber' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            return $this->sendFailed($exception);
        }

        $recipientNames = $result['sent_to']->map(fn ($approver) => $approver->name ?: $approver->username)->implode(', ');
        $message = "ส่งเมลให้ {$recipientNames} แล้ว โดยไม่เปลี่ยนสถานะหรือสร้างรายการอนุมัติใหม่";

        return redirect()
            ->route('adminweb.po-retroactive-mail.index', ['ordnumber' => $data['ordnumber']])
            ->with('ok', $message);
    }

    private function sendFailed(\Throwable $exception)
    {
        report($exception);

        return back()->withInput()->withErrors([
            'ordnumber' => 'ส่งเมลไม่สำเร็จ กรุณาตรวจ log หรือลองใหม่',
        ]);
    }
}

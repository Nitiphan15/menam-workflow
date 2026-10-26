<?php

namespace Tests\Unit;

use App\Mail\PoClosedNotificationMail;
use App\Mail\PoNeedsApprovalMail;
use App\Mail\PoSubmittedNotificationMail;
use Tests\TestCase;

class PoMailSenderNameTest extends TestCase
{
    /** @dataProvider poMailProvider */
    public function test_po_mail_uses_po_online_sender_name($mail): void
    {
        config(['mail.from.address' => 'sender@example.com']);

        $mail->build();

        $this->assertTrue($mail->hasFrom('sender@example.com', 'Menam PO Online'));
    }

    public static function poMailProvider(): array
    {
        return [
            'needs approval' => [new PoNeedsApprovalMail('Approver', [['ordnumber' => 'PO-001']])],
            'submitted' => [new PoSubmittedNotificationMail('Recipient', ['ordnumber' => 'PO-001'])],
            'closed' => [new PoClosedNotificationMail('Recipient', ['ordnumber' => 'PO-001'])],
        ];
    }
}

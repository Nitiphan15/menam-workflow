<?php

namespace Database\Seeders;

use App\Models\FormAccounting\BillingPlan;
use App\Models\FormAccounting\PaymentSchedule;
use Illuminate\Database\Seeder;

class AccountingMasterSeeder extends Seeder
{
    public function run(): void
    {
        $billingPlans = [
            [
                'code' => 'BILL_ON_DELIVERY',
                'name_th' => 'วางบิลพร้อมส่ง',
                'name_en' => 'Bill on delivery',
                'billing_day_from' => null,
                'billing_day_to' => null,
                'is_cash' => false,
            ],
            [
                'code' => 'BILL_BATCH_1_15',
                'name_th' => 'รวบวางบิลวันที่ 1-15',
                'name_en' => 'Batch billing day 1-15',
                'billing_day_from' => 1,
                'billing_day_to' => 15,
                'is_cash' => false,
            ],
            [
                'code' => 'BILL_BATCH_16_END',
                'name_th' => 'รวบวางบิลวันที่ 16-สิ้นเดือน',
                'name_en' => 'Batch billing day 16-end',
                'billing_day_from' => 16,
                'billing_day_to' => 31,
                'is_cash' => false,
            ],
            [
                'code' => 'CASH',
                'name_th' => 'เงินสด',
                'name_en' => 'Cash',
                'billing_day_from' => null,
                'billing_day_to' => null,
                'is_cash' => true,
            ],
        ];

        foreach ($billingPlans as $plan) {
            BillingPlan::updateOrCreate(
                ['code' => $plan['code']],
                $plan + ['is_active' => true]
            );
        }

        $paymentSchedules = [
            [
                'code' => 'END_OF_MONTH',
                'name_th' => 'โอนทุกสิ้นเดือน',
                'schedule_type' => 'end_of_month',
                'payment_day' => null,
            ],
            [
                'code' => 'DAY_10',
                'name_th' => 'โอนทุกวันที่ 10',
                'schedule_type' => 'fixed_day',
                'payment_day' => 10,
            ],
            [
                'code' => 'DAY_25',
                'name_th' => 'โอนทุกวันที่ 25',
                'schedule_type' => 'fixed_day',
                'payment_day' => 25,
            ],
            [
                'code' => 'IMMEDIATE',
                'name_th' => 'จ่ายทันทีหลังส่ง',
                'schedule_type' => 'immediate',
                'payment_day' => null,
            ],
        ];

        foreach ($paymentSchedules as $schedule) {
            PaymentSchedule::updateOrCreate(
                ['code' => $schedule['code']],
                $schedule + ['is_active' => true]
            );
        }
    }
}

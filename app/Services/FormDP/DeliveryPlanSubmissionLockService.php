<?php

namespace App\Services\FormDP;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

class DeliveryPlanSubmissionLockService
{
    private function conn(): ConnectionInterface
    {
        return DB::connection('sqlsrv_menam');
    }

    public function isLocked(string $shipDate): bool
    {
        $shipDate = Carbon::parse($shipDate)->toDateString();
        $lastMailId = $this->latestAssignMailId($shipDate);

        $lastControl = $this->conn()
            ->table('delivery_plan_sales_submission_controls')
            ->whereDate('ship_posted_at', $shipDate)
            ->orderByDesc('id')
            ->first(['action', 'mail_log_id']);

        if ($lastControl !== null) {
            $controlMailId = $lastControl->mail_log_id === null ? null : (int) $lastControl->mail_log_id;
            if ($lastMailId !== null && ($controlMailId === null || $lastMailId > $controlMailId)) {
                return true;
            }

            return strtoupper((string) $lastControl->action) === 'LOCK';
        }

        if ($lastMailId === null) {
            return false;
        }

        return ! $this->conn()
            ->table('delivery_plan_sales_submission_unlocks')
            ->whereDate('ship_posted_at', $shipDate)
            ->where('mail_log_id', $lastMailId)
            ->exists();
    }

    /**
     * Logistics กด "ปลดล็อกให้ Sales" ไว้สำหรับวันที่นี้ และยังไม่ถูกล็อกคืน / ยังไม่มีเมลจัดรถรอบใหม่
     * ใช้เปิดให้ Sales แก้รายการที่จัดรถแล้วได้ (ก่อนส่งเมลจัดรถ ยังต้องให้ Logistics ยกเลิกรถก่อนเหมือนเดิม)
     */
    public function isUnlockedForAssignedEdit(string $shipDate): bool
    {
        $shipDate = Carbon::parse($shipDate)->toDateString();

        if ($this->isLocked($shipDate)) {
            return false;
        }

        $lastControl = $this->conn()
            ->table('delivery_plan_sales_submission_controls')
            ->whereDate('ship_posted_at', $shipDate)
            ->orderByDesc('id')
            ->first(['action']);

        if ($lastControl !== null) {
            return strtoupper((string) $lastControl->action) === 'UNLOCK';
        }

        // ไม่มี control แต่ไม่ล็อก + มีเมลจัดรถ = ปลดล็อกแบบเดิม (ตาราง delivery_plan_sales_submission_unlocks)
        return $this->latestAssignMailId($shipDate) !== null;
    }

    private function latestAssignMailId(string $shipDate): ?int
    {
        $id = $this->conn()
            ->table('delivery_plan_mail_logs')
            ->where('mail_type', 'ASSIGN')
            ->whereDate('ship_posted_at', $shipDate)
            ->max('id');

        return $id === null ? null : (int) $id;
    }

    public function unlock(string $shipDate, ?int $userId, ?string $userName, ?string $remark = null): bool
    {
        $shipDate = Carbon::parse($shipDate)->toDateString();

        return $this->conn()->transaction(function () use ($shipDate, $userId, $userName, $remark) {
            if (! $this->isLocked($shipDate)) {
                return false;
            }

            $this->recordControl($shipDate, 'UNLOCK', $userId, $userName, $remark);

            return true;
        });
    }

    public function lock(string $shipDate, ?int $userId, ?string $userName, ?string $remark = null): bool
    {
        $shipDate = Carbon::parse($shipDate)->toDateString();

        return $this->conn()->transaction(function () use ($shipDate, $userId, $userName, $remark) {
            if ($this->isLocked($shipDate)) {
                return false;
            }

            $this->recordControl($shipDate, 'LOCK', $userId, $userName, $remark);

            return true;
        });
    }

    private function recordControl(string $shipDate, string $action, ?int $userId, ?string $userName, ?string $remark): void
    {
        $this->conn()->table('delivery_plan_sales_submission_controls')->insert([
                'ship_posted_at' => $shipDate,
                'action' => $action,
                'mail_log_id' => $this->latestAssignMailId($shipDate),
                'controlled_at' => now(),
                'controlled_by' => $userId,
                'controlled_by_name' => $userName,
                'remark' => $remark,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function lockedDates(?string $fromDate = null): array
    {
        $fromDate = Carbon::parse($fromDate ?: now())->toDateString();
        $dateKey = fn ($date) => Carbon::parse($date)->toDateString();
        $latestMailByDate = $this->conn()->table('delivery_plan_mail_logs')->where('mail_type', 'ASSIGN')
            ->get(['id', 'ship_posted_at'])->groupBy(fn ($row) => $dateKey($row->ship_posted_at))
            ->map(fn ($rows) => (int) $rows->max('id'));
        $latestControlByDate = $this->conn()->table('delivery_plan_sales_submission_controls')
            ->get(['id', 'ship_posted_at', 'action', 'mail_log_id'])
            ->groupBy(fn ($row) => $dateKey($row->ship_posted_at))
            ->map(fn ($rows) => $rows->sortByDesc('id')->first());
        $legacyUnlockMailIdsByDate = $this->conn()->table('delivery_plan_sales_submission_unlocks')
            ->get(['ship_posted_at', 'mail_log_id'])->groupBy(fn ($row) => $dateKey($row->ship_posted_at))
            ->map(fn ($rows) => $rows->pluck('mail_log_id')->map(fn ($id) => (int) $id)->all());

        return $latestMailByDate->keys()->merge($latestControlByDate->keys())->unique()
            ->filter(fn (string $date) => $date >= $fromDate)
            ->filter(function (string $date) use ($latestMailByDate, $latestControlByDate, $legacyUnlockMailIdsByDate) {
                $mailId = $latestMailByDate->get($date);
                $control = $latestControlByDate->get($date);
                if ($control !== null) {
                    $controlMailId = $control->mail_log_id === null ? null : (int) $control->mail_log_id;
                    if ($mailId !== null && ($controlMailId === null || $mailId > $controlMailId)) return true;
                    return strtoupper((string) $control->action) === 'LOCK';
                }
                return $mailId !== null && ! in_array((int) $mailId, $legacyUnlockMailIdsByDate->get($date, []), true);
            })->sort()->values()->all();
    }

    public function lockedMessage(string $shipDate): string
    {
        $dateText = Carbon::parse($shipDate)->format('d/m/Y');

        return "วันที่ส่งสินค้า {$dateText} ส่งเมลตารางจัดรถแล้ว Sales ไม่สามารถเพิ่มหรือแก้ไขข้อมูลได้ กรุณาให้ Logistics กดอนุญาต Sales Submit ก่อน";
    }
}

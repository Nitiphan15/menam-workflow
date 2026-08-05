<?php

namespace App\Support\FormWOS;

final class DeadstockReviewStatus
{
    public const NOT_FOLLOWED_UP = 'open';

    public const WAITING = 'waiting_follow_up';

    public const IN_PROGRESS = 'in_progress';

    public static function labels(): array
    {
        return [
            self::NOT_FOLLOWED_UP => 'ยังไม่ได้ติดตาม',
            self::WAITING => 'กำลังติดตาม รอคำตอบจากลูกค้า',
            self::IN_PROGRESS => 'มีกำหนดส่งมอบแล้ว',
        ];
    }

    public static function filterOptions(): array
    {
        return ['all' => 'ทุกสถานะติดตาม'] + self::labels();
    }

    public static function label(?string $status): string
    {
        $normalized = self::normalize($status);

        return self::labels()[$normalized] ?? trim((string) $status);
    }

    public static function normalize(?string $status): ?string
    {
        $key = self::key($status);

        if ($key === '') {
            return null;
        }

        return [
            'open' => self::NOT_FOLLOWED_UP,
            'notfollowedup' => self::NOT_FOLLOWED_UP,
            'ยังไม่เริ่ม' => self::NOT_FOLLOWED_UP,
            'ยังไม่มีงานติดตาม' => self::NOT_FOLLOWED_UP,
            'ยังไม่ได้มีการติดตาม' => self::NOT_FOLLOWED_UP,
            'ยังไม่ได้ติดตาม' => self::NOT_FOLLOWED_UP,

            'waitingfollowup' => self::WAITING,
            'waitingsales' => self::WAITING,
            'waitingcustomer' => self::WAITING,
            'waitingdelivery' => self::WAITING,
            'notdeliveredcurrentmonth' => self::WAITING,
            'รอติดตาม' => self::WAITING,
            'รอsales' => self::WAITING,
            'รอลูกค้า' => self::WAITING,
            'รอจัดส่ง' => self::WAITING,
            'ยังไม่ได้ส่งมอบเดือนปัจจุบัน' => self::WAITING,
            'กำลังติดตามรอคำตอบจากลูกค้า' => self::WAITING,

            'inprogress' => self::IN_PROGRESS,
            'followup' => self::IN_PROGRESS,
            'closed' => self::IN_PROGRESS,
            'กำลังติดตาม' => self::IN_PROGRESS,
            'ต้องติดตามต่อ' => self::IN_PROGRESS,
            'ปิดแล้ว' => self::IN_PROGRESS,
            'มีกำหนดส่งมอบแล้ว' => self::IN_PROGRESS,
        ][$key] ?? null;
    }

    public static function valuesForFilter(string $status): array
    {
        return match ($status) {
            self::NOT_FOLLOWED_UP => ['open'],
            self::WAITING => [
                self::WAITING,
                'waiting_sales',
                'waiting_customer',
                'waiting_delivery',
                'not_delivered_current_month',
            ],
            self::IN_PROGRESS => [
                self::IN_PROGRESS,
                'follow_up',
                'closed',
            ],
            default => [],
        };
    }

    private static function key(?string $status): string
    {
        return mb_strtolower((string) preg_replace('/[\s_\-]+/u', '', trim((string) $status)), 'UTF-8');
    }
}

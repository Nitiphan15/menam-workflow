<?php

namespace App\Models\FormDP;

use Illuminate\Database\Eloquent\Model;

class DeliveryConfirmation extends Model
{
    protected $connection = 'sqlsrv_menam';
    protected $table = 'dp_delivery_confirmation';

    public const STATUS_CONFIRM = 'CONFIRM';
    public const STATUS_POSTPONE = 'POSTPONE';
    public const STATUS_CANCELLED = 'CANCELLED';

    public $timestamps = true;

    protected $fillable = [
        'mfg_no',
        'site',
        'so_number',
        'confirmation_status',
        'original_ship_date',
        'new_delivery_date',
        'remark',
        'confirmed_by_id',
        'confirmed_by_login',
        'confirmed_by_name',
        'confirmed_at',
    ];

    protected $casts = [
        'original_ship_date' => 'date',
        'new_delivery_date' => 'date',
        'confirmed_at' => 'datetime',
    ];

    public static function statusLabel(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            self::STATUS_CONFIRM => 'Confirm Delivery',
            self::STATUS_POSTPONE => 'Request Postpone',
            self::STATUS_CANCELLED => 'ยกเลิกการยืนยัน',
            default => '-',
        };
    }

    public static function statusBadgeClass(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            self::STATUS_CONFIRM => 'success',
            self::STATUS_POSTPONE => 'warning',
            self::STATUS_CANCELLED => 'danger',
            default => 'light text-dark border',
        };
    }

    public static function isActiveStatus(?string $status): bool
    {
        return in_array(strtoupper((string) $status), [
            self::STATUS_CONFIRM,
            self::STATUS_POSTPONE,
        ], true);
    }
}

<?php

namespace App\Models\FormAccounting;

use Illuminate\Database\Eloquent\Model;

class PaymentSchedule extends Model
{
    protected $table = 'mst_payment_schedules';

    protected $fillable = [
        'code',
        'name_th',
        'schedule_type',
        'payment_day',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}

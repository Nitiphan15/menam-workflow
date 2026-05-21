<?php

namespace App\Models\FormAccounting;

use Illuminate\Database\Eloquent\Model;

class BillingPlan extends Model
{
    protected $table = 'mst_billing_plans';

    protected $fillable = [
        'code',
        'name_th',
        'name_en',
        'billing_day_from',
        'billing_day_to',
        'is_cash',
        'is_active',
    ];

    protected $casts = [
        'is_cash' => 'boolean',
        'is_active' => 'boolean',
    ];
}

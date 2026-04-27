<?php

namespace App\Models\FormOTD;

use Illuminate\Database\Eloquent\Model;

class DeliveryPlanLine extends Model
{
    protected $table = 'delivery_plan_lines';
    protected $connection = 'sqlsrv';

    public $timestamps = true;

    protected $fillable = [
        'plan_date',
        'customer_name',
        'package_name',
        'product_type',
        'size_length',
        'mfg_no',
        'qty_marketing',
        'qty_inventory',
        'qty_production',
        'qty_delivery',
        'address',
        'sales_order_no',
        'tel',
        'logistic',
        'remark_problem',
        'revise_count',
        'row_version',
        'active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'plan_date' => 'date',
        'active' => 'boolean',
        'qty_marketing' => 'decimal:2',
        'qty_inventory' => 'decimal:2',
        'qty_production' => 'decimal:2',
        'qty_delivery' => 'decimal:2',
    ];
}

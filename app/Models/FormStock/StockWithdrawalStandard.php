<?php

namespace App\Models\FormStock;

use Illuminate\Database\Eloquent\Model;

class StockWithdrawalStandard extends Model
{
    protected $connection = 'sqlsrv_menam';
    protected $table = 'stock_withdrawal_standards';

    protected $fillable = [
        'site', 'partnumber', 'description', 'standard_days', 'warning_days',
        'day_type', 'responsible_name', 'responsible_email', 'effective_from',
        'effective_to', 'is_active', 'remark', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'standard_days' => 'integer', 'warning_days' => 'integer',
        'effective_from' => 'date', 'effective_to' => 'date', 'is_active' => 'boolean',
    ];
}

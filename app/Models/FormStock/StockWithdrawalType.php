<?php

namespace App\Models\FormStock;

use Illuminate\Database\Eloquent\Model;

class StockWithdrawalType extends Model
{
    protected $connection = 'sqlsrv_menam';
    protected $table = 'stock_withdrawal_types';
    protected $fillable = ['code', 'name', 'lead_time_days', 'effective_from', 'effective_to', 'is_active', 'remark', 'created_by', 'updated_by'];
    protected $casts = ['lead_time_days' => 'integer', 'effective_from' => 'date', 'effective_to' => 'date', 'is_active' => 'boolean'];
}

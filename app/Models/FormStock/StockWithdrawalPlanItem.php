<?php

namespace App\Models\FormStock;

use Illuminate\Database\Eloquent\Model;

class StockWithdrawalPlanItem extends Model
{
    protected $connection = 'sqlsrv_menam';
    protected $table = 'stock_withdrawal_plan_items';
    protected $guarded = [];
    protected $casts = ['recommended_withdraw_date' => 'date', 'planned_withdraw_date' => 'date', 'lead_time_days' => 'integer'];

    public function plan() { return $this->belongsTo(StockWithdrawalPlan::class, 'stock_withdrawal_plan_id'); }
    public function type() { return $this->belongsTo(StockWithdrawalType::class, 'stock_withdrawal_type_id'); }
}

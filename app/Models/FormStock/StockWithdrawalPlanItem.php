<?php

namespace App\Models\FormStock;

use Illuminate\Database\Eloquent\Model;

class StockWithdrawalPlanItem extends Model
{
    protected $connection = 'sqlsrv_menam';
    protected $table = 'stock_withdrawal_plan_items';
    protected $guarded = [];
    protected $casts = ['recommended_withdraw_date' => 'date', 'planned_withdraw_date' => 'date', 'request_date' => 'date', 'queue_date' => 'date', 'original_queue_date' => 'date', 'planner_closed_at' => 'datetime', 'special_mail_sent_at' => 'datetime', 'erp_synced_at' => 'datetime', 'lead_time_days' => 'integer', 'plan_section' => 'integer', 'queue_position' => 'integer', 'quantity' => 'decimal:4', 'withdraw_quantity' => 'decimal:4', 'erp_withdrawn_qty' => 'decimal:4', 'erp_remaining_qty' => 'decimal:4', 'is_split' => 'boolean', 'is_special' => 'boolean', 'split_quantities' => 'array'];

    public function plan() { return $this->belongsTo(StockWithdrawalPlan::class, 'stock_withdrawal_plan_id'); }
    public function type() { return $this->belongsTo(StockWithdrawalType::class, 'stock_withdrawal_type_id'); }

    public function getUnitLabelAttribute(): string
    {
        return match (strtoupper(trim((string) $this->unit))) {
            '01' => 'KG.',
            default => trim((string) $this->unit),
        };
    }
}

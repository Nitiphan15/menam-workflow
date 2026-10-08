<?php

namespace App\Models\FormStock;

use Illuminate\Database\Eloquent\Model;

class StockWithdrawalPlan extends Model
{
    protected $connection = 'sqlsrv_menam';
    protected $table = 'stock_withdrawal_plans';
    protected $guarded = [];
    protected $casts = ['delivery_date' => 'date', 'production_date' => 'date', 'quantity' => 'decimal:4'];

    public function items() { return $this->hasMany(StockWithdrawalPlanItem::class); }
}

<?php

namespace App\Models\FormAccounting;

use App\Models\Traits\Blameable;
use Illuminate\Database\Eloquent\Model;

class CustomerPaymentTerm extends Model
{
    use Blameable;

    protected $table = 'customer_payment_terms';

    protected $fillable = [
        'erp_source',
        'customer_code',
        'customer_name',
        'erp_terms',
        'credit_days',
        'credit_term_code',
        'credit_term_detail',
        'billing_plan_id',
        'payment_schedule_id',
        'override_payment_day',
        'remark',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function billingPlan()
    {
        return $this->belongsTo(BillingPlan::class, 'billing_plan_id');
    }

    public function paymentSchedule()
    {
        return $this->belongsTo(PaymentSchedule::class, 'payment_schedule_id');
    }

    public function histories()
    {
        return $this->hasMany(CustomerPaymentTermHistory::class, 'customer_payment_term_id');
    }
}

<?php

namespace App\Models\FormAccounting;

use Illuminate\Database\Eloquent\Model;

class CustomerPaymentTermHistory extends Model
{
    protected $table = 'customer_payment_term_history';

    public $timestamps = false;

    protected $fillable = [
        'customer_payment_term_id',
        'before_data',
        'after_data',
        'action',
        'changed_by',
        'changed_at',
    ];

    protected $casts = [
        'before_data' => 'array',
        'after_data' => 'array',
        'changed_at' => 'datetime',
    ];

    public function paymentTerm()
    {
        return $this->belongsTo(CustomerPaymentTerm::class, 'customer_payment_term_id');
    }
}

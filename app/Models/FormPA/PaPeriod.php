<?php

namespace App\Models\FormPA;

use Illuminate\Database\Eloquent\Model;

class PaPeriod extends Model
{
    protected $table = 'pa_periods';
    protected $fillable = ['code', 'name', 'year_no', 'start_date', 'end_date', 'is_active'];

    // ให้ start_date/end_date กลายเป็น Carbon และ is_active เป็น boolean
    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'is_active'  => 'boolean',
    ];
}

<?php

namespace App\Models\FormExam;

use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\Blameable;

class Category extends Model
{
    use Blameable;

    protected $table = 'exam_categories';

    protected $fillable = [
        'exam_form_id',
        'name',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];


    public function creator()
    {
        return $this->belongsTo(\App\Models\Users\User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(\App\Models\Users\User::class, 'updated_by');
    }
}

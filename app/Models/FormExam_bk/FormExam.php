<?php

namespace App\Models\FormExam;

use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\Blameable;

class FormExam extends Model
{
    use Blameable;

    protected $table = 'exam_forms';

    protected $fillable = [
        'name',
        'description',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function examTypes()
    {
        return $this->hasMany(ExamType::class, 'exam_form_id');
    }

    public function categories()
    {
        return $this->hasMany(Category::class, 'exam_form_id');
    }

    public function questions()
    {
        return $this->hasMany(Question::class, 'exam_form_id');
    }

    // scope เอางานที่ active
    public function scopeActive($q)
    {
        return $q->where('is_active', 1);
    }

    public function creator()
    {
        return $this->belongsTo(\App\Models\Users\User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(\App\Models\Users\User::class, 'updated_by');
    }
}

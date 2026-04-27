<?php

namespace App\Models\FormExam;

use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\Blameable;

class ExamType extends Model
{
    use Blameable;

    protected $table = 'exam_types';

    protected $fillable = [
        'exam_form_id',
        'code',
        'name',
        'pass_score',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function formExam()
    {
        return $this->belongsTo(FormExam::class, 'exam_form_id');
    }

    public function creator()
    {
        return $this->belongsTo(\App\Models\Users\User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(\App\Models\Users\User::class, 'updated_by');
    }

    public function questions()
    {
        return $this->hasMany(Question::class, 'exam_type_id');
    }
}

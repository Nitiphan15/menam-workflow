<?php

namespace App\Models\FormExam;

use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\Blameable;

class ExamSession extends Model
{
    use Blameable;

    protected $table = 'exam_sessions';

    protected $fillable = [
        'exam_form_id',
        'exam_type_id',
        'full_name',
        'total_score',
        'max_score',
        'is_pass',
        'submitted_at',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'is_pass'      => 'boolean',
        'is_active'    => 'boolean',
    ];

    public function examType()
    {
        return $this->belongsTo(ExamType::class, 'exam_type_id');
    }

    public function formExam()
    {
        return $this->belongsTo(FormExam::class, 'exam_form_id');
    }

    public function answers()
    {
        return $this->hasMany(ExamAnswer::class, 'exam_session_id');
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

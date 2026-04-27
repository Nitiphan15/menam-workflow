<?php

namespace App\Models\FormExam;

use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\Blameable;

class ExamAnswer extends Model
{
    use Blameable;

    protected $table = 'exam_answers';

    protected $fillable = [
        'exam_session_id',
        'exam_question_id',
        'exam_choice_id',
        'is_correct',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_correct' => 'boolean',
        'is_active'  => 'boolean',
    ];

    public function question()
    {
        return $this->belongsTo(Question::class, 'exam_question_id');
    }

    public function choice()
    {
        return $this->belongsTo(Choice::class, 'exam_choice_id');
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

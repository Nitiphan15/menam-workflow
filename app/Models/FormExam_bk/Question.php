<?php

namespace App\Models\FormExam;

use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\Blameable;

class Question extends Model
{
    use Blameable;

    protected $table = 'exam_questions';

    protected $fillable = [
        'exam_form_id',
        'exam_category_id',
        'question_text',
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

    public function category()
    {
        return $this->belongsTo(Category::class, 'exam_category_id');
    }

    // exam_choices.exam_question_id
    public function choices()
    {
        return $this->hasMany(Choice::class, 'exam_question_id');
    }

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

    public function examType()
    {
        return $this->belongsTo(ExamType::class, 'exam_type_id');
    }
}

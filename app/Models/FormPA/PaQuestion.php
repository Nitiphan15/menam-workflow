<?php

// app/Models/PaQuestion.php
namespace App\Models\FormPA;

use Illuminate\Database\Eloquent\Model;

class PaQuestion extends Model
{
    protected $table = 'pa_questions';
    protected $fillable = ['section_id', 'order_no', 'text', 'weight', 'is_active'];

    public function section()
    {
        return $this->belongsTo(PaSection::class, 'section_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', 1);
    }
}

<?php
// app/Models/PA/PaScore.php
namespace App\Models\FormPA;

use Illuminate\Database\Eloquent\Model;

class PaScore extends Model
{
    protected $table = 'pa_scores';
    public $timestamps = false;

    protected $fillable = [
        'pa_data_id',
        'period_id',
        'employee_id',
        'reviewer_id',
        'section_id',
        'question_id',
        'score',
        'weight',
        'comment'
    ];
}

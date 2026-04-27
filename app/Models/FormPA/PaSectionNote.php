<?php

namespace App\Models\FormPA;

use Illuminate\Database\Eloquent\Model;

class PaSectionNote extends Model
{
    protected $table = 'pa_section_notes';
    protected $fillable = ['pa_data_id', 'section_id', 'sum_wx', 'sum_w', 'avg', 'note'];
}

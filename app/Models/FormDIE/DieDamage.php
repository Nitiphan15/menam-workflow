<?php

namespace App\Models\FormDIE;

use App\Models\Concerns\UsesWorkflowConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DieDamage extends Model
{
    use UsesWorkflowConnection, SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = ['damaged_at' => 'date', 'source_snapshot' => 'array'];
}

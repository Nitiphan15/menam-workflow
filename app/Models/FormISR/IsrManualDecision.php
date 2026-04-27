<?php

namespace App\Models\FormISR;

use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\Blameable;
use App\Models\Users\User;

class IsrManualDecision extends Model
{
    protected $fillable = [
        'mfg_no',
        'wo_id',
        'step_seq',
        'decision',
        'remark',
        'decided_by',
        'decided_at'
    ];

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}

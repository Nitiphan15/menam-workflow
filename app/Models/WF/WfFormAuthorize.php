<?php

namespace App\Models\WF;

use App\Models\Concerns\UsesWorkflowConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Users\User;

class WfFormAuthorize extends Model
{
    use UsesWorkflowConnection;

    protected $table = 'wf_form_authorizes';
    public $timestamps = false;

    public const ST_PENDING  = 'PENDING';
    public const ST_APPROVED = 'APPROVED';
    public const ST_REJECTED = 'REJECTED';
    public const ST_SKIPPED  = 'SKIPPED';

    protected $fillable = [
        'wf_form_id',
        'step_no',
        'approver_user_id',
        'status',
        'decided_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'wf_form_id' => 'integer',
        'step_no' => 'integer',
        'approver_user_id' => 'integer',
        'decided_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /* -------- Relations -------- */
    public function form()
    {
        return $this->belongsTo(WfForm::class, 'wf_form_id');
    }
    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    /* -------- Scopes -------- */
    public function scopeStep(Builder $q, int $step): Builder
    {
        return $q->where('step_no', $step);
    }

    public function scopeOfUser(Builder $q, int $userId): Builder
    {
        return $q->where('approver_user_id', $userId);
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', self::ST_PENDING);
    }

    public function scopeApproved(Builder $q): Builder
    {
        return $q->where('status', self::ST_APPROVED);
    }

    /* -------- Helper (ทางเลือก) -------- */
    public function approve(string $comment = null): void
    {
        $this->status = self::ST_APPROVED;
        $this->decided_at = now();
        $this->updated_at = now();
        $this->save();

        WfActionHistory::create([
            'wf_form_id'    => $this->wf_form_id,
            'step_no'       => $this->step_no,
            'actor_user_id' => $this->approver_user_id,
            'action_type'   => 'APPROVE',
            'comment'       => $comment,
            'created_at'    => now(),
        ]);
    }
}

<?php

namespace App\Models\WF;

use App\Models\Concerns\UsesWorkflowConnection;
use App\Models\Users\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WfForm extends Model
{
    use UsesWorkflowConnection;

    protected $table = 'wf_forms';
    public $timestamps = false;

    public const ST_DRAFT = 'DRAFT';
    public const ST_REQUESTED = 'REQUESTED';
    public const ST_APPROVED = 'APPROVED';
    public const ST_REJECTED = 'REJECTED';
    public const ST_CANCELLED = 'CANCELLED';
    public const ST_CLOSED = 'CLOESD';

    protected $fillable = [
        'app_code',
        'ref_type',
        'ref_id',
        'form_no',
        'form_status',
        'current_step_no',
        'request_by_user_id',
        'request_dt',
        'last_action_dt',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'form_status' => 'string',
        'current_step_no' => 'integer',
        'request_dt' => 'datetime',
        'last_action_dt' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'request_by_user_id');
    }

    public function ref()
    {
        return $this->morphTo(__FUNCTION__, 'ref_type', 'ref_id');
    }

    public function authorizes()
    {
        return $this->hasMany(WfFormAuthorize::class, 'wf_form_id');
    }

    public function histories()
    {
        return $this->hasMany(WfActionHistory::class, 'wf_form_id')->orderBy('id');
    }

    public function currentAuthorizes()
    {
        return $this->hasMany(WfFormAuthorize::class, 'wf_form_id')
            ->where('step_no', $this->current_step_no);
    }

    public function authorizesCurrentStep()
    {
        return $this->hasMany(WfFormAuthorize::class, 'wf_form_id')
            ->whereColumn('step_no', 'wf_forms.current_step_no');
    }

    public function canApprove(int $userId): bool
    {
        return $this->currentAuthorizes()
            ->where('approver_user_id', $userId)
            ->where('status', WfFormAuthorize::ST_PENDING)
            ->exists();
    }

    public function scopeApp(Builder $q, string $code): Builder
    {
        return $q->where('app_code', $code);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNotIn('form_status', [self::ST_CLOSED, self::ST_CANCELLED]);
    }

    public function scopeAwaitingMyApproval(Builder $q, int $userId): Builder
    {
        return $q->whereIn('id', function ($sub) use ($userId) {
            $sub->select('wf_form_id')->from('wf_form_authorizes')
                ->whereColumn('wf_form_authorizes.step_no', 'wf_forms.current_step_no')
                ->where('approver_user_id', $userId)
                ->where('status', WfFormAuthorize::ST_PENDING);
        });
    }

    public function isComplete(): bool
    {
        return (string) $this->form_status === self::ST_CLOSED;
    }

    public function isVoid(): bool
    {
        return (string) $this->form_status === self::ST_CANCELLED;
    }

    public function isTerminal(): bool
    {
        return $this->isComplete() || $this->isVoid();
    }

    public function getStatusTextAttribute(): string
    {
        return strtoupper((string) $this->form_status);
    }
}

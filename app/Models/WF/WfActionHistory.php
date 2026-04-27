<?php

namespace App\Models\WF;

use App\Models\Concerns\UsesWorkflowConnection;
use Illuminate\Database\Eloquent\Model;
use App\Models\Users\User;


class WfActionHistory extends Model
{
    use UsesWorkflowConnection;

    protected $table = 'wf_action_histories';
    public $timestamps = false;

    // ตัวอย่าง action
    public const ACT_SUBMIT   = 'SUBMIT';
    public const ACT_ASSIGN   = 'ASSIGN';
    public const ACT_APPROVE  = 'APPROVE';
    public const ACT_REJECT   = 'REJECT';
    public const ACT_RETURN   = 'RETURN';
    public const ACT_CANCEL   = 'CANCEL';
    public const ACT_SKIP     = 'SKIP';
    public const ACT_COMPLETE = 'COMPLETE';

    protected $fillable = [
        'wf_form_id',
        'step_no',
        'actor_user_id',
        'action_type',
        'comment',
        'created_at',
    ];

    protected $casts = [
        'wf_form_id'    => 'integer',
        'step_no'       => 'integer',
        'actor_user_id' => 'integer',
        'created_at'    => 'datetime',
    ];

    /* -------- Relations -------- */
    public function form()
    {
        return $this->belongsTo(WfForm::class, 'wf_form_id');
    }
    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /* -------- Factory helpers (เรียกง่าย ๆ) -------- */
    public static function logSubmit(int $wfId, int $actorId, ?string $msg = null): void
    {
        self::create(['wf_form_id' => $wfId, 'step_no' => 0, 'actor_user_id' => $actorId, 'action_type' => self::ACT_SUBMIT, 'comment' => $msg, 'created_at' => now()]);
    }

    public static function logSkip(int $wfId, int $step, int $actorId, ?string $msg = null): void
    {
        self::create(['wf_form_id' => $wfId, 'step_no' => $step, 'actor_user_id' => $actorId, 'action_type' => self::ACT_SKIP, 'comment' => $msg, 'created_at' => now()]);
    }

    public static function logApprove(int $wfId, int $step, int $actorId, ?string $msg = null): void
    {
        self::create(['wf_form_id' => $wfId, 'step_no' => $step, 'actor_user_id' => $actorId, 'action_type' => self::ACT_APPROVE, 'comment' => $msg, 'created_at' => now()]);
    }

    public static function logReject(int $wfId, int $step, int $actorId, string $msg): void
    {
        self::create(['wf_form_id' => $wfId, 'step_no' => $step, 'actor_user_id' => $actorId, 'action_type' => self::ACT_REJECT, 'comment' => $msg, 'created_at' => now()]);
    }

    public static function logCancel(int $wfId, int $step, int $actorId, ?string $msg = null): void
    {
        self::create(['wf_form_id' => $wfId, 'step_no' => $step, 'actor_user_id' => $actorId, 'action_type' => self::ACT_CANCEL, 'comment' => $msg, 'created_at' => now()]);
    }

    public static function logComplete(int $wfId, int $step, int $actorId, ?string $msg = null): void
    {
        self::create(['wf_form_id' => $wfId, 'step_no' => $step, 'actor_user_id' => $actorId, 'action_type' => self::ACT_COMPLETE, 'comment' => $msg, 'created_at' => now()]);
    }
}

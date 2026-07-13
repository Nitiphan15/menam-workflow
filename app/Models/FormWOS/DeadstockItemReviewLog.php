<?php

namespace App\Models\FormWOS;

use App\Models\Concerns\UsesWorkflowConnection;
use App\Models\Users\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeadstockItemReviewLog extends Model
{
    use UsesWorkflowConnection;

    protected $table = 'ds_item_review_logs';

    protected $guarded = [];

    protected $casts = [
        'changed_fields' => 'array',
        'before_values' => 'array',
        'after_values' => 'array',
        'changed_at' => 'datetime',
    ];

    public function snapshotItem(): BelongsTo
    {
        return $this->belongsTo(DeadstockSnapshotItem::class, 'snapshot_item_id');
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(DeadstockItemReview::class, 'review_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}

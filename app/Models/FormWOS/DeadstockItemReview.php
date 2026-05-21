<?php

namespace App\Models\FormWOS;

use App\Models\User;
use App\Models\Concerns\UsesWorkflowConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeadstockItemReview extends Model
{
    use UsesWorkflowConnection;

    protected $table = 'ds_item_reviews';

    protected $guarded = [];

    protected $casts = [
        'revised_due_date' => 'date',
        'next_follow_up_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function snapshotItem(): BelongsTo
    {
        return $this->belongsTo(DeadstockSnapshotItem::class, 'snapshot_item_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}

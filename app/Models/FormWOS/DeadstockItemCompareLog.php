<?php

namespace App\Models\FormWOS;

use App\Models\Concerns\UsesWorkflowConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeadstockItemCompareLog extends Model
{
    use UsesWorkflowConnection;

    protected $table = 'ds_item_compare_logs';

    protected $guarded = [];

    protected $casts = [
        'checked_at' => 'datetime',
        'matched_qty' => 'decimal:4',
        'matched_due_date' => 'date',
    ];

    public function snapshotItem(): BelongsTo
    {
        return $this->belongsTo(DeadstockSnapshotItem::class, 'snapshot_item_id');
    }
}

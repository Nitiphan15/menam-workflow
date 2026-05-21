<?php

namespace App\Models\FormWOS;

use App\Models\Concerns\UsesWorkflowConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DeadstockSnapshotItem extends Model
{
    use UsesWorkflowConnection;

    protected $table = 'ds_snapshot_items';

    protected $guarded = [];

    protected $casts = [
        'purchase_date' => 'date',
        'status_time' => 'datetime',
        'snapshot_qty' => 'decimal:4',
        'unitcost' => 'decimal:4',
        'snapshot_value' => 'decimal:4',
        'due_date' => 'date',
        'days_diff' => 'integer',
        'days_overdue' => 'integer',
        'dead_stock_flag' => 'boolean',
        'current_qty' => 'decimal:4',
        'current_due_date' => 'date',
        'last_checked_at' => 'datetime',
        'cleared_at' => 'datetime',
    ];

    public function snapshotMonth(): BelongsTo
    {
        return $this->belongsTo(DeadstockSnapshotMonth::class, 'snapshot_month_id');
    }

    public function review(): HasOne
    {
        return $this->hasOne(DeadstockItemReview::class, 'snapshot_item_id');
    }

    public function compareLogs(): HasMany
    {
        return $this->hasMany(DeadstockItemCompareLog::class, 'snapshot_item_id');
    }

    public function latestCompareLog(): HasOne
    {
        return $this->hasOne(DeadstockItemCompareLog::class, 'snapshot_item_id')->latestOfMany();
    }
}

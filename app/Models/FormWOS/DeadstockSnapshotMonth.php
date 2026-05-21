<?php

namespace App\Models\FormWOS;

use App\Models\Concerns\UsesWorkflowConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeadstockSnapshotMonth extends Model
{
    use UsesWorkflowConnection;

    protected $table = 'ds_snapshot_months';

    protected $guarded = [];

    protected $casts = [
        'snapshot_month' => 'date',
        'recv_date' => 'date',
        'as_of_date' => 'date',
        'captured_at' => 'datetime',
        'total_qty' => 'decimal:4',
        'total_value' => 'decimal:4',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(DeadstockSnapshotItem::class, 'snapshot_month_id');
    }
}

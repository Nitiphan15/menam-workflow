<?php

namespace App\Models\Po;

use App\Models\WF\WfForm;
use Illuminate\Database\Eloquent\Model;

class PoHeader extends Model
{
    protected $connection = 'sqlsrv_menam';
    protected $table = 'po_headers';

    public $timestamps = false;

    protected $fillable = [
        'workflow_id',
        'site',
        'ordnumber',
        'invnumber',
        'transdate',
        'reqdate',
        'amount',
        'netamount',
        'curr',
        'terms',
        'notes',
        'f1',
        'f3',
        'vendor_name',
        'requester_name',
        'status_code',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by',
    ];

    protected $casts = [
        'transdate' => 'date',
        'reqdate' => 'date',
        'amount' => 'decimal:2',
        'netamount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function getSiteAttribute($value): string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['wire', 'plus'], true) ? $normalized : 'wire';
    }

    public function attachments()
    {
        return $this->hasMany(PoAttached::class, 'po_header_id');
    }

    public function workflow()
    {
        return $this->belongsTo(WfForm::class, 'workflow_id');
    }
}

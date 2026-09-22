<?php

namespace App\Models\Po;

use App\Models\Users\User;
use Illuminate\Database\Eloquent\Model;

class PoAttached extends Model
{
    protected $connection = 'sqlsrv_menam';
    protected $table = 'po_attached';

    public $timestamps = false;

    protected $fillable = [
        'po_header_id',
        'file_name',
        'file_path',
        'file_ext',
        'mime_type',
        'file_size',
        'remark',
        'workflow_step_no',
        'created_at',
        'created_by',
    ];

    protected $casts = [
        'workflow_step_no' => 'integer',
        'created_at' => 'datetime',
    ];

    public function header()
    {
        return $this->belongsTo(PoHeader::class, 'po_header_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

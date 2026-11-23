<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ค่าก่อน/หลังการแก้ไขข้อมูลสำคัญ (เขียนผ่าน App\Services\Audit\AuditTrail เท่านั้น)
 */
class UserAuditChange extends Model
{
    protected $connection = 'sqlsrv_menam';

    protected $table = 'user_audit_changes';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'user_name',
        'entity_type',
        'entity_id',
        'entity_label',
        'event',
        'changes',
        'route_name',
        'ip_address',
        'changed_at',
    ];

    protected $casts = [
        'changes'    => 'array',
        'changed_at' => 'datetime',
    ];
}

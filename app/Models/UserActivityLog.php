<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserActivityLog extends Model
{
    protected $connection = 'sqlsrv_menam';

    protected $table = 'user_activity_logs';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'user_name',
        'is_authenticated',
        'menu_name',
        'route_name',
        'url_path',
        'method',
        'action_type',
        'status_code',
        'ip_address',
        'user_agent',
        'accessed_at',
    ];

    protected $casts = [
        'is_authenticated' => 'boolean',
        'status_code'      => 'integer',
        'accessed_at'      => 'datetime',
    ];
}

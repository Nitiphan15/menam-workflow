<?php

namespace App\Models\Users;

use App\Models\Concerns\UsesWorkflowConnection;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use UsesWorkflowConnection;

    protected $fillable = [
        'code',
        'name',
        'parent_id',
        'site_code',
        'cost_center',
        'manager_user_id',
        'is_active',
    ];

    protected $casts = [
        'parent_id'       => 'integer',
        'manager_user_id' => 'integer',
        'is_active'       => 'boolean',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];
}

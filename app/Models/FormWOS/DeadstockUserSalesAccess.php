<?php

namespace App\Models\FormWOS;

use App\Models\Concerns\UsesWorkflowConnection;
use App\Models\Users\User;
use Illuminate\Database\Eloquent\Model;

class DeadstockUserSalesAccess extends Model
{
    use UsesWorkflowConnection;

    protected $table = 'ds_user_sales_access';

    protected $fillable = [
        'user_id',
        'salesperson_key',
        'can_import',
        'can_edit',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'can_import' => 'boolean',
        'can_edit' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

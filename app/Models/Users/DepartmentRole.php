<?php

namespace App\Models\Users;

use App\Models\Concerns\UsesWorkflowConnection;
use Illuminate\Database\Eloquent\Model;

class DepartmentRole extends Model
{
    use UsesWorkflowConnection;

    public function users()
    {
        return $this->belongsToMany(User::class, 'department_role_users')
            ->withPivot('is_primary', 'start_date', 'end_date')
            ->withTimestamps();
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function scopeLevel($q, int $levelNo)
    {
        return $q->where('level_no', $levelNo);
    }
}

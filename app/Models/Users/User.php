<?php

namespace App\Models\Users;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\UsesWorkflowConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Users\DeptRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Foundation\Auth\Access\Authorizable;


class User extends Authenticatable implements AuthorizableContract
{
    use HasApiTokens, HasFactory, Notifiable,   Authorizable;
    use UsesWorkflowConnection;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_code',
        'name',
        'email',
        'phone',
        'password',
        'department_id',
        'supervisor_user_id',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function deptRoles()
    {
        return $this->belongsToMany(DeptRole::class, 'user_dept_roles', 'user_id', 'role_id')
            ->withPivot('department_id');
    }


    public function hasRoleCode(string|array $codes, int $departmentId = null): bool
    {
        $codes = array_map('strtoupper', (array)$codes);
        $q = $this->deptRoles()->whereIn(DB::raw('UPPER(code)'), $codes);
        if ($departmentId) $q->wherePivot('department_id', $departmentId);
        return $q->exists();
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function supervisor()
    {
        return $this->belongsTo(User::class, 'supervisor_user_id');
    }

    public function subordinates()
    {
        return $this->hasMany(User::class, 'supervisor_user_id');
    }

    public function departmentRoles()
    {
        return $this->belongsToMany(DepartmentRole::class, 'department_role_users', 'user_id', 'department_role_id')
            ->withPivot('is_primary', 'start_date', 'end_date')
            ->withTimestamps();
    }

    public function currentDepartmentRoles()
    {
        return $this->departmentRoles()->where(function ($q) {
            $q->whereDate('department_role_users.start_date', '<=', now()->toDateString())
                ->where(function ($q2) {
                    $q2->whereNull('department_role_users.end_date')
                        ->orWhereDate('department_role_users.end_date', '>=', now()->toDateString());
                });
        });
    }


    /** บทบาทหลัก (primary) ที่กำลังมีผล (ถ้ามีหลายอัน เอาเริ่มล่าสุด) */
    public function primaryDepartmentRole()
    {
        return $this->currentDepartmentRoles()
            ->wherePivot('is_primary', 1)
            ->latest('department_role_users.start_date');
    }

    /** แปลงเป็นพร็อพเพอร์ตี้ $user->level_no (ดึงจากบทบาทหลัก, ถ้าไม่มีให้ใช้ค่าสูงสุดของบทบาทปัจจุบัน) */
    public function getLevelNoAttribute(): int
    {
        // ใช้ primary ถ้ามี
        $primary = $this->primaryDepartmentRole()->first();
        if ($primary) {
            return (int) $primary->level_no;
        }

        // ไม่งั้นใช้ max ของ current roles
        $lvl = (int) ($this->currentDepartmentRoles()->max('level_no') ?? 0);
        return $lvl;
    }
}

<?php

namespace App\Models\Users;

use App\Models\Concerns\UsesWorkflowConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeptRole extends Model
{
    use HasFactory;
    use UsesWorkflowConnection;

    // ตารางนี้ไม่มี created_at/updated_at → ปิด timestamps
    public $timestamps = false;

    // อนุญาต field ที่จะ mass-assign
    protected $table = 'dept_roles';
    protected $fillable = ['code', 'name', 'is_active'];
}

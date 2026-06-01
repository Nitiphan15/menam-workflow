<?php

namespace App\Models\FormPR;

use App\Models\Concerns\UsesWorkflowConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use App\Support\SqlServerDb;
use App\Models\Users\Department;
use App\Models\Users\User;
use App\Models\WF\WfForm;
use Carbon\Carbon;

class PrData extends Model
{
    use UsesWorkflowConnection;

    protected $table = 'pr_data';
    public $timestamps = false; // ตารางนี้ไม่มี created_at/updated_at

    protected $fillable = [
        'form_id',
        'req_date',
        'docu_date',
        'docu_no',
        'department_id',
        'department',
        'site',
        'type',
        'type_other',
        'attached_file',
    ];

    protected $casts = [
        'req_date'     => 'date',
        'docu_date'    => 'date',
        'attached_file' => 'boolean',
    ];

    /* ---------------- Relations ---------------- */

    // wf_forms
    public function wfForm()
    {
        return $this->belongsTo(WfForm::class, 'form_id');
    }

    // departments
    public function departmentRef()
    {
        return $this->belongsTo(Department::class, 'department_id', 'id');
    }

    // pr_data_files: ใช้ form_id เชื่อม (localKey=form_id)
    public function files()
    {
        return $this->hasMany(PrDataFile::class, 'form_id', 'form_id');
    }

    // ผู้ยื่นเอกสาร (originator) ผ่าน wf_forms.request_by_user_id
    public function requester()
    {
        return $this->hasOneThrough(
            User::class,          // สุดทาง
            WfForm::class,        // ตารางกลาง
            'id',                 // FK บน WfForm ชี้ตัวมันเอง (primary key)
            'id',                 // PK ของ User
            'form_id',            // FK บน PrData → WfForm
            'request_by_user_id'  // FK บน WfForm → User
        );
    }

    /* ---------------- Scopes ---------------- */

    public function scopeOfDepartment(Builder $q, int $deptId): Builder
    {
        return $q->where('department_id', $deptId);
    }

    public function scopeInYearMonth(Builder $q, int $year, int $month): Builder
    {
        return $q->whereYear('req_date', $year)
            ->whereMonth('req_date', $month);
    }

    public function scopeSearch(Builder $q, ?string $kw): Builder
    {
        $kw = trim((string)$kw);
        if ($kw === '') return $q;

        return $q->where(function ($w) use ($kw) {
            $w->where('docu_no', 'like', "%$kw%")
                ->orWhere('department', 'like', "%$kw%")
                ->orWhere('type', 'like', "%$kw%");
        });
    }

    /* ---------------- Helpers ---------------- */

    /**
     * สร้างเลขเอกสาร: DEPT(รหัสแผนก) + YY + MM + running(4)
     * - ใช้ภายใน Transaction เสมอ (ป้องกันชนกัน)
     * - รันแยกตามเดือน/แผนก
     */
    public static function nextDocNo(int $departmentId, ?Carbon $baseDate = null): string
    {
        $baseDate = $baseDate ?: now();
        $yy   = $baseDate->format('y');
        $mm   = $baseDate->format('m');

        $deptCode = SqlServerDb::table('departments')->where('id', $departmentId)->value('code') ?? 'XX';
        $prefix   = $deptCode . $yy . $mm; // เช่น IT2508

        // ดึง docu_no ล่าสุดของเดือน/แผนกเดียวกัน (ล็อกเพื่อกัน race)
        $last = SqlServerDb::table('pr_data')
            ->select('docu_no')
            ->where('department_id', $departmentId)
            ->where('docu_no', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('docu_no');

        $run = $last ? (int)substr($last, -4) + 1 : 1;

        return $prefix . str_pad((string)$run, 4, '0', STR_PAD_LEFT);
    }
}

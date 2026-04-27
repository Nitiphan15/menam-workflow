<?php
// app/Models/PA/PaForm.php
namespace App\Models\FormPA;

use Illuminate\Database\Eloquent\Model;
use App\Models\Users\User;
use App\Models\Users\Department;

class PaData extends Model
{
    protected $table = 'pa_data';
    public $timestamps = false;

    protected $fillable = [
        'period_id',
        'employee_id',
        'department_id',
        'position_title',
        'form_no',
        'wf_form_id',
        'scale_max',
        'final_avg',
        'final_grade',
        'employee_ack_at',
        'employee_comment',
    ];

    protected $casts = [
        'id'              => 'integer',
        'period_id'       => 'integer',
        'employee_id'     => 'integer',
        'department_id'   => 'integer',
        'wf_form_id'      => 'integer',
        'scale_max'       => 'integer',
        'final_avg'       => 'decimal:3',
        'employee_ack_at' => 'datetime',
    ];

    public const STATUS_DRAFT       = 'DRAFT';
    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';
    public const STATUS_CLOSED      = 'CLOSED';
    public const STATUS_CANCELLED   = 'CANCELLED';

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function scopeOfPeriod($q, int $periodId)
    {
        return $q->where('period_id', $periodId);
    }
}

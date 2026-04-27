<?php

namespace App\Models\FormPP;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use App\Support\WorkflowDb;
use App\Models\WF\WfForm;
use App\Models\Users\User;
use Carbon\Carbon;

class PpData extends Model
{
    protected $table = 'pp_data';
    public $timestamps = true; // มี created_at / updated_at

    protected $fillable = [
        'form_id',
        'req_date',
        'docu_date',
        'part_no',
        'data_site',
        'customer',
        'sales_fg',
        'sales_wip',
        'sales_mfg',
        'sales_order',
        'sales_prod',
        'sales_rm',
        'planner_fg',
        'planner_wip',
        'planner_mfg',
        'planner_order',
        'planner_prod',
        'planner_rm',
    ];

    protected $casts = [
        'req_date'      => 'date',
        'docu_date'     => 'date',
        'sales_fg'      => 'float',
        'sales_wip'     => 'float',
        'sales_mfg'     => 'float',
        'sales_order'   => 'float',
        'sales_prod'    => 'float',
        'sales_rm'      => 'float',
        'planner_fg'    => 'float',
        'planner_wip'   => 'float',
        'planner_mfg'   => 'float',
        'planner_order' => 'float',
        'planner_prod'  => 'float',
        'planner_rm'    => 'float',
    ];

    /* ---------------- Relations ---------------- */

    // wf_forms
    public function wfForm()
    {
        return $this->belongsTo(WfForm::class, 'form_id');
    }

    // ผู้ยื่นเอกสาร (originator) ผ่าน wf_forms
    public function requester()
    {
        return $this->hasOneThrough(
            User::class,
            WfForm::class,
            'id',                 // wf_forms.id
            'id',                 // users.id
            'form_id',            // pp_data.form_id
            'request_by_user_id'  // wf_forms.request_by_user_id
        );
    }

    /* ---------------- Scopes ---------------- */

    public function scopeInMonth(Builder $q, int $year, int $month): Builder
    {
        return $q->whereYear('req_date', $year)
            ->whereMonth('req_date', $month);
    }

    public function scopeBySite(Builder $q, string $site): Builder
    {
        return $q->where('data_site', $site);
    }

    public function scopeSearch(Builder $q, ?string $kw): Builder
    {
        $kw = trim((string)$kw);
        if ($kw === '') return $q;

        return $q->where(function ($w) use ($kw) {
            $w->where('part_no', 'like', "%$kw%")
                ->orWhere('customer', 'like', "%$kw%");
        });
    }

    /* ---------------- Helpers ---------------- */

    /**
     * สร้างรหัสอ้างอิง (optional helper)
     */
    public static function generateDocCode(string $appCode = 'PP', ?Carbon $baseDate = null): string
    {
        $baseDate = $baseDate ?: now();
        $appCode = strtolower(trim($appCode));
        $prefixLike = strtolower(strtoupper($appCode) . $baseDate->format('ym')) . '%';
        $prefix = strtoupper($appCode) . $baseDate->format('ym'); // เช่น WIRE2508

        $lastLocal = DB::table('pp_data')
            ->select('docu_no')
            ->whereRaw('LOWER(docu_no) LIKE ?', [$prefixLike])
            ->orderByDesc('docu_no')
            ->lockForUpdate()
            ->value('docu_no');

        $lastWorkflow = WorkflowDb::table($appCode, 'wf_forms')
            ->select('form_no')
            ->whereRaw('LOWER(app_code) = ?', [$appCode])
            ->whereRaw('LOWER(form_no) LIKE ?', [$prefixLike])
            ->orderByDesc('form_no')
            ->lockForUpdate()
            ->value('form_no');

        $run = collect([$lastLocal, $lastWorkflow])
            ->filter()
            ->map(function ($docNo) use ($prefix) {
                return preg_match('/^' . preg_quote($prefix, '/') . '(\d{4})$/', (string) $docNo, $m)
                    ? (int) $m[1]
                    : 0;
            })
            ->max() + 1;

        return $prefix . str_pad($run, 4, '0', STR_PAD_LEFT);
    }
}

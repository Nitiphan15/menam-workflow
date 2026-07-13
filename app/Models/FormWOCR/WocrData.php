<?php

namespace App\Models\FormWOCR;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use App\Support\WorkflowDb;
use App\Models\WF\WfForm;
use App\Models\Users\User;
use Carbon\Carbon;

/**
 * ตาราง: wocr_data (WOCR = Work Order Change Request)
 *
 * @property int                 $id
 * @property int|null            $form_id
 * @property \Illuminate\Support\Carbon|null $req_date
 * @property \Illuminate\Support\Carbon|null $docu_date
 * @property string|null         $docu_no
 * @property string|null         $mfg_no
 * @property string|null         $form_type
 * @property string|null         $grade
 * @property string|null         $type
 * @property string|null         $size
 * @property string|null         $length
 * @property string|null         $qty
 * @property string|null         $mfg_request_detail
 * @property string|null         $reason
 */
class WocrData extends Model
{
    /** ชื่อตาราง & คอนเนคชัน */
    protected $table = 'wocr_data';
    protected $connection = 'mysql';

    /** ตารางนี้ไม่มี created_at/updated_at (อิงสคีมาที่ให้มา) */
    public $timestamps = false;

    /** อนุญาต mass assign */
    protected $fillable = [
        'form_id',
        'req_date',
        'docu_date',
        'docu_no',
        'mfg_no',
        'form_type',
        'grade',
        'req_type',
        'urgency',
        'size',
        'length',
        'qty',
        'mfg_request_detail',
        'reason',
    ];

    /** แคสต์ชนิดข้อมูล */
    protected $casts = [
        'form_id'   => 'integer',
        'urgency'   => 'integer',
        'req_date'  => 'datetime',
        'docu_date' => 'datetime',
    ];

    /** ระดับความเร่งด่วน */
    public const URGENCY_LOW    = 1;
    public const URGENCY_MEDIUM = 2;
    public const URGENCY_HIGH   = 3;

    /** map ระดับความเร่งด่วน -> ป้ายภาษาไทย */
    public const URGENCY_LABELS = [
        self::URGENCY_LOW    => 'น้อย',
        self::URGENCY_MEDIUM => 'ปานกลาง',
        self::URGENCY_HIGH   => 'มาก',
    ];

    /** ป้ายภาษาไทยของ urgency ปัจจุบัน */
    public function urgencyLabel(): string
    {
        return self::URGENCY_LABELS[(int) $this->urgency] ?? '-';
    }

    public static function urgencyText(?int $urgency): string
    {
        return self::URGENCY_LABELS[(int) $urgency] ?? '-';
    }

    /* ---------------- Relations ---------------- */

    // เอกสาร WF ที่อ้างอิง
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
            'form_id',            // wocr_data.form_id
            'request_by_user_id'  // wf_forms.request_by_user_id
        );
    }

    /* ---------------- Scopes ---------------- */

    public function scopeInMonth(Builder $q, int $year, int $month): Builder
    {
        return $q->whereYear('req_date', $year)
            ->whereMonth('req_date', $month);
    }

    public function scopeByFormType(Builder $q, string $type): Builder
    {
        return $q->where('form_type', $type);
    }

    public function scopeDocuNo(Builder $q, string $docuNo): Builder
    {
        return $q->where('docu_no', $docuNo);
    }

    public function scopeMfgNo(Builder $q, string $mfgNo): Builder
    {
        return $q->where('mfg_no', $mfgNo);
    }

    public function scopeBetweenReqDate(Builder $q, $start, $end): Builder
    {
        return $q->whereBetween('req_date', [$start, $end]);
    }

    public function scopeSearch(Builder $q, ?string $kw): Builder
    {
        $kw = trim((string) $kw);
        if ($kw === '') return $q;

        return $q->where(function ($w) use ($kw) {
            $w->where('docu_no', 'like', "%{$kw}%")
                ->orWhere('mfg_no', 'like', "%{$kw}%")
                ->orWhere('grade', 'like', "%{$kw}%")
                ->orWhere('type', 'like', "%{$kw}%")
                ->orWhere('size', 'like', "%{$kw}%")
                ->orWhere('reason', 'like', "%{$kw}%")
                ->orWhere('mfg_request_detail', 'like', "%{$kw}%");
        });
    }

    /* ---------------- Helpers ---------------- */

    /**
     * สร้างเลขเอกสารสำหรับ WOCR เช่น "WOCR2509XXXX"
     * - prefix: WOCR + YYMM + running 4 หลัก
     * - ใช้ lockForUpdate ป้องกันชนเมื่อรันพร้อมกัน
     */
    public static function generateDocCode(string $appCode = 'WOCR', ?Carbon $baseDate = null): string
    {
        $baseDate = $baseDate ?: now();
        $prefix = strtoupper($appCode) . $baseDate->format('ym'); // ex. WOCR2509

        $lastLocal = DB::table('wocr_data')
            ->select('docu_no')
            ->where('docu_no', 'like', $prefix . '%')
            ->orderByDesc('docu_no')
            ->lockForUpdate()
            ->value('docu_no');

        $lastWorkflow = WorkflowDb::table($appCode, 'wf_forms')
            ->select('form_no')
            ->whereRaw('LOWER(app_code) = ?', [strtolower($appCode)])
            ->where('form_no', 'like', $prefix . '%')
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

<?php

namespace App\Services\FormWR;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Import follow-up (review) data for Wire Rod Dead Stock from Excel.
 * Accepts the template from templateHeaders() or the "Serial Detail" sheet of the page's own Export.
 */
class WireRodDeadstockReviewImporter
{
    public const MAX_ROWS = 5000;

    public const STATUS_LABELS = [
        'open' => 'ยังไม่ได้ติดตาม',
        'waiting_follow_up' => 'กำลังติดตาม รอคำตอบ',
        'in_progress' => 'มีกำหนดส่งมอบแล้ว',
    ];

    private const TEXT_FIELDS = ['review_detail', 'corrective_action', 'preventive_action', 'sales_remark'];

    private string $connection;

    public function __construct()
    {
        $this->connection = config('database.workflow_connection', 'sqlsrv_menam');
    }

    public static function templateHeaders(): array
    {
        return ['Site', 'Part Number', 'Serial / Heat', 'Review Status', 'Next Follow-up',
            'Review Detail', 'Corrective Action', 'Preventive Action', 'Sales Remark'];
    }

    /**
     * @param  callable(object $item): bool  $canEdit
     * @return array{stats: array<string,int>, errors: list<string>}
     */
    public function import(string $path, callable $canEdit, int $userId, string $userName, bool $dryRun): array
    {
        $rows = $this->readRows($path);

        return $this->importRows($rows, $canEdit, $userId, $userName, $dryRun);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows  each row: row_number + field => ['raw'=>..., 'text'=>...]
     */
    public function importRows(Collection $rows, callable $canEdit, int $userId, string $userName, bool $dryRun): array
    {
        $stats = ['read' => 0, 'saved' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0,
            'empty' => 0, 'unmatched' => 0, 'ambiguous' => 0, 'duplicate' => 0, 'unauthorized' => 0, 'error' => 0];
        $errors = [];
        $seen = [];
        $db = DB::connection($this->connection);

        if ($rows->isEmpty()) {
            $errors[] = 'ไม่พบข้อมูล หรือไม่พบหัวคอลัมน์ Serial / Heat';

            return compact('stats', 'errors');
        }
        if ($rows->count() > self::MAX_ROWS) {
            $errors[] = 'ไฟล์มีเกิน '.number_format(self::MAX_ROWS).' แถว กรุณาแบ่งไฟล์';

            return compact('stats', 'errors');
        }

        foreach ($rows as $row) {
            $stats['read']++;
            $label = 'แถว '.$row['row_number'];

            [$payload, $problem] = $this->payload($row);
            if ($problem) {
                $stats['error']++;
                $errors[] = "{$label}: {$problem}";
                continue;
            }
            if ($payload === []) {
                $stats['empty']++;
                continue;
            }

            $serial = $this->text($row['serial'] ?? null);
            if (! $serial) {
                $stats['unmatched']++;
                $errors[] = "{$label}: ไม่มี Serial / Heat";
                continue;
            }

            $matches = $this->findItems($serial, $this->text($row['site'] ?? null), $this->text($row['part_number'] ?? null));
            if ($matches->isEmpty()) {
                $stats['unmatched']++;
                $errors[] = "{$label}: ไม่พบ Serial {$serial} ในข้อมูลปัจจุบัน";
                continue;
            }
            if ($matches->count() > 1) {
                $stats['ambiguous']++;
                $errors[] = "{$label}: Serial {$serial} พบหลาย Lot กรุณาระบุ Site / Part Number";
                continue;
            }

            $item = $matches->first();
            if (isset($seen[$item->lifecycle_id])) {
                $stats['duplicate']++;
                $errors[] = "{$label}: Serial {$serial} ซ้ำกับแถว {$seen[$item->lifecycle_id]} (ข้าม)";
                continue;
            }
            $seen[$item->lifecycle_id] = $row['row_number'];

            if (! $canEdit($item)) {
                $stats['unauthorized']++;
                $errors[] = "{$label}: ไม่มีสิทธิ์บันทึก Serial {$serial}";
                continue;
            }

            try {
                $result = $this->save($db, $item, $payload, $userId, $userName, $dryRun);
            } catch (Throwable $e) {
                Log::warning('FormWR deadstock review import row failed', ['row' => $row['row_number'], 'error' => $e->getMessage()]);
                $stats['error']++;
                $errors[] = "{$label}: บันทึกไม่สำเร็จ";
                continue;
            }

            if ($result === 'unchanged') {
                $stats['unchanged']++;
                continue;
            }
            $stats['saved']++;
            $stats[$result]++;
        }

        return compact('stats', 'errors');
    }

    /** @return array{0: array<string,mixed>, 1: ?string} */
    public function payload(array $row): array
    {
        $payload = [];
        foreach (self::TEXT_FIELDS as $field) {
            $value = $this->text($row[$field] ?? null);
            if ($value !== null) {
                if (mb_strlen($value) > 5000) {
                    return [[], "{$field} ยาวเกิน 5000 ตัวอักษร"];
                }
                $payload[$field] = $value;
            }
        }

        $statusText = $this->text($row['review_status'] ?? null);
        if ($statusText !== null) {
            $status = self::normalizeStatus($statusText);
            if (! $status) {
                return [[], "สถานะ \"{$statusText}\" ไม่รองรับ (ใช้ open / waiting_follow_up / in_progress หรือข้อความภาษาไทยตามหน้าจอ)"];
            }
            $payload['review_status'] = $status;
        }

        if (array_key_exists('next_follow_up_date', $row) && $this->text($row['next_follow_up_date']) !== null) {
            $date = $this->date($row['next_follow_up_date']);
            if (! $date) {
                return [[], 'วันติดตามถัดไปไม่ใช่วันที่'];
            }
            $payload['next_follow_up_date'] = $date;
        }

        return [$payload, null];
    }

    public static function normalizeStatus(string $value): ?string
    {
        $key = mb_strtolower(trim($value));
        $compact = preg_replace('/[\s_\-]+/u', '', $key);
        foreach (self::STATUS_LABELS as $code => $label) {
            if ($compact === str_replace('_', '', $code) || $compact === preg_replace('/\s+/u', '', mb_strtolower($label))) {
                return $code;
            }
        }

        return null;
    }

    private function findItems(string $serial, ?string $site, ?string $partNumber): Collection
    {
        return DB::connection($this->connection)->table('wr_ds_current_items as c')
            ->join('wr_ds_item_lifecycles as l', 'l.id', '=', 'c.lifecycle_id')
            ->where('l.is_current', 1)
            ->whereRaw('UPPER(c.serial_number) = ?', [mb_strtoupper($serial)])
            ->when($site, fn ($q) => $q->whereRaw('UPPER(c.site_code) = ?', [mb_strtoupper($site)]))
            ->when($partNumber, fn ($q) => $q->whereRaw('UPPER(c.part_number) = ?', [mb_strtoupper($partNumber)]))
            ->select('c.*', 'l.cycle_no')
            ->limit(5)->get();
    }

    private function save($db, object $item, array $payload, int $userId, string $userName, bool $dryRun): string
    {
        return $db->transaction(function () use ($db, $item, $payload, $userId, $userName, $dryRun) {
            $db->table('wr_ds_item_lifecycles')->where('id', $item->lifecycle_id)->lockForUpdate()->first();
            $before = $db->table('wr_ds_item_reviews')->where('lifecycle_id', $item->lifecycle_id)->lockForUpdate()->first();

            $changed = collect($payload)->filter(function ($value, $field) use ($before) {
                $old = $before?->{$field} ?? null;
                if ($field === 'next_follow_up_date' && $old) {
                    $old = Carbon::parse($old)->toDateString();
                }

                return trim((string) $old) !== trim((string) $value);
            })->all();

            if ($before && $changed === []) {
                return 'unchanged';
            }
            // A re-imported Export lists every untouched lot as "open"; don't create empty reviews for them.
            if (! $before && array_keys($payload) === ['review_status'] && $payload['review_status'] === 'open') {
                return 'unchanged';
            }
            if (! $before && ! isset($payload['review_status'])) {
                $changed['review_status'] = 'open';
            }
            if ($dryRun) {
                return $before ? 'updated' : 'created';
            }

            $now = now('Asia/Bangkok');
            $audit = ['reviewed_by' => $userId, 'reviewed_by_name' => $userName, 'reviewed_at' => $now,
                'updated_by' => $userId, 'updated_at' => $now];
            if ($before) {
                $db->table('wr_ds_item_reviews')->where('id', $before->id)->update($changed + $audit);
                $reviewId = $before->id;
            } else {
                $reviewId = $db->table('wr_ds_item_reviews')->insertGetId($changed + $audit + [
                    'lifecycle_id' => $item->lifecycle_id, 'created_by' => $userId, 'created_at' => $now,
                ]);
            }
            $db->table('wr_ds_item_review_logs')->insert([
                'lifecycle_id' => $item->lifecycle_id, 'review_id' => $reviewId, 'site_code' => $item->site_code,
                'serial_number' => $item->serial_number, 'cycle_no' => $item->cycle_no,
                'action' => $before ? 'UPDATE' : 'CREATE', 'source' => 'IMPORT',
                'changed_fields' => json_encode(array_keys($changed)),
                'before_values' => json_encode($before, JSON_UNESCAPED_UNICODE),
                'after_values' => json_encode($changed, JSON_UNESCAPED_UNICODE),
                'changed_by' => $userId, 'changed_by_name' => $userName,
                'changed_at' => $now, 'created_at' => $now,
            ]);

            return $before ? 'updated' : 'created';
        });
    }

    /** @return Collection<int, array<string, mixed>> */
    public function readRows(string $path): Collection
    {
        $spreadsheet = IOFactory::load($path);
        try {
            // Prefer the export's "Serial Detail" sheet when present, else the active sheet.
            $sheet = $spreadsheet->getSheetByName('Serial Detail') ?? $spreadsheet->getActiveSheet();
            $highestRow = min((int) $sheet->getHighestRow(), self::MAX_ROWS + 25);
            $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestColumn());
            $headerMap = self::headerMap();
            $columnMap = [];
            $headerRow = null;

            for ($r = 1; $r <= min($highestRow, 20); $r++) {
                $mapped = [];
                for ($col = 1; $col <= $highestColumn; $col++) {
                    $header = self::normalizeHeader($sheet->getCell(Coordinate::stringFromColumnIndex($col).$r)->getFormattedValue());
                    if (isset($headerMap[$header]) && ! in_array($headerMap[$header], $mapped, true)) {
                        $mapped[$col] = $headerMap[$header];
                    }
                }
                if (in_array('serial', $mapped, true) && count($mapped) >= 2) {
                    $headerRow = $r;
                    $columnMap = $mapped;
                    break;
                }
            }
            if ($headerRow === null) {
                return collect();
            }

            $rows = [];
            for ($r = $headerRow + 1; $r <= $highestRow; $r++) {
                $data = ['row_number' => $r];
                $hasValue = false;
                foreach ($columnMap as $col => $key) {
                    $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($col).$r);
                    $text = trim((string) $cell->getFormattedValue());
                    $raw = $cell->getValue();
                    $hasValue = $hasValue || $text !== '';
                    $data[$key] = ['raw' => $raw, 'text' => $text];
                }
                if ($hasValue) {
                    $rows[] = $data;
                }
            }

            return collect($rows);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public static function headerMap(): array
    {
        $aliases = [
            'site' => ['Site', 'company', 'site_code'],
            'part_number' => ['Part Number', 'partnumber', 'part_number', 'Part'],
            'serial' => ['Serial / Heat', 'Serial', 'serialnumber', 'serial_number', 'Serial no.', 'Heat No.'],
            'review_status' => ['Review Status', 'สถานะการติดตาม', 'review_status', 'สถานะงาน'],
            'next_follow_up_date' => ['Next Follow-up', 'วันติดตามถัดไป', 'next_follow_up_date'],
            'review_detail' => ['Review Detail', 'รายละเอียด', 'review_detail'],
            'corrective_action' => ['Corrective Action', 'แนวทางแก้ไข', 'corrective_action'],
            'preventive_action' => ['Preventive Action', 'แนวทางป้องกัน', 'แนวทางป้องกันการเกิดซ้ำ', 'preventive_action'],
            'sales_remark' => ['Sales Remark', 'Remark', 'หมายเหตุ', 'sales_remark'],
        ];
        $map = [];
        foreach ($aliases as $key => $headers) {
            foreach ($headers as $header) {
                $map[self::normalizeHeader($header)] = $key;
            }
        }

        return $map;
    }

    public static function normalizeHeader(mixed $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', trim((string) $value));

        return preg_replace('/[\s\(\)\[\]\{\}._\-\/\\\\]+/u', '', mb_strtolower($value)) ?? '';
    }

    private function text(?array $cell): ?string
    {
        $value = trim((string) ($cell['text'] ?? ''));

        return ($value === '' || $value === '-' || mb_strtolower($value) === 'null') ? null : $value;
    }

    private function date(?array $cell): ?string
    {
        $raw = $cell['raw'] ?? null;
        $text = $this->text($cell);
        try {
            if (is_numeric($raw) && (float) $raw > 1000 && (float) $raw < 100000) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $raw))->toDateString();
            }
            if ($text && preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $text, $m)) {
                return Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1])->toDateString(); // d/m/Y
            }

            return $text ? Carbon::parse($text)->toDateString() : null;
        } catch (Throwable) {
            return null;
        }
    }
}

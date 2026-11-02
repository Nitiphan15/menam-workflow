<?php

namespace App\Services\FormDIE;

use App\Models\FormDIE\DieMasterExtra;
use App\Repositories\FormDIE\DieUsageEquipmentRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * ข้อมูลไดร์ที่กรอกเองในระบบ (ฐาน PCM อ่านอย่างเดียว) — ตอนนี้คือวันที่รับเข้า/หมายเหตุ
 * อ่านแยกจาก cache ของ master/profile เพื่อให้ค่าที่เพิ่งบันทึกแสดงทันที
 */
class DieMasterExtraService
{
    private const MAX_IMPORT_ROWS = 30000;

    public function __construct(private DieUsageEquipmentRepository $dieRepo) {}

    /** เติม received_date/age ให้แถว master; ฐาน Workflow ล่มต้องไม่ทำให้หน้า master ล่ม → คืน false */
    public function attachToMasterRows(array $rows): bool
    {
        $extras = $this->allKeyed();
        foreach ($rows as $r) {
            $payload = $extras[$this->key($r->site ?? 'W', $r->equipnumber ?? '')] ?? null;
            $r->received_date = $payload['received_date'] ?? null;
            $r->age_days      = $payload['age_days'] ?? null;
            $r->age_text      = $payload['age_text'] ?? null;
        }

        return $extras !== null;
    }

    public function attachToExportRows(array $rows): array
    {
        $extras = $this->allKeyed() ?? [];
        foreach ($rows as &$row) {
            // แถวที่ไม่มี site (โหมด WO) ดูจากเลข WO: ขึ้นต้น + = P
            $site = $row['site'] ?? (str_starts_with((string) ($row['workordernumber'] ?? ''), '+') ? 'P' : 'W');
            $payload = $extras[$this->key($site, $row['equipnumber'] ?? '')] ?? null;
            $row['received_date'] = $payload['received_date'] ?? null;
            $row['age_text']      = $payload['age_text'] ?? null;
        }
        unset($row);

        return $rows;
    }

    /** @return array{available: bool, extra: ?array} */
    public function forDie(string $site, string $equipnumber): array
    {
        try {
            $row = DieMasterExtra::where('site', $site)->where('equipnumber', $equipnumber)->first();
        } catch (\Throwable $e) {
            Log::warning('die_master_extras read failed: ' . $e->getMessage());
            return ['available' => false, 'extra' => null];
        }

        return ['available' => true, 'extra' => $row ? $this->payload($row) : null];
    }

    public function firstUseDate(string $site, string $equipnumber): ?string
    {
        return $this->dieRepo->getFirstIssueDate($equipnumber, $this->connOf($site));
    }

    /** @return ?array null = ไม่พบไดร์นี้ใน PCM */
    public function save(string $site, string $equipnumber, ?string $receivedDate, ?string $notes, string $user): ?array
    {
        $conn = $this->connOf($site);
        if (!$this->dieRepo->getDieInfo($equipnumber, $conn)) {
            return null;
        }

        $row = DieMasterExtra::updateOrCreate(
            ['site' => $site, 'equipnumber' => $equipnumber],
            ['received_date' => $receivedDate, 'notes' => $notes, 'updated_by' => $user]
        );

        return $this->payload($row);
    }

    /**
     * นำเข้าแบบ merge เท่านั้น: ช่องว่างไม่ล้างค่าเดิม และถ้ามีแถวผิดแม้แถวเดียวจะไม่บันทึกอะไรเลย
     * @return array{imported: int, skipped: int, errors: array<int, string>}
     */
    public function import(string $path, string $user): array
    {
        $sheetRows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
        $header = array_map(fn($v) => trim((string) $v), $sheetRows[0] ?? []);
        $columns = [
            'site' => array_search('Site', $header, true),
            'die'  => array_search('Die#', $header, true),
            'date' => array_search('วันที่รับเข้า', $header, true),
            'note' => array_search('หมายเหตุ', $header, true),
        ];
        if (in_array(false, $columns, true)) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['หัวตารางต้องมี Site, Die#, วันที่รับเข้า, หมายเหตุ (ใช้ไฟล์แม่แบบ)']];
        }
        if (count($sheetRows) - 1 > self::MAX_IMPORT_ROWS) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['ไฟล์มีเกิน ' . self::MAX_IMPORT_ROWS . ' แถว']];
        }

        $errors = [];
        $items  = [];
        $skipped = 0;
        foreach (array_slice($sheetRows, 1) as $i => $cells) {
            $line = $i + 2;
            $site = strtoupper(trim((string) ($cells[$columns['site']] ?? '')));
            $die  = trim((string) ($cells[$columns['die']] ?? ''));
            $rawDate = $cells[$columns['date']] ?? null;
            $note = trim((string) ($cells[$columns['note']] ?? ''));

            if ($site === '' && $die === '' && trim((string) $rawDate) === '' && $note === '') {
                continue;
            }
            if (!in_array($site, ['W', 'P'], true)) {
                $errors[] = "แถว {$line}: Site ต้องเป็น W หรือ P";
                continue;
            }
            if ($die === '') {
                $errors[] = "แถว {$line}: ไม่มี Die#";
                continue;
            }
            $key = $this->key($site, $die);
            if (isset($items[$key])) {
                $errors[] = "แถว {$line}: {$site} / {$die} ซ้ำในไฟล์";
                continue;
            }

            $date = null;
            if (trim((string) $rawDate) !== '') {
                $date = self::parseDate($rawDate);
                if ($date === null) {
                    $errors[] = "แถว {$line}: วันที่รับเข้าไม่ถูกต้องหรือเป็นวันในอนาคต";
                    continue;
                }
            }
            if ($date === null && $note === '') {
                $skipped++;
                continue;
            }
            $items[$key] = compact('site', 'die', 'date', 'note', 'line');
        }

        foreach (['W', 'P'] as $site) {
            $dies = array_column(array_filter($items, fn($it) => $it['site'] === $site), 'die');
            if (!$dies) continue;
            $known = array_flip($this->dieRepo->getExistingEquipnumbers($dies, $this->connOf($site)));
            foreach ($items as $it) {
                if ($it['site'] === $site && !isset($known[$it['die']])) {
                    $errors[] = "แถว {$it['line']}: ไม่พบ {$site} / {$it['die']} ในฐาน PCM";
                }
            }
        }

        if ($errors) {
            return ['imported' => 0, 'skipped' => $skipped, 'errors' => $errors];
        }

        DB::connection((new DieMasterExtra())->getConnectionName())->transaction(function () use ($items, $user) {
            foreach ($items as $it) {
                $values = ['updated_by' => $user];
                if ($it['date'] !== null) $values['received_date'] = $it['date'];
                if ($it['note'] !== '')   $values['notes'] = $it['note'];
                DieMasterExtra::updateOrCreate(['site' => $it['site'], 'equipnumber' => $it['die']], $values);
            }
        });

        return ['imported' => count($items), 'skipped' => $skipped, 'errors' => []];
    }

    /** รับ serial ของ Excel, Y-m-d หรือ d/m/Y; ปี พ.ศ. แปลงเป็น ค.ศ.; วันในอนาคต = ไม่ถูกต้อง */
    public static function parseDate($value): ?string
    {
        try {
            if ($value instanceof \DateTimeInterface) {
                $date = Carbon::instance($value);
            } elseif (is_numeric($value)) {
                $date = Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value));
            } else {
                $date = null;
                $text = trim((string) $value);
                foreach (['Y-m-d', 'Y-n-j', 'd/m/Y', 'j/n/Y', 'd-m-Y', 'j-n-Y'] as $format) {
                    $parsed = \DateTime::createFromFormat('!' . $format, $text);
                    if ($parsed && $parsed->format($format) === $text) {
                        $date = Carbon::instance($parsed);
                        break;
                    }
                }
                if (!$date) return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        if ($date->year >= 2400) {
            $date = $date->subYears(543);
        }

        // ปีเก่าผิดปกติ = มักเป็นตัวเลขอื่นที่ Excel ตีความเป็นวันที่
        if ($date->year < 1950 || $date->startOfDay()->gt(Carbon::today())) {
            return null;
        }

        return $date->toDateString();
    }

    public static function formatAge(Carbon $received, ?Carbon $today = null): string
    {
        $diff = $received->diff($today ?? Carbon::today());
        if ($diff->y > 0) {
            return $diff->y . ' ปี' . ($diff->m > 0 ? ' ' . $diff->m . ' เดือน' : '');
        }

        return $diff->m > 0 ? $diff->m . ' เดือน' : $diff->d . ' วัน';
    }

    private function allKeyed(): ?array
    {
        try {
            $rows = DieMasterExtra::whereNotNull('received_date')->get();
        } catch (\Throwable $e) {
            Log::warning('die_master_extras read failed: ' . $e->getMessage());
            return null;
        }

        $keyed = [];
        foreach ($rows as $row) {
            $keyed[$this->key($row->site, $row->equipnumber)] = $this->payload($row);
        }

        return $keyed;
    }

    private function payload(DieMasterExtra $row): array
    {
        $received = $row->received_date;

        return [
            'received_date' => $received?->toDateString(),
            'age_days'      => $received ? (int) $received->diffInDays(Carbon::today()) : null,
            'age_text'      => $received ? self::formatAge($received) : null,
            'notes'         => $row->notes,
            'updated_by'    => $row->updated_by,
            'updated_at'    => $row->updated_at?->format('Y-m-d H:i'),
        ];
    }

    private function key(string $site, string $equipnumber): string
    {
        return $site . '|' . $equipnumber;
    }

    private function connOf(string $site): string
    {
        return $site === 'P' ? 'pgsqlpcmp' : 'pgsqlpcmw';
    }
}

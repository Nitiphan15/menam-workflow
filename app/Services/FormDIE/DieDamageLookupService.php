<?php

namespace App\Services\FormDIE;

use App\Repositories\FormDIE\DieDamageSourceRepository;
use App\Repositories\FormDIE\DieUsageEquipmentRepository;
use App\Repositories\FormDIE\WoDetailRepository;

class DieDamageLookupService
{
    public function __construct(private DieUsageEquipmentRepository $dies, private WoDetailRepository $workorders, private DieDamageSourceRepository $source)
    {
    }

    public function lookup(string $site, string $die, string $date, ?string $wo): array
    {
        $conn = $site === 'P' ? 'pgsqlpcmp' : 'pgsqlpcmw';
        $info = $this->source->master($die, $conn);
        if (! $info) {
            return ['found' => false, 'values' => [], 'warnings' => ['ไม่พบ DIE ใน Site ที่เลือก']];
        }
        $values = [];
        $warnings = [];
        // Master sizes describe today; never present them as an event-date snapshot.
        $master = (array) $info;
        $history = array_values(array_filter($this->dies->getDieHistoryAsOf($die, $conn, $date), fn ($r) => substr($r->transdate, 0, 10) <= $date));
        $candidates = array_values(array_unique(array_filter(array_map(fn ($r) => $r->prod_wo ?? null, $history))));
        $issues = array_unique(array_map(fn ($r) => $r->transnumber, array_filter($history, fn ($r) => ($r->txn_type ?? null) === 'issue')));
        if ($issues) {
            $values['usage_count'] = count($issues);
        }
        if (! $wo && count($candidates)) {
            $wo = strtoupper($candidates[0]);
        }
        if ($wo) {
            $wo = strtoupper(trim($wo));
            if (! in_array($wo, array_map('strtoupper', $candidates), true)) {
                $warnings[] = 'WO ที่ระบุไม่พบในประวัติ DIE ถึงวันที่เสีย กรุณาตรวจสอบ';
            } else {
                $values['workorder'] = $wo;
                try {
                    $wc = $site === 'P' ? 'pgsqlmfgp' : 'pgsqlmfgw';
                    $header = $this->workorders->getHeader($wo, $wc);
                    if ($header) {
                        $bom = $this->workorders->getBom((int) $header->id, $wc);
                        foreach (['wire_grade' => 'fgrade', 'material_supplier' => 'fsupplier'] as $field => $source) {
                            $unique = array_values(array_unique(array_filter(array_map(fn ($r) => trim((string) ($r->$source ?? '')), $bom))));
                            if (count($unique) === 1) {
                                $values[$field] = $unique[0];
                            } elseif (count($unique) > 1) {
                                $warnings[] = self::label($field).' มีหลายค่าใน BOM กรุณาเลือกเอง';
                            }
                        }
                        $receives = array_values(array_filter($this->workorders->getReceives((int) $header->id, $wc), fn ($r) => substr((string) $r->receivestamp, 0, 10) <= $date && ! empty($r->machinenumber)));
                        usort($receives, fn ($a, $b) => strcmp($b->receivestamp, $a->receivestamp));
                        // These are WO machines, not proof of which machine damaged this DIE.
                        $machines = array_values(array_unique(array_map(fn ($r) => $r->machinenumber, $receives)));
                    } else {
                        $warnings[] = 'ไม่พบ WO ใน MFG';
                    }
                } catch (\Throwable $e) {
                    report($e);
                    $warnings[] = 'เชื่อมต่อ MFG ไม่สำเร็จ กรอกข้อมูลเองได้';
                }
            }
        }
        $warnings[] = 'ขนาดจาก Master เป็นค่าปัจจุบัน และเครื่องจักรเป็นข้อมูลระดับ WO ต้องยืนยันค่าของเหตุการณ์ก่อนบันทึก';
        $warnings[] = 'จำนวนเบิกนับเอกสาร EU ไม่ซ้ำถึงวันที่เสีย ส่วนน้ำหนักผลิตสะสม/ราคา/ลูกค้า/สาเหตุเสีย ให้ตรวจสอบและกรอกเอง';

        return ['found' => true, 'values' => $values, 'master' => $master, 'workorders' => $candidates, 'machines' => $machines ?? [], 'warnings' => $warnings, 'source' => ['site' => $site, 'die_code' => $die, 'as_of' => $date, 'fetched_at' => now()->toIso8601String()]];
    }

    private static function label(string $field): string
    {
        return DieDamageReportService::FIELDS[$field];
    }
}

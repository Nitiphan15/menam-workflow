<?php

namespace App\Repositories\FormDIE;

use Illuminate\Support\Facades\DB;

class DieUsageEquipmentRepository
{
    public static function connectionFor(string $workordernumber): string
    {
        return str_starts_with(trim($workordernumber), '+') ? 'pgsqlpcmp' : 'pgsqlpcmw';
    }

    /**
     * สร้าง Postgres text array literal ที่ปลอดภัย — รองรับค่าที่มี comma, quote, เว้นวรรค, ภาษาไทย
     * (เลข WO ใน ERP มีข้อมูล freetext ปนจริง)
     */
    public static function pgTextArray(array $values): string
    {
        $escaped = array_map(
            fn($v) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v) . '"',
            $values
        );

        return '{' . implode(',', $escaped) . '}';
    }

    /**
     * แยกค่า category ที่อาจมาเป็น comma-separated ("A,B") หรือ array → array ที่สะอาด
     * รองรับ multi-category โดยไม่ต้องเปลี่ยน signature ทุกชั้น (ค่ายังส่งเป็น string ได้)
     */
    public static function splitCats($category): array
    {
        if ($category === null) return [];
        $arr = is_array($category) ? $category : explode(',', (string) $category);
        return array_values(array_filter(array_map('trim', $arr), fn($c) => $c !== ''));
    }

    public function getByWorkorder(string $workordernumber, ?string $category = null, ?string $description = null): array
    {
        // f3 (เลข WO) ใน ERP เก็บปนทั้งตัวพิมพ์เล็ก/ใหญ่ — ต้องเทียบแบบ case-insensitive
        // baseSelect เป็น deployment event อยู่แล้ว → WO อยู่บนแถวเบิก (evt.f3)
        $where = " AND UPPER(evt.f3) = UPPER(:wo)";
        $bind = ['wo' => $workordernumber];
        $catArr = self::splitCats($category);
        if (!empty($catArr)) {
            $where .= " AND ec.description = ANY(:cats)";
            $bind['cats'] = self::pgTextArray($catArr);
        }
        if ($description) {
            $where .= " AND e.description ILIKE :descf";
            $bind['descf'] = '%' . $description . '%';
        }

        return DB::connection(self::connectionFor($workordernumber))
            ->select($this->baseSelect() . $where . " ORDER BY evt.transdate, block_no", $bind);
    }

    public function getByDate(string $date, string $connection = 'pgsqlpcmw', ?string $category = null, ?string $description = null): array
    {
        // baseSelect = deployment event (วันที่ = วันเบิกไดร์ไปใช้, WO + แผนกจากแถวเบิก, meter จากแถวคืน)
        $where = " AND evt.transdate = :d";
        $bind = ['d' => $date];
        $catArr = self::splitCats($category);
        if (!empty($catArr)) {
            $where .= " AND ec.description = ANY(:cats)";
            $bind['cats'] = self::pgTextArray($catArr);
        }
        if ($description) {
            $where .= " AND e.description ILIKE :descf";
            $bind['descf'] = '%' . $description . '%';
        }

        return DB::connection($connection)
            ->select($this->baseSelect() . $where . " ORDER BY evt.transdate, evt.f3, block_no", $bind);
    }

    public function getByDateRange(string $start, string $end, string $connection = 'pgsqlpcmw', ?string $category = null, ?string $description = null): array
    {
        // baseSelect = deployment event เช่นเดียวกับ getByDate
        $where = " AND evt.transdate BETWEEN :s AND :e";
        $bind = ['s' => $start, 'e' => $end];
        $catArr = self::splitCats($category);
        if (!empty($catArr)) {
            $where .= " AND ec.description = ANY(:cats)";
            $bind['cats'] = self::pgTextArray($catArr);
        }
        if ($description) {
            $where .= " AND e.description ILIKE :descf";
            $bind['descf'] = '%' . $description . '%';
        }

        return DB::connection($connection)
            ->select($this->baseSelect() . $where . " ORDER BY evt.transdate, evt.f3, block_no", $bind);
    }

    /**
     * รายการประเภท equipment (equiptype) สำหรับ dropdown — เรียงตามจำนวนมาก→น้อย
     */
    public function getEquipTypes(string $connection): array
    {
        return DB::connection($connection)->select("
            SELECT et.description AS equiptype, COUNT(e.id) AS die_count
            FROM equiptype et
            JOIN equipment e ON e.equiptype_id = et.id
            GROUP BY et.description
            HAVING COUNT(e.id) > 0
            ORDER BY COUNT(e.id) DESC
        ");
    }

    /**
     * รายการ status ที่มีจริงในข้อมูล (distinct) — ภายในประเภทที่เลือก
     */
    public function getStatuses(string $connection, string $equiptype = 'ไดร์'): array
    {
        $conds = ["NULLIF(TRIM(e.status), '') IS NOT NULL"];
        $bind  = [];
        if ($equiptype !== '' && $equiptype !== 'ALL') {
            $conds[] = 'et.description = :etype';
            $bind['etype'] = $equiptype;
        }

        return DB::connection($connection)->select("
            SELECT TRIM(e.status) AS status, COUNT(*) AS n
            FROM equipment e
            JOIN equiptype et ON et.id = e.equiptype_id
            WHERE " . implode(' AND ', $conds) . "
            GROUP BY TRIM(e.status)
            ORDER BY COUNT(*) DESC
        ", $bind);
    }

    public function getCategories(string $connection, string $equiptype = 'ไดร์'): array
    {
        $where = '';
        $bind  = [];
        if ($equiptype !== '' && $equiptype !== 'ALL') {
            $where = 'WHERE et.description = :etype';
            $bind['etype'] = $equiptype;
        }

        return DB::connection($connection)->select("
            SELECT ec.description AS category, COUNT(*) AS die_count
            FROM equipment e
            JOIN equipcategory ec ON ec.id = e.equipcategory_id
            JOIN equiptype et ON et.id = e.equiptype_id
            $where
            GROUP BY ec.description
            ORDER BY ec.description
        ", $bind);
    }

    /**
     * รายชื่อ Supplier (equipment.vendor) ที่มีจริง — สำหรับ dropdown filter
     */
    public function getSuppliers(string $connection, string $equiptype = 'ไดร์'): array
    {
        $conds = ["NULLIF(TRIM(e.vendor), '') IS NOT NULL"];
        $bind  = [];
        if ($equiptype !== '' && $equiptype !== 'ALL') {
            $conds[] = 'et.description = :etype';
            $bind['etype'] = $equiptype;
        }

        return DB::connection($connection)->select("
            SELECT TRIM(e.vendor) AS supplier, COUNT(*) AS die_count
            FROM equipment e
            JOIN equiptype et ON et.id = e.equiptype_id
            WHERE " . implode(' AND ', $conds) . "
            GROUP BY TRIM(e.vendor)
            ORDER BY COUNT(*) DESC, TRIM(e.vendor)
        ", $bind);
    }

    public function getDieMaster(string $connection, array $filters = []): array
    {
        // จำแนกด้วย equiptype (default 'ไดร์') แทน ILIKE '%ไดร์%' เดิมที่ดึงของไม่ใช่ไดร์ปนมา
        $where = [];
        $bind  = [];

        $equiptype = $filters['equiptype'] ?? 'ไดร์';
        if ($equiptype !== '' && $equiptype !== 'ALL') {
            $where[] = "et.description = :etype";
            $bind['etype'] = $equiptype;
        }

        if (!empty($filters['q'])) {
            $where[] = "(e.equipnumber ILIKE :q OR e.description ILIKE :q)";
            $bind['q'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['status'])) {
            $where[] = "e.status = :st";
            $bind['st'] = $filters['status'];
        }
        $catArr = self::splitCats($filters['category'] ?? null);
        if (!empty($catArr)) {
            $where[] = "ec.description = ANY(:cats)";
            $bind['cats'] = self::pgTextArray($catArr);
        }
        // Supplier (ผู้ผลิต/ซัพพลายเออร์ไดร์) — equipment.vendor (เป็น master ของอุปกรณ์ ไม่ใช่ vendor การเงิน)
        if (!empty($filters['vendor'])) {
            $where[] = "e.vendor = :vendor";
            $bind['vendor'] = $filters['vendor'];
        }
        // Description filter เฉพาะคอลัมน์ description (ห้องแต่งไดร์จัดกลุ่มด้วย description)
        if (!empty($filters['description'])) {
            $where[] = "e.description ILIKE :descf";
            $bind['descf'] = '%' . $filters['description'] . '%';
        }

        $sql = "
            SELECT e.equipnumber, e.description AS die_description, e.status,
                   ec.description AS equipcategory, et.description AS equiptype,
                   e.vendor AS supplier, e.brand,
                   e.f1 AS size_in, e.f2 AS size_out,
                   e.f1 AS ordered_size, e.f2 AS present_diameter,
                   e.f3 AS reduction_area_angle, e.f4 AS bearing_length,
                   e.f3, e.f4,
                   COALESCE(usg.usage_count, 0) AS usage_count,
                   COALESCE(usg.total_meter, 0) AS total_meter,
                   usg.last_used_at
            FROM equipment e
            JOIN equipcategory ec ON ec.id = e.equipcategory_id
            JOIN equiptype et     ON et.id = e.equiptype_id
            LEFT JOIN (
                -- usage / meter นับเฉพาะการใช้ผลิตจริง (USABLE + คืนเข้า floor) — meter เป็นค่า incremental ต่อรอบ
                SELECT etfi.equipment_id,
                       COUNT(*) AS usage_count,
                       COALESCE(SUM(etfi.meter), 0) AS total_meter,
                       MAX(etf.transdate) AS last_used_at
                FROM equipmenttransferitems etfi
                JOIN equipmenttransfer etf ON etf.id = etfi.trans_id
                WHERE etfi.status = 'USABLE' AND etf.to_class_id = 0
                GROUP BY etfi.equipment_id
            ) usg ON usg.equipment_id = e.id
            " . (empty($where) ? '' : 'WHERE ' . implode(' AND ', $where)) . "
            ORDER BY usg.last_used_at DESC NULLS LAST, e.equipnumber
            LIMIT 1000
        ";

        return DB::connection($connection)->select($sql, $bind);
    }

    /**
     * คู่ (die => workordernumber) ของ die ที่ระบุ — ใช้ทำ map kg ผลิตต่อ die ในหน้า master
     * กรองด้วย equipment_id (มี index) ไม่ scan ทั้งตาราง transferitems
     */
    public function getDieWorkorders(array $equipnumbers, string $connection): array
    {
        $equipnumbers = array_values(array_unique(array_filter($equipnumbers)));
        if (empty($equipnumbers)) return [];

        // WO ผลิตจริงอยู่บนแถวเบิก (from_class_id = -1) — แถวคืนงานมี f3 เป็นรหัสสเปค (เช่น RA14-16) ไม่ใช่ WO ผลิต
        $sql = "
            SELECT e.equipnumber, etfi.f3 AS workordernumber
            FROM equipment e
            JOIN equipmenttransferitems etfi ON etfi.equipment_id = e.id
            JOIN equipmenttransfer etf       ON etf.id = etfi.trans_id
            WHERE e.equipnumber = ANY(:dies)
              AND etfi.status = 'USABLE' AND etf.from_class_id = -1
              AND etfi.f3 IS NOT NULL AND etfi.f3 <> ''
            GROUP BY e.equipnumber, etfi.f3
        ";

        return DB::connection($connection)->select($sql, [
            'dies' => self::pgTextArray($equipnumbers),
        ]);
    }

    /**
     * (A) ตำแหน่ง/แผนกปัจจุบันของ die แต่ละตัว = transfer ล่าสุด (DISTINCT ON + ORDER BY transdate DESC)
     * คืน map ได้ที่ caller index ด้วย equipnumber
     */
    public function getCurrentClassByDies(array $equipnumbers, string $connection): array
    {
        $equipnumbers = array_values(array_unique(array_filter($equipnumbers)));
        if (empty($equipnumbers)) return [];

        $sql = "
            SELECT DISTINCT ON (e.id)
                e.equipnumber,
                etf.to_class_id,
                COALESCE(
                    NULLIF(cit.description, ''),
                    CASE
                        WHEN etf.to_class_id = 0  THEN 'Repair'
                        WHEN etf.to_class_id = -1 THEN 'Store'
                        ELSE NULL
                    END
                ) AS current_class,
                etf.transdate AS last_move_date
            FROM equipment e
            JOIN equipmenttransferitems etfi ON etfi.equipment_id = e.id
            JOIN equipmenttransfer etf       ON etf.id = etfi.trans_id
            LEFT JOIN classinfo cit          ON cit.id = etf.to_class_id
            WHERE e.equipnumber = ANY(:dies)
            ORDER BY e.id, etf.transdate DESC, etf.id DESC
        ";

        return DB::connection($connection)->select($sql, [
            'dies' => self::pgTextArray($equipnumbers),
        ]);
    }

    /**
     * ไดร์ที่ "กำลังถูกเบิกใช้อยู่" ทั้งหมด = transfer ล่าสุดส่งไปแผนกจริง (to_class_id > 0)
     * คืน WO ที่กำลังใช้ (f3 ของแถวเบิกล่าสุด) เพื่อนำไป map หาเครื่องในฐาน MFG
     */
    public function getDeployedDies(string $connection, array $filters = []): array
    {
        $bind = [];
        $catFilter = '';
        $catArr = self::splitCats($filters['category'] ?? null);
        if (!empty($catArr)) {
            $catFilter = ' AND ec.description = ANY(:cats)';
            $bind['cats'] = self::pgTextArray($catArr);
        }
        $qFilter = '';
        if (!empty($filters['q'])) {
            $qFilter = ' AND (e.equipnumber ILIKE :q OR e.description ILIKE :q)';
            $bind['q'] = '%' . $filters['q'] . '%';
        }
        // กรองตามวันที่ขึ้นเครื่อง (last_move_date) เพื่อลดความรก
        $dateFilter = '';
        if (!empty($filters['date_from'])) {
            $dateFilter .= ' AND t.last_move_date::date >= :df';
            $bind['df'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $dateFilter .= ' AND t.last_move_date::date <= :dt';
            $bind['dt'] = $filters['date_to'];
        }
        // เฉพาะแผนกรีด (DRAWING) — ตามเกณฑ์เดียวกับ drawingOnly() (to_class มีคำว่า DRAWING)
        $drawingFilter = !empty($filters['drawing_only'])
            ? " AND t.current_dept ILIKE '%DRAWING%'"
            : '';

        $sql = "
            SELECT t.equipnumber, t.die_description, t.equipcategory,
                   t.current_dept, t.last_move_date, t.workordernumber
            FROM (
                SELECT DISTINCT ON (e.id)
                    e.equipnumber,
                    e.description AS die_description,
                    ec.description AS equipcategory,
                    etf.to_class_id,
                    cit.description AS current_dept,
                    etf.transdate AS last_move_date,
                    etfi.f3 AS workordernumber
                FROM equipment e
                JOIN equiptype et     ON et.id = e.equiptype_id AND et.description = 'ไดร์'
                JOIN equipcategory ec ON ec.id = e.equipcategory_id
                JOIN equipmenttransferitems etfi ON etfi.equipment_id = e.id
                JOIN equipmenttransfer etf       ON etf.id = etfi.trans_id
                LEFT JOIN classinfo cit          ON cit.id = etf.to_class_id
                WHERE TRUE $catFilter $qFilter
                ORDER BY e.id, etf.transdate DESC, etf.id DESC
            ) t
            WHERE t.to_class_id > 0
            $dateFilter
            $drawingFilter
            ORDER BY t.last_move_date DESC
            LIMIT 2000
        ";

        return DB::connection($connection)->select($sql, $bind);
    }

    /**
     * (B) WO ผลิตล่าสุดต่อ die — ใช้ map ไปหาเครื่องผลิตจริงในฐาน MFG
     * เอาจากแถวเบิก (from_class_id = -1) ที่ f3 = WO ผลิตจริง (แถวคืนงาน f3 เป็นรหัสสเปค)
     */
    public function getDieLastProdWo(array $equipnumbers, string $connection): array
    {
        $equipnumbers = array_values(array_unique(array_filter($equipnumbers)));
        if (empty($equipnumbers)) return [];

        $sql = "
            SELECT DISTINCT ON (e.id)
                e.equipnumber, etfi.f3 AS workordernumber
            FROM equipment e
            JOIN equipmenttransferitems etfi ON etfi.equipment_id = e.id
            JOIN equipmenttransfer etf       ON etf.id = etfi.trans_id
            WHERE e.equipnumber = ANY(:dies)
              AND etfi.status = 'USABLE' AND etf.from_class_id = -1
              AND etfi.f3 IS NOT NULL AND etfi.f3 <> ''
            ORDER BY e.id, etf.transdate DESC, etf.id DESC
        ";

        return DB::connection($connection)->select($sql, [
            'dies' => self::pgTextArray($equipnumbers),
        ]);
    }

    public function getDieProfile(string $equipnumber, string $connection): array
    {
        // เก็บทุกประเภท transaction ไว้ใน timeline (เบิกออก / ใช้ผลิต / ส่งซ่อม)
        // - is_production = แถวคืนงาน (USABLE + to_class=0) ที่ meter เป็น incremental จริง
        // - prod_wo = WO ผลิตจริงของรอบนั้น: แถวคืนใช้ WO ของแถวเบิกก่อนหน้า (LAG), แถวเบิกใช้ f3 ตัวเอง
        //   (เพราะ f3 บนแถวคืนเป็นรหัสสเปค เช่น RA14-16 ไม่ใช่ WO ผลิต — ต้องจับคู่ถึงจะรวม kg/meter ต่อ WO ถูก)
        // - ไม่ join equipmentmvmt แล้ว เพราะ transfer คืนงานมี 2 leg ทำให้แถวซ้ำ
        $sql = "
            WITH h AS (
                SELECT etf.id, etf.transnumber, etf.transdate, etf.description AS block_desc,
                       etfi.qty, etfi.meter, etfi.f3,
                       etfi.status AS txn_status, etf.from_class_id, etf.to_class_id,
                       LAG(etfi.f3) OVER (PARTITION BY etfi.equipment_id ORDER BY etf.transdate, etf.id) AS prev_f3
                FROM equipmenttransfer etf
                JOIN equipmenttransferitems etfi ON etf.id = etfi.trans_id
                JOIN equipment e                 ON e.id = etfi.equipment_id
                WHERE e.equipnumber = :en
            )
            SELECT h.transnumber, h.transdate, h.block_desc,
                   h.qty, h.meter, h.f3 AS workordernumber,
                   h.txn_status, h.from_class_id, h.to_class_id,
                   (CASE WHEN h.txn_status = 'USABLE' AND h.to_class_id = 0 THEN 1 ELSE 0 END) AS is_production,
                   CASE
                       WHEN h.transnumber ILIKE 'EU%' THEN 'issue'
                       WHEN h.transnumber ILIKE 'ER%' THEN 'return_to_repair'
                       WHEN h.transnumber ILIKE 'EW%' THEN 'repair_complete'
                       ELSE 'other'
                   END AS txn_type,
                   CASE
                       WHEN h.to_class_id = 0    THEN h.prev_f3
                       WHEN h.from_class_id = -1 THEN h.f3
                       ELSE NULL
                   END AS prod_wo,
                   COALESCE(
                       NULLIF(cif.description, ''),
                       CASE
                           WHEN h.from_class_id = 0 THEN 'Repair'
                           WHEN h.from_class_id = -1 THEN 'Store'
                           ELSE NULL
                       END
                   ) AS from_class,
                   COALESCE(
                       NULLIF(cit.description, ''),
                       CASE
                           WHEN h.to_class_id = 0 THEN 'Repair'
                           WHEN h.to_class_id = -1 THEN 'Store'
                           ELSE NULL
                       END
                   ) AS to_class
            FROM h
            LEFT JOIN classinfo cif ON cif.id = h.from_class_id
            LEFT JOIN classinfo cit ON cit.id = h.to_class_id
            ORDER BY h.transdate DESC, h.id DESC
            LIMIT 200
        ";

        return DB::connection($connection)->select($sql, ['en' => $equipnumber]);
    }

    public function getDieInfo(string $equipnumber, string $connection): ?object
    {
        $sql = "
            SELECT e.equipnumber, e.description AS die_description, e.status,
                   ec.description AS equipcategory, et.description AS equiptype,
                   e.f1 AS size_in, e.f2 AS size_out,
                   e.f1 AS ordered_size, e.f2 AS present_diameter,
                   e.f3 AS reduction_area_angle, e.f4 AS bearing_length,
                   e.f3, e.f4
            FROM equipment e
            JOIN equipcategory ec ON ec.id = e.equipcategory_id
            JOIN equiptype et     ON et.id = e.equiptype_id
            WHERE e.equipnumber = :en
            LIMIT 1
        ";

        $rows = DB::connection($connection)->select($sql, ['en' => $equipnumber]);
        return $rows[0] ?? null;
    }

    public function getCurrentLocations(string $connection, array $filters = []): array
    {
        $where = [
            "etf.transdate >= CURRENT_DATE - (:days * INTERVAL '1 day')",
        ];
        $bind  = [
            'days' => (int) ($filters['days'] ?? 30),
        ];

        $equiptype = $filters['equiptype'] ?? 'ไดร์';
        if ($equiptype !== '' && $equiptype !== 'ALL') {
            $where[] = "et.description = :etype";
            $bind['etype'] = $equiptype;
        }

        if (!empty($filters['q'])) {
            $where[] = "(e.equipnumber ILIKE :q OR e.description ILIKE :q)";
            $bind['q'] = '%' . $filters['q'] . '%';
        }

        // หมายเหตุ: ใช้ JOIN (ไม่ใช่ LEFT JOIN) เพราะต้องการเฉพาะที่มี move ใน N วัน
        $catArr = self::splitCats($filters['category'] ?? null);
        if (!empty($catArr)) {
            $where[] = "ec.description = ANY(:cats)";
            $bind['cats'] = self::pgTextArray($catArr);
        }

        // ตำแหน่งปัจจุบัน = transfer ล่าสุดของไดร์ (DISTINCT ON + ORDER BY transdate DESC)
        // ไม่ join equipmentmvmt แล้ว (กันแถวซ้ำ) — ดึง 1 แถวต่อ transfer จาก etfi โดยตรง
        // to_class ของแถวซ่อม (-1) / คืนเข้า floor (0) ไม่มีชื่อใน classinfo → แปลงเป็น label ที่สื่อความหมาย
        $sql = "
            SELECT DISTINCT ON (e.id)
                e.equipnumber,
                e.description AS die_description,
                e.status,
                ec.description AS equipcategory,
                etf.transdate AS last_move_date,
                etf.transnumber AS last_trans,
                etfi.status AS last_txn_status,
                etf.to_class_id,
                COALESCE(
                    NULLIF(cit.description, ''),
                    CASE
                        WHEN etf.to_class_id = 0 THEN 'Repair'
                        WHEN etf.to_class_id = -1 THEN 'Store'
                        ELSE NULL
                    END
                ) AS current_class,
                etfi.f3 AS last_workorder
            FROM equipment e
            JOIN equipcategory ec ON ec.id = e.equipcategory_id
            JOIN equiptype et     ON et.id = e.equiptype_id
            JOIN equipmenttransferitems etfi ON etfi.equipment_id = e.id
            JOIN equipmenttransfer etf       ON etf.id = etfi.trans_id
            LEFT JOIN classinfo cit          ON cit.id = etf.to_class_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY e.id, etf.transdate DESC, etf.id DESC
            LIMIT 1000
        ";

        return DB::connection($connection)->select($sql, $bind);
    }

    public function getTopConsumers(string $start, string $end, string $connection, ?string $category = null): array
    {
        // โมเดล deployment: แผนก = to_class ของแถวเบิก, meter = แถวคืนถัดไป (LEAD), WO ผลิตจริง = f3 บนแถวเบิก
        // string_agg WO (คั่นด้วย chr(31) ที่ไม่มีในข้อมูล) เพื่อให้ service เอาไปหา FG kg ต่อแผนกได้
        $bind = ['s' => $start, 'e' => $end];
        $catFilter = '';
        $catArr = self::splitCats($category);
        if (!empty($catArr)) {
            $catFilter = ' AND evt.cat = ANY(:cats)';
            $bind['cats'] = self::pgTextArray($catArr);
        }

        $sql = "
            WITH evt AS (
                SELECT etfi.equipment_id, etf.id, etf.transdate,
                       etf.from_class_id, etf.to_class_id, etfi.f3, etfi.status,
                       e.equipnumber, ec.description AS cat,
                       LEAD(etfi.meter)      OVER w AS next_meter,
                       LEAD(etf.to_class_id) OVER w AS next_to_class_id
                FROM equipmenttransfer etf
                JOIN equipmenttransferitems etfi ON etf.id = etfi.trans_id
                JOIN equipment e      ON e.id = etfi.equipment_id
                JOIN equiptype et     ON et.id = e.equiptype_id AND et.description = 'ไดร์'
                JOIN equipcategory ec ON ec.id = e.equipcategory_id
                WINDOW w AS (PARTITION BY etfi.equipment_id ORDER BY etf.transdate, etf.id)
            )
            SELECT COALESCE(cit.description, '(ไม่ระบุ)') AS dept,
                   COUNT(*) AS trans_count,
                   COALESCE(SUM(CASE WHEN evt.next_to_class_id = 0 THEN evt.next_meter ELSE 0 END), 0) AS total_meter,
                   COUNT(DISTINCT evt.equipnumber) AS unique_die,
                   string_agg(DISTINCT NULLIF(evt.f3, ''), chr(31)) AS wos
            FROM evt
            LEFT JOIN classinfo cit ON cit.id = evt.to_class_id
            WHERE evt.from_class_id = -1 AND evt.status = 'USABLE'
              AND evt.transdate BETWEEN :s AND :e
              $catFilter
            GROUP BY cit.description
            ORDER BY total_meter DESC
            LIMIT 10
        ";

        return DB::connection($connection)->select($sql, $bind);
    }

    /**
     * Candidate pool สำหรับ widget "ไดร์ Output สูงสุด" — ไดร์ที่ถูกเบิกไปใช้ในช่วงวันที่ พร้อม WO ผลิต + meter
     * (เรียงตาม meter แล้วตัด 80 ตัวมาเป็น candidate; service จะหา FG kg แล้ว re-sort ตาม kg เอา top 10
     *  เพราะ kg อยู่คนละ DB join ใน SQL ไม่ได้)
     */
    public function getTopOutputDies(string $start, string $end, string $connection, ?string $category = null): array
    {
        $bind = ['s' => $start, 'e' => $end];
        $catFilter = '';
        $catArr = self::splitCats($category);
        if (!empty($catArr)) {
            $catFilter = ' AND evt.cat = ANY(:cats)';
            $bind['cats'] = self::pgTextArray($catArr);
        }

        $sql = "
            WITH evt AS (
                SELECT etfi.equipment_id, etf.id, etf.transdate,
                       etf.from_class_id, etf.to_class_id, etfi.f3, etfi.status,
                       e.equipnumber, e.description AS die_description, ec.description AS cat,
                       LEAD(etfi.meter)      OVER w AS next_meter,
                       LEAD(etf.to_class_id) OVER w AS next_to_class_id
                FROM equipmenttransfer etf
                JOIN equipmenttransferitems etfi ON etf.id = etfi.trans_id
                JOIN equipment e      ON e.id = etfi.equipment_id
                JOIN equiptype et     ON et.id = e.equiptype_id AND et.description = 'ไดร์'
                JOIN equipcategory ec ON ec.id = e.equipcategory_id
                WINDOW w AS (PARTITION BY etfi.equipment_id ORDER BY etf.transdate, etf.id)
            )
            SELECT evt.equipnumber,
                   MAX(evt.die_description) AS die_description,
                   MAX(evt.cat)            AS equipcategory,
                   COALESCE(SUM(CASE WHEN evt.next_to_class_id = 0 THEN evt.next_meter ELSE 0 END), 0) AS total_meter,
                   COUNT(*)               AS usage_count,
                   string_agg(DISTINCT NULLIF(evt.f3, ''), chr(31)) AS wos
            FROM evt
            WHERE evt.from_class_id = -1 AND evt.status = 'USABLE'
              AND evt.transdate BETWEEN :s AND :e
              $catFilter
            GROUP BY evt.equipnumber
            ORDER BY total_meter DESC
            LIMIT 80
        ";

        return DB::connection($connection)->select($sql, $bind);
    }

    public function getIdleDies(int $days, string $connection, ?string $category = null): array
    {
        $sql = "
            WITH last_move AS (
                SELECT e.id, e.equipnumber, e.description AS die_description, e.status,
                       MAX(etf.transdate) AS last_move_date
                FROM equipment e
                JOIN equipcategory ec ON ec.id = e.equipcategory_id
                JOIN equiptype et ON et.id = e.equiptype_id
                LEFT JOIN equipmentmvmt em ON em.equipment_id = e.id AND em.class_id <> -1
                LEFT JOIN equipmenttransfer etf ON etf.id = em.trans_id
                WHERE et.description = 'ไดร์'
                GROUP BY e.id, e.equipnumber, e.description, e.status
            )
            SELECT equipnumber, die_description, status, last_move_date,
                   CASE WHEN last_move_date IS NULL THEN NULL
                        ELSE (CURRENT_DATE - last_move_date) END AS days_idle
            FROM last_move
            WHERE last_move_date IS NULL OR last_move_date < CURRENT_DATE - (:days * INTERVAL '1 day')
            ORDER BY last_move_date ASC NULLS FIRST
            LIMIT 50
        ";

        $bind = ['days' => $days];
        $catArr = self::splitCats($category);
        if (!empty($catArr)) {
            $sql = str_replace('GROUP BY e.id, e.equipnumber, e.description, e.status', 'AND ec.description = ANY(:cats)
                GROUP BY e.id, e.equipnumber, e.description, e.status', $sql);
            $bind['cats'] = self::pgTextArray($catArr);
        }

        return DB::connection($connection)->select($sql, $bind);
    }

    /**
     * Base query แบบ "deployment event": 1 แถว = 1 ครั้งที่เบิกไดร์ไปใช้ผลิต
     *
     * เหตุผล: ใน ERP เลข WO ผลิตจริง (เช่น N2600137) อยู่บนแถว "เบิกออก" (from_class_id = -1 → workcenter)
     * แต่ "เมตรที่ใช้จริงของรอบนั้น" (incremental) อยู่บนแถว "คืนงาน" ถัดไป (to_class_id = 0)
     * จึงใช้ LEAD() จับคู่ เบิก→คืน: แสดง WO + แผนกปลายทางจากแถวเบิก, meter จากแถวคืน
     * (วิธีนี้ทำให้ WO ผลิตกลับมาแสดง แทนที่จะเหลือแต่ f3 บนแถวคืน เช่น RA14-16 ที่ไม่ใช่ WO ผลิต)
     */
    private function baseSelect(): string
    {
        return <<<SQL
WITH evt AS (
    SELECT
        etfi.equipment_id,
        etf.id                  AS trans_id,
        etf.transnumber,
        etf.transdate,
        etf.description         AS block_desc,
        etf.requester_id,
        etf.employee_id,
        etf.updatedby,
        etfi.qty,
        etfi.meter,
        etfi.status             AS txn_status,
        etfi.f3,
        etf.from_class_id,
        etf.to_class_id,
        LEAD(etfi.meter)      OVER w AS next_meter,
        LEAD(etf.to_class_id) OVER w AS next_to_class_id
    FROM equipmenttransfer etf
    JOIN equipmenttransferitems etfi ON etf.id = etfi.trans_id
    JOIN equipment  e0  ON e0.id  = etfi.equipment_id
    JOIN equiptype  et0 ON et0.id = e0.equiptype_id AND et0.description = 'ไดร์'
    WINDOW w AS (PARTITION BY etfi.equipment_id ORDER BY etf.transdate, etf.id)
)
SELECT
    evt.f3                                                          AS workordernumber,
    NULLIF((regexp_match(evt.block_desc, '(?i)(?:^|[^A-Za-z0-9])(?:block|b)[[:space:]]*([0-9]{1,2})(?:[^0-9]|$)'))[1], '')::int AS block_no,
    evt.transnumber,
    evt.transdate,
    evt.block_desc,
    evt.qty,
    -- meter ของรอบผลิต = meter ของแถว "คืนงาน" ถัดไป (next_to_class_id = 0); ถ้ายังไม่คืน = 0
    CASE WHEN evt.next_to_class_id = 0 THEN evt.next_meter ELSE 0 END AS meter,
    evt.requester_id,
    req.name                                                         AS requester_name,
    evt.employee_id,
    emp.name                                                         AS issued_by_name,
    evt.updatedby                                                    AS updated_by_id,
    upd.name                                                         AS updated_by_name,
    e.equipnumber,
    e.description                                                    AS die_description,
    et.description                                                   AS equiptype,
    ec.description                                                   AS equipcategory,
    e.f1                                                             AS size_in,
    e.f2                                                             AS size_out,
    e.f1                                                             AS ordered_size,
    e.f2                                                             AS present_diameter,
    e.f3                                                             AS reduction_area_angle,
    e.f4                                                             AS bearing_length,
    e.f3                                                             AS extra_f3,
    e.f4                                                             AS extra_f4,
    e.status,
    evt.txn_status,
    evt.from_class_id,
    evt.to_class_id,
    1                                                               AS is_production,
    'issue'                                                         AS txn_type,
    cif.description                                                  AS from_class,
    cit.description                                                  AS to_class
FROM evt
JOIN equipment e                 ON e.id = evt.equipment_id
JOIN equiptype et                ON et.id = e.equiptype_id
JOIN equipcategory ec            ON ec.id = e.equipcategory_id
LEFT JOIN classinfo cif          ON cif.id = evt.from_class_id
LEFT JOIN classinfo cit          ON cit.id = evt.to_class_id
LEFT JOIN employee req           ON req.id = evt.requester_id
LEFT JOIN employee emp           ON emp.id = evt.employee_id
LEFT JOIN employee upd           ON upd.id = evt.updatedby
WHERE evt.from_class_id = -1 AND evt.txn_status = 'USABLE'
SQL;
    }
}

<?php

namespace App\Services\FormWOS;

use Illuminate\Support\Facades\DB;

class InvoicePackingDocumentService
{
    private const CONNECTIONS = [
        'WIRE' => 'pgsqlw',
        'PLUS' => 'pgsqlp',
    ];

    public static function normalizeInvoiceNumbers(string $value): array
    {
        return collect(preg_split('/[\s,;]+/', strtoupper(trim($value))) ?: [])
            ->map(fn ($number) => trim((string) $number))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public static function pages(array $rows, int $perPage = 5): array
    {
        return array_chunk(array_values($rows), $perPage);
    }

    public static function amountInThaiText(float $amount): string
    {
        [$integerPart, $decimalPart] = explode('.', number_format($amount, 2, '.', ''));

        $readNumber = function (string $value) use (&$readNumber): string {
            $value = ltrim($value, '0');

            if ($value === '') {
                return '';
            }

            if (strlen($value) > 6) {
                return $readNumber(substr($value, 0, -6)) . 'ล้าน' . $readNumber(substr($value, -6));
            }

            $digits = ['ศูนย์', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'];
            $positions = ['', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน'];
            $result = '';
            $length = strlen($value);

            for ($index = 0; $index < $length; $index++) {
                $digit = (int) $value[$index];

                if ($digit === 0) {
                    continue;
                }

                $position = $length - $index - 1;

                if ($position === 0 && $digit === 1 && $length > 1) {
                    $result .= 'เอ็ด';
                } elseif ($position === 1 && $digit === 1) {
                    $result .= 'สิบ';
                } elseif ($position === 1 && $digit === 2) {
                    $result .= 'ยี่สิบ';
                } else {
                    $result .= $digits[$digit] . $positions[$position];
                }
            }

            return $result;
        };

        $bahtText = $readNumber($integerPart) ?: 'ศูนย์';

        return $decimalPart === '00'
            ? $bahtText . 'บาทถ้วน'
            : $bahtText . 'บาท' . $readNumber($decimalPart) . 'สตางค์';
    }

    public static function materialType(?string $description): string
    {
        $description = strtoupper(trim((string) $description));

        if (str_contains($description, 'CARBON')) {
            return 'เพลาเหล็ก';
        }

        if (str_contains($description, 'STAINLESS')
            || preg_match('/\bSUS\s*[0-9]/', $description)
            || preg_match('/\b(?:3|4)[0-9]{2}[A-Z]?\b/', $description)) {
            return 'เพลาสแตนเลส';
        }

        return '';
    }

    public function lookup(array $invoiceNumbers): array
    {
        if ($invoiceNumbers === []) {
            return [];
        }

        $rows = collect();

        foreach (self::CONNECTIONS as $site => $connection) {
            $rows = $rows->concat($this->lookupConnection($connection, $site, $invoiceNumbers));
        }

        $invoiceOrder = array_flip($invoiceNumbers);

        return $rows
            ->sortBy(fn ($row) => sprintf(
                '%04d|%s|%020d',
                $invoiceOrder[strtoupper(trim((string) $row->invoice_no))] ?? 9999,
                $row->site,
                (int) $row->invoice_line_id
            ))
            ->values()
            ->all();
    }

    private function lookupConnection(string $connection, string $site, array $invoiceNumbers): array
    {
        $placeholders = implode(',', array_fill(0, count($invoiceNumbers), '?'));
        $sql = <<<SQL
SELECT
    ? AS site,
    ar.id AS ar_id,
    ar.invnumber AS invoice_no,
    ar.transdate AS invoice_date,
    ar.refnumber AS customer_po,
    customer.customernumber AS customer_code,
    customer.name AS customer_name,
    customer.f5 AS customer_province,
    dm.ordnumber AS so_no,
    dm.dmnumber AS packing_list_no,
    i.id AS invoice_line_id,
    p.partnumber,
    COALESCE(NULLIF(TRIM(i.description), ''), p.description) AS description,
    i.qty,
    i.unit,
    COUNT(DISTINCT su.id) AS package_qty,
    STRING_AGG(DISTINCT NULLIF(TRIM(su.serialnumber), ''), ', ') AS package_numbers,
    ROUND(SUM(ABS(sm.qty))::numeric, 2) AS net_weight_kg,
    ROUND(SUM(
        CASE
            WHEN TRIM(COALESCE(su.f1, '')) ~ '^[0-9]+([.][0-9]+)?$'
                THEN TRIM(su.f1)::numeric
            ELSE 0
        END
    )::numeric, 2) AS gross_weight_kg,
    ROUND((SUM(
        CASE
            WHEN TRIM(COALESCE(su.f1, '')) ~ '^[0-9]+([.][0-9]+)?$'
                THEN TRIM(su.f1)::numeric
            ELSE 0
        END
    ) - SUM(ABS(sm.qty)))::numeric, 2) AS package_weight_kg,
    i.sellprice AS unit_price,
    ROUND((i.qty * i.sellprice)::numeric, 2) AS line_amount,
    ar.curr AS currency
FROM ar
JOIN invoice i
    ON i.trans_id = ar.id
LEFT JOIN parts p
    ON p.id = i.parts_id
LEFT JOIN customer
    ON customer.id = ar.customer_id
JOIN delivery d
    ON d.ar_id = ar.id
JOIN deliveryitems di
    ON di.delivery_id = d.id
   AND di.invoice_id = i.id
JOIN oe
    ON oe.id = d.ord_id
JOIN dm
    ON dm.ordnumber = oe.ordnumber
   AND dm.transdate = ar.transdate
JOIN dmitems dmi
    ON dmi.trans_id = dm.id
   AND dmi.orderitems_id = di.orderitems_id
JOIN serializeunitsmvmt sm
    ON sm.trans_id = dm.id
   AND sm.invoice_id = dmi.id
JOIN serializeunits su
    ON su.id = sm.su_id
WHERE UPPER(TRIM(ar.invnumber)) IN ($placeholders)
GROUP BY
    ar.id,
    ar.invnumber,
    ar.transdate,
    ar.refnumber,
    ar.curr,
    customer.customernumber,
    customer.name,
    customer.f5,
    dm.id,
    dm.ordnumber,
    dm.dmnumber,
    i.id,
    p.partnumber,
    i.description,
    p.description,
    i.qty,
    i.unit,
    i.sellprice
ORDER BY ar.transdate, ar.invnumber, i.id
SQL;

        return DB::connection($connection)->select($sql, array_merge([$site], $invoiceNumbers));
    }
}

<?php

namespace App\Repositories\FormDIE;

use Illuminate\Support\Facades\DB;

class SuggestRepository
{
    public static function woConnFor(string $site): string
    {
        return $site === 'P' ? 'pgsqlmfgp' : 'pgsqlmfgw';
    }

    public static function dieConnFor(string $site): string
    {
        return $site === 'P' ? 'pgsqlpcmp' : 'pgsqlpcmw';
    }

    public function suggestWorkorder(string $q, string $site = 'W'): array
    {
        $sql = "
            SELECT wo.workordernumber AS value,
                   COALESCE(wo.brand, '')   AS brand,
                   COALESCE(wo.fcat, '')    AS fcat,
                   COALESCE(wo.fsize, '')   AS fsize,
                   wo.reqdate,
                   wo.dateclose IS NULL     AS open
            FROM workorder wo
            WHERE wo.workordernumber ILIKE :q || '%'
              AND wo.approved = true
            ORDER BY wo.dateopen DESC NULLS LAST, wo.workordernumber DESC
            LIMIT 15
        ";
        return DB::connection(self::woConnFor($site))->select($sql, ['q' => $q]);
    }

    public function suggestDie(string $q, string $site = 'W'): array
    {
        $sql = "
            SELECT e.equipnumber                       AS value,
                   COALESCE(e.description, '')         AS description,
                   COALESCE(ec.description, '')        AS category,
                   COALESCE(e.f1::text, '')            AS size_in,
                   COALESCE(e.f2::text, '')            AS size_out,
                   COALESCE(e.status, '')              AS status
            FROM equipment e
            JOIN equipcategory ec ON ec.id = e.equipcategory_id
            JOIN equiptype et     ON et.id = e.equiptype_id
            WHERE e.equipnumber ILIKE :q || '%'
              AND et.description = 'ไดร์'
            ORDER BY e.equipnumber
            LIMIT 15
        ";
        return DB::connection(self::dieConnFor($site))->select($sql, ['q' => $q]);
    }

    public function suggestDescription(string $q, string $site = 'W'): array
    {
        $sql = "
            SELECT e.description AS value, COUNT(*) AS die_count
            FROM equipment e
            JOIN equiptype et ON et.id = e.equiptype_id
            WHERE et.description = 'ไดร์'
              AND NULLIF(TRIM(e.description), '') IS NOT NULL
              AND e.description ILIKE '%' || :q || '%'
            GROUP BY e.description
            ORDER BY COUNT(*) DESC, e.description
            LIMIT 15
        ";
        return DB::connection(self::dieConnFor($site))->select($sql, ['q' => $q]);
    }

    public function suggestHeat(string $q, string $site = 'W'): array
    {
        $sql = "
            SELECT DISTINCT heatno AS value
            FROM workorderbom
            WHERE heatno IS NOT NULL AND heatno ILIKE :q || '%'
            ORDER BY heatno DESC
            LIMIT 15
        ";
        return DB::connection(self::woConnFor($site))->select($sql, ['q' => $q]);
    }

    public function suggestCoil(string $q, string $site = 'W'): array
    {
        $sql = "
            SELECT DISTINCT coilno AS value
            FROM workorderreceive
            WHERE coilno IS NOT NULL AND coilno <> '' AND coilno ILIKE :q || '%'
            ORDER BY coilno DESC
            LIMIT 15
        ";
        return DB::connection(self::woConnFor($site))->select($sql, ['q' => $q]);
    }
}

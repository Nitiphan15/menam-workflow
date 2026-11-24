<?php

namespace App\Services\Po;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Read-only ERP check for POs that still wait for approval in PO Online:
 * was the PO already received / invoiced / closed / cancelled in ERP?
 * Such POs no longer open in PO Online (PoErpService only reads open, not-received POs),
 * so the approval report shows them apart instead of counting them as pending.
 *
 * Only oe / ap flags are read (no vendor or customer data).
 */
class PoErpCompletionLookup
{
    private const CONNECTIONS = [
        PoErpService::SOURCE_WIRE => 'pgsqlw',
        PoErpService::SOURCE_PLUS => 'pgsqlp',
    ];

    private const CHUNK = 500;

    /** Same date floor as PoErpService (POs before it never open in PO Online). */
    private const OPEN_FROM = '2026-04-01';

    /**
     * @param  array<int, array{ordnumber: string, site: ?string}>  $pos
     * @return array<string, string> key "site::ORDNUMBER" => Thai note, only for POs that are done in ERP
     */
    public function doneInErp(array $pos): array
    {
        $bySite = [];
        foreach ($pos as $po) {
            $site = PoErpService::normalizeSource($po['site'] ?? null) ?? PoErpService::SOURCE_WIRE;
            $bySite[$site][] = strtoupper(trim((string) $po['ordnumber']));
        }

        $done = [];
        foreach ($bySite as $site => $ordnumbers) {
            try {
                $done += $this->lookupSite($site, array_values(array_unique($ordnumbers)));
            } catch (Throwable $exception) {
                // ERP unreachable: the report still works, these POs just stay "pending".
                Log::warning('PO approval report: ERP completion lookup failed', [
                    'site' => $site,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $done;
    }

    public static function key(?string $site, string $ordnumber): string
    {
        return (PoErpService::normalizeSource($site) ?? PoErpService::SOURCE_WIRE) . '::' . strtoupper(trim($ordnumber));
    }

    /** @return array<string, string> */
    private function lookupSite(string $site, array $ordnumbers): array
    {
        $connection = DB::connection(self::CONNECTIONS[$site]);
        $done = [];

        foreach (array_chunk($ordnumbers, self::CHUNK) as $chunk) {
            $orders = $connection->table('oe')
                ->whereIn('ordnumber', $chunk)
                ->orderBy('id')
                ->get(['ordnumber', 'transdate', 'closed', 'cancelled', 'shipped_or_received'])
                ->keyBy(fn ($row) => strtoupper(trim((string) $row->ordnumber))); // latest id wins

            $invoiced = $connection->table('ap')
                ->whereIn('ordnumber', $chunk)
                ->distinct()
                ->pluck('ordnumber')
                ->map(fn ($n) => strtoupper(trim((string) $n)))
                ->flip();

            foreach ($chunk as $ordnumber) {
                $order = $orders->get($ordnumber);
                if (!$order) {
                    $done[self::key($site, $ordnumber)] = 'ไม่พบใน ERP';
                    continue;
                }

                $notes = array_filter([
                    $this->flag($order->shipped_or_received) ? 'รับของแล้ว' : null,
                    $invoiced->has($ordnumber) ? 'ตั้งหนี้ (invoice) แล้ว' : null,
                    $this->flag($order->closed) ? 'ปิดใน ERP' : null,
                    $this->flag($order->cancelled) ? 'ยกเลิกใน ERP' : null,
                    $order->transdate && (string) $order->transdate < self::OPEN_FROM ? 'PO ก่อน 04/2026' : null,
                ]);

                if ($notes) {
                    $done[self::key($site, $ordnumber)] = implode(' · ', $notes);
                }
            }
        }

        return $done;
    }

    private function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}

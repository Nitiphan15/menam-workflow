<?php

namespace App\Services\Audit;

use App\Models\UserAuditChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * บันทึกค่าก่อน/หลังการแก้ไขข้อมูลสำคัญลง user_audit_changes
 *
 * ใช้งาน:
 *   $before = AuditTrail::snapshot($model);            // ก่อนแก้
 *   ... save ...
 *   AuditTrail::updated('role', $model->id, $model->name, $before, AuditTrail::snapshot($model));
 *
 * - เก็บเฉพาะฟิลด์ที่เปลี่ยนจริง
 * - ไม่เก็บฟิลด์ลับ (password, token ฯลฯ) — ถ้าเปลี่ยนจะบันทึกแค่ว่า "เปลี่ยน"
 * - ห้าม throw ออกไป เพื่อไม่ให้การบันทึกข้อมูลหลักล้มเพราะ audit
 */
class AuditTrail
{
    /** ฟิลด์ที่ไม่ต้องเก็บ เพราะเปลี่ยนเองทุกครั้ง */
    private const IGNORED = ['updated_at', 'created_at', 'remember_token', 'last_login_at'];

    /** ฟิลด์ลับ: บันทึกแค่ว่ามีการเปลี่ยน ไม่เก็บค่า */
    private const SECRET = ['password', 'api_token', 'token', 'secret', 'signature_path'];

    /**
     * @param  Model|object|array|null  $row  Eloquent model หรือแถวจาก query builder
     */
    public static function snapshot($row): array
    {
        if ($row instanceof Model) {
            return $row->getAttributes();
        }

        return $row ? (array) $row : [];
    }

    public static function created(string $entityType, $entityId, ?string $label, array $after): void
    {
        self::write($entityType, $entityId, $label, 'created', self::diff([], $after));
    }

    public static function updated(string $entityType, $entityId, ?string $label, array $before, array $after): void
    {
        $changes = self::diff($before, $after);
        if ($changes) {
            self::write($entityType, $entityId, $label, 'updated', $changes);
        }
    }

    public static function deleted(string $entityType, $entityId, ?string $label, array $before): void
    {
        self::write($entityType, $entityId, $label, 'deleted', self::diff($before, []));
    }

    /**
     * สำหรับชุดค่าแบบ list (เช่น role/permission ที่ผูกกับผู้ใช้) — เก็บว่าเพิ่มอะไร เอาอะไรออก
     */
    public static function listChanged(string $entityType, $entityId, ?string $label, string $field, array $before, array $after): void
    {
        $norm = fn (array $v) => array_values(array_unique(array_map('strval', $v)));
        $before = $norm($before);
        $after = $norm($after);

        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        if (! $added && ! $removed) {
            return;
        }

        $changes = [];
        if ($added) {
            $changes["{$field} (เพิ่ม)"] = ['old' => null, 'new' => $added];
        }
        if ($removed) {
            $changes["{$field} (เอาออก)"] = ['old' => $removed, 'new' => null];
        }

        self::write($entityType, $entityId, $label, 'updated', $changes);
    }

    public static function diff(array $before, array $after): array
    {
        $changes = [];
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));

        foreach ($keys as $key) {
            if (in_array($key, self::IGNORED, true)) {
                continue;
            }

            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;

            if (self::same($old, $new)) {
                continue;
            }

            $changes[$key] = in_array(strtolower($key), self::SECRET, true)
                ? ['old' => $old === null ? null : '(ซ่อน)', 'new' => $new === null ? null : '(ซ่อน - มีการเปลี่ยน)']
                : ['old' => self::scalar($old), 'new' => self::scalar($new)];
        }

        return $changes;
    }

    private static function same($a, $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        // DB คืนค่าเป็น string ส่วน request เป็น int/bool -> เทียบแบบ string
        return (string) self::scalar($a) === (string) self::scalar($b);
    }

    private static function scalar($v)
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d H:i:s');
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        if (is_string($v)) {
            return mb_substr($v, 0, 500);
        }

        return is_scalar($v) || $v === null ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);
    }

    private static function write(string $entityType, $entityId, ?string $label, string $event, array $changes): void
    {
        if (! ActivityLogger::enabled() || ! self::tableExists()) {
            return;
        }

        try {
            $request = request();
            $user = auth()->user();

            DB::connection((new UserAuditChange())->getConnectionName())
                ->table('user_audit_changes')
                ->insert([
                    'user_id'      => $user?->id,
                    'user_name'    => $user?->name ? mb_substr($user->name, 0, 150) : null,
                    'entity_type'  => mb_substr($entityType, 0, 100),
                    'entity_id'    => mb_substr((string) $entityId, 0, 100),
                    'entity_label' => $label !== null ? mb_substr($label, 0, 200) : null,
                    'event'        => $event,
                    'changes'      => json_encode($changes ?: new \stdClass(), JSON_UNESCAPED_UNICODE),
                    'route_name'   => $request?->route()?->getName(),
                    'ip_address'   => $request?->ip(),
                    'changed_at'   => now(),
                ]);
        } catch (\Throwable $e) {
            Log::warning('AuditTrail failed: ' . $e->getMessage(), [
                'entity_type' => $entityType,
                'entity_id'   => (string) $entityId,
            ]);
        }
    }

    /**
     * รองรับกรณี deploy โค้ดก่อนรัน SQL 02_create_user_audit_changes.sql
     */
    public static function tableExists(): bool
    {
        static $exists = null;

        return $exists ??= Cache::remember('audit_trail.table_exists', 3600, function () {
            return Schema::connection((new UserAuditChange())->getConnectionName())
                ->hasTable('user_audit_changes');
        });
    }
}

<?php

namespace App\Services\FormDP;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

class DeliveryPlanActivityLogger
{
    public function record(
        ConnectionInterface $connection,
        string $eventType,
        int $ordId,
        array $before = [],
        array $after = [],
        ?string $reason = null,
        ?int $relatedOrdId = null,
        string $sourceModule = 'FORMDP',
        array $metadata = []
    ): void {
        $user = auth()->user();
        $request = request();
        $requestId = $request->attributes->get('formdp_audit_request_id');
        if (!$requestId) {
            $requestId = (string) Str::uuid();
            $request->attributes->set('formdp_audit_request_id', $requestId);
        }

        $connection->table('delivery_plan_activity_logs')->insert([
            'ord_id' => $ordId,
            'related_ord_id' => $relatedOrdId,
            'event_type' => strtoupper(trim($eventType)),
            'source_module' => strtoupper(trim($sourceModule)),
            'actor_id' => $user?->id,
            'actor_code' => $user?->user_code ?? $user?->login,
            'actor_name' => $user?->name,
            'reason' => $reason,
            'before_values' => $this->json($before),
            'after_values' => $this->json($after),
            'metadata' => $this->json($metadata),
            'request_id' => $requestId,
            'ip_address' => $request?->ip(),
            'user_agent' => mb_substr((string) ($request?->userAgent() ?? ''), 0, 500),
            'occurred_at' => now(),
        ]);
    }

    private function json(array $value): ?string
    {
        if ($value === []) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

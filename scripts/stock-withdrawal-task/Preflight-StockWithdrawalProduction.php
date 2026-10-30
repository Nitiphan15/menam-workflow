<?php

use App\Models\FormStock\StockWithdrawalPlanItem;
use App\Services\FormStock\StockWithdrawalPlanningService;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$requiredColumns = [
    'stock_withdrawal_plan_items' => ['planned_withdraw_date', 'queue_date', 'queue_position', 'planner_closed_at', 'is_special'],
    'stock_withdrawal_alert_logs' => ['recipient', 'item_count', 'status', 'mail_type', 'payload'],
    'stock_withdrawal_alert_log_items' => ['stock_withdrawal_alert_log_id', 'stock_withdrawal_plan_item_id', 'snapshot'],
];

$missing = [];
foreach ($requiredColumns as $table => $columns) {
    $actual = DB::connection('sqlsrv_menam')->table('INFORMATION_SCHEMA.COLUMNS')
        ->where('TABLE_NAME', $table)->pluck('COLUMN_NAME')->map(fn ($column) => strtolower((string) $column))->all();
    foreach ($columns as $column) {
        if (!in_array(strtolower($column), $actual, true)) $missing[] = $table.'.'.$column;
    }
}

$today = now('Asia/Bangkok')->toDateString();
$items = StockWithdrawalPlanItem::query()->with('plan')
    ->whereDate('queue_date', '<=', $today)
    ->whereNull('planner_closed_at')
    ->whereHas('plan', fn ($query) => $query->whereNull('cancelled_at'))
    ->get();
app(StockWithdrawalPlanningService::class)->attachWithdrawalBalances($items);
$incomplete = $items->reject(fn ($item) => $item->withdrawal_status_code === 'COMPLETED')->values();

$recipientCount = count(array_filter(array_map('trim', explode(',', (string) config('stock-withdrawal.fallback_email')))));

echo json_encode([
    'checked_at' => now('Asia/Bangkok')->format('Y-m-d H:i:s'),
    'schema_ok' => $missing === [],
    'missing_columns' => $missing,
    'queue_date' => $today,
    'incomplete_current_queue' => $incomplete->filter(fn ($item) => $item->queue_date?->toDateString() === $today)->count(),
    'incomplete_backlog_to_rollover' => $incomplete->filter(fn ($item) => $item->queue_date?->toDateString() < $today)->count(),
    'recipient_count' => $recipientCount,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;

exit($missing === [] && $recipientCount > 0 ? 0 : 1);

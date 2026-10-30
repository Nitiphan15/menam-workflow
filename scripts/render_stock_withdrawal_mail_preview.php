<?php

use App\Http\Controllers\FormStock\StockWithdrawalPlanController;
use App\Models\FormStock\StockWithdrawalPlanItem;
use App\Services\FormStock\StockWithdrawalPlanningService;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$today = now('Asia/Bangkok')->toDateString();
$service = app(StockWithdrawalPlanningService::class);

$items = StockWithdrawalPlanItem::query()
    ->with('plan')
    ->whereDate('queue_date', $today)
    ->whereNull('planner_closed_at')
    ->whereHas('plan', fn ($query) => $query->whereNull('cancelled_at'))
    ->get();

$service->attachWithdrawalBalances($items);
$items = $items->reject(fn ($item) => $item->withdrawal_status_code === 'COMPLETED')->values();

$controller = app(StockWithdrawalPlanController::class);
$method = new ReflectionMethod($controller, 'mailGroups');
$method->setAccessible(true);
$groups = $method->invoke($controller, $items);

$html = view('emails.stock-withdrawal-filtered-alert', [
    'groups' => $groups,
    'mailType' => 'AUTO',
])->render();

$output = __DIR__.'/../docs/stock-withdrawal-email-preview-real.html';
file_put_contents($output, $html);

fwrite(STDOUT, json_encode([
    'output' => realpath($output),
    'queue_date' => $today,
    'item_count' => $items->count(),
    'group_count' => count($groups),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL);

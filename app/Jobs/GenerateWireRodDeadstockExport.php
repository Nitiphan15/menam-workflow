<?php

namespace App\Jobs;

use App\Exports\FormWR\WireRodDeadstockWorkbook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class GenerateWireRodDeadstockExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 900;
    public array $backoff = [60];

    public function __construct(public int $requestId)
    {
        $this->onConnection('wr-deadstock-export')->onQueue('wr-deadstock-export');
    }

    public function handle(): void
    {
        $db = DB::connection(config('database.workflow_connection', 'sqlsrv_menam'));
        $request = $db->table('wr_ds_export_requests')->where('id', $this->requestId)->first();
        if (! $request || $request->status === 'COMPLETED') return;

        $started = now('Asia/Bangkok');
        $db->table('wr_ds_export_requests')->where('id', $this->requestId)->update(['status' => 'PROCESSING', 'started_at' => $started]);
        $filters = json_decode($request->filter_json ?: '[]', true) ?: [];
        $path = 'wr-deadstock-exports/'.$request->token.'.xlsx';
        Excel::store(new WireRodDeadstockWorkbook($filters), $path, 'local');
        $rowCount = app(\App\Services\FormWR\WireRodDeadstockQuery::class)->current($filters)->count();
        $db->table('wr_ds_export_requests')->where('id', $this->requestId)->update([
            'status' => 'COMPLETED', 'file_path' => $path, 'row_count' => $rowCount,
            'file_size' => Storage::disk('local')->size($path), 'completed_at' => now('Asia/Bangkok'),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        DB::connection(config('database.workflow_connection', 'sqlsrv_menam'))->table('wr_ds_export_requests')
            ->where('id', $this->requestId)->update([
                'status' => 'FAILED', 'error_message' => mb_substr($exception->getMessage(), 0, 1000),
                'completed_at' => now('Asia/Bangkok'),
            ]);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\OpsMonitoring\LogFileReader;
use Illuminate\Http\Request;

/**
 * หน้าดู error log (อ่านอย่างเดียว) — route อยู่ในกลุ่ม adminweb (auth + can:ADMINWEB)
 */
class ErrorLogController extends Controller
{
    public function index(Request $r, LogFileReader $reader)
    {
        $files = $reader->files();

        $filters = [
            'file'  => (string) $r->query('file', ''),
            'level' => (string) $r->query('level', 'error'),
            'q'     => mb_substr(trim((string) $r->query('q', '')), 0, 100),
            // ป้าย module เช่น DP, VC, MLA (ตัวอักษร/ตัวเลข/ขีดเท่านั้น)
            'module' => preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string) $r->query('module', ''))
                ? strtoupper((string) $r->query('module')) : '',
        ];

        if (! array_key_exists($filters['level'], LogFileReader::LEVEL_GROUPS) && $filters['level'] !== 'all') {
            $filters['level'] = 'error';
        }

        // ไม่ระบุไฟล์ = ไฟล์ laravel ล่าสุด
        if ($filters['file'] === '') {
            $filters['file'] = collect($files)->keys()->first(fn ($name) => str_starts_with($name, 'laravel')) ?? '';
        }

        $path = $reader->resolve($filters['file']);
        if ($filters['file'] !== '' && $path === null) {
            abort(404);
        }

        $result = $path
            ? $reader->entries($path, [
                'level' => $filters['level'] === 'all' ? '' : $filters['level'],
                'q' => $filters['q'],
                'module' => $filters['module'],
            ])
            : ['entries' => [], 'counts' => [], 'modules' => [], 'truncated' => false, 'read_bytes' => 0];

        return view('adminweb.error_logs.index', [
            'files' => $files,
            'filters' => $filters,
            'entries' => $result['entries'],
            'counts' => $result['counts'],
            'modules' => $result['modules'] ?? [],
            'truncated' => $result['truncated'],
            'readBytes' => $result['read_bytes'],
        ]);
    }
}

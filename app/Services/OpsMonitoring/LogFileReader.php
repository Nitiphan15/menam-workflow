<?php

namespace App\Services\OpsMonitoring;

/**
 * อ่านไฟล์ log ใน storage/logs แบบอ่านอย่างเดียว สำหรับหน้า adminweb
 *
 * - เปิดได้เฉพาะไฟล์ใน whitelist (laravel*.log, client-errors*.log) กัน path traversal
 * - อ่านเฉพาะท้ายไฟล์ (ค่าเริ่มต้น 3MB) กันหน่วยความจำเต็มเมื่อไฟล์ใหญ่
 */
class LogFileReader
{
    public const LEVEL_GROUPS = [
        'error'   => ['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR'],
        'warning' => ['WARNING'],
        'info'    => ['NOTICE', 'INFO'],
        'debug'   => ['DEBUG'],
    ];

    private const FILE_PATTERNS = ['laravel*.log', 'client-errors*.log'];

    private const ENTRY_DETAIL_LIMIT = 20000;

    public function directory(): string
    {
        return storage_path('logs');
    }

    /**
     * @return array<string, array{name: string, size: int, modified: int}>  เรียงไฟล์ใหม่สุดก่อน
     */
    public function files(): array
    {
        $files = [];
        foreach (self::FILE_PATTERNS as $pattern) {
            foreach (glob($this->directory() . DIRECTORY_SEPARATOR . $pattern) ?: [] as $path) {
                if (! is_file($path)) {
                    continue;
                }
                $name = basename($path);
                $files[$name] = [
                    'name' => $name,
                    'size' => (int) filesize($path),
                    'modified' => (int) filemtime($path),
                ];
            }
        }

        uasort($files, fn ($a, $b) => $b['modified'] <=> $a['modified']);

        return $files;
    }

    /**
     * คืน path จริงเฉพาะเมื่อชื่อไฟล์อยู่ใน whitelist เท่านั้น
     */
    public function resolve(?string $name): ?string
    {
        if ($name === null || $name === '' || $name !== basename($name)) {
            return null;
        }

        return array_key_exists($name, $this->files())
            ? $this->directory() . DIRECTORY_SEPARATOR . $name
            : null;
    }

    /**
     * @param  array{level?: string, q?: string}  $filters
     * @return array{entries: array<int, array<string, mixed>>, counts: array<string, int>, truncated: bool, read_bytes: int}
     */
    public function entries(string $path, array $filters = [], int $maxBytes = 3_145_728, int $limit = 300): array
    {
        $size = (int) filesize($path);
        $offset = max(0, $size - $maxBytes);

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ['entries' => [], 'counts' => [], 'truncated' => false, 'read_bytes' => 0];
        }

        try {
            fseek($handle, $offset);
            $content = (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        // ไฟล์ log อาจมีข้อความ encoding เพี้ยน — ทำให้เป็น UTF-8 ที่ถูกต้องก่อนแสดงผล
        $content = mb_scrub($content, 'UTF-8');

        if ($offset > 0) {
            // ตัดส่วนแรกที่อาจเป็น entry ครึ่ง ๆ ทิ้ง
            $firstHeader = preg_match('/^\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/m', $content, $m, PREG_OFFSET_CAPTURE)
                ? $m[0][1] : strlen($content);
            $content = substr($content, $firstHeader);
        }

        $chunks = preg_split('/^(?=\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/m', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $levelFilter = self::LEVEL_GROUPS[$filters['level'] ?? ''] ?? null;
        $keyword = trim((string) ($filters['q'] ?? ''));

        $counts = array_fill_keys(array_keys(self::LEVEL_GROUPS), 0);
        $entries = [];

        for ($i = count($chunks) - 1; $i >= 0; $i--) {
            $entry = $this->parse($chunks[$i]);
            if ($entry === null) {
                continue;
            }

            $counts[$entry['group']] = ($counts[$entry['group']] ?? 0) + 1;

            if (count($entries) >= $limit) {
                continue; // ยังนับจำนวนต่อ แต่ไม่เก็บรายการเพิ่ม
            }
            if ($levelFilter !== null && ! in_array($entry['level'], $levelFilter, true)) {
                continue;
            }
            if ($keyword !== '' && mb_stripos($entry['raw'], $keyword) === false) {
                continue;
            }

            unset($entry['raw']);
            $entries[] = $entry;
        }

        return [
            'entries' => $entries,
            'counts' => $counts,
            'truncated' => $offset > 0,
            'read_bytes' => $size - $offset,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parse(string $chunk): ?array
    {
        $chunk = rtrim($chunk);
        $newline = strpos($chunk, "\n");
        $firstLine = $newline === false ? $chunk : substr($chunk, 0, $newline);

        if (! preg_match('/^\[([^\]]+)\]\s+([\w-]+)\.(\w+):\s?(.*)$/u', rtrim($firstLine, "\r"), $m)) {
            return null;
        }

        $level = strtoupper($m[3]);
        $group = 'debug';
        foreach (self::LEVEL_GROUPS as $name => $levels) {
            if (in_array($level, $levels, true)) {
                $group = $name;
                break;
            }
        }

        [$message, $context] = $this->splitContext($m[4]);

        $detail = $newline === false ? '' : trim(substr($chunk, $newline + 1));

        return [
            'time' => $m[1],
            'env' => $m[2],
            'level' => $level,
            'group' => $group,
            'message' => mb_substr($message, 0, 1000),
            'context' => $this->summarizeContext($context),
            'detail' => mb_substr($detail, 0, self::ENTRY_DETAIL_LIMIT),
            'exception' => is_array($context) && isset($context['exception']) ? mb_substr((string) $context['exception'], 0, 2000) : null,
            'raw' => $chunk,
        ];
    }

    /**
     * Laravel ต่อ context เป็น JSON ท้ายบรรทัดแรก:
     *   Log::warning   -> msg {"site":"WIRE","error":"..."}            (จบในบรรทัดเดียว)
     *   exception      -> msg {"userId":1,"exception":"[object] (...)   (JSON ต่อยาวไปหลายบรรทัดพร้อม stack trace)
     *
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    private function splitContext(string $line): array
    {
        $pos = strpos($line, ' {"');
        if ($pos === false) {
            return [$line, null];
        }

        $message = substr($line, 0, $pos);
        $json = preg_replace('/\s*\[\]\s*$/', '', substr($line, $pos + 1)) ?? '';

        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            return [$message, $decoded];
        }

        // JSON ยังไม่จบในบรรทัดนี้ (มี exception) -> แยกส่วนก่อน "exception" มา decode
        $context = [];
        $exPos = strpos($json, '"exception":');
        $head = $exPos === false ? $json : rtrim(substr($json, 0, $exPos), ',');
        $headDecoded = json_decode(rtrim($head, ',') . '}', true);
        if (is_array($headDecoded)) {
            $context = $headDecoded;
        }
        if ($exPos !== false) {
            $context['exception'] = stripcslashes(trim(substr($json, $exPos + strlen('"exception":')), '"'));
        }

        return [$message, $context ?: null];
    }

    /**
     * ดึงเฉพาะ field ที่อ่านง่ายจาก context (Handler::context() + Log::warning([...]))
     *
     * @return array<string, string>
     */
    private function summarizeContext(?array $context): array
    {
        if (! $context) {
            return [];
        }

        $out = [];
        foreach ($context as $key => $value) {
            if ($key === 'exception') {
                continue;
            }
            $out[(string) $key] = mb_substr(is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 300);
        }

        return $out;
    }
}

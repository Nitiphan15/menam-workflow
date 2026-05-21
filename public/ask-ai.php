<?php
// Small chat backend: receives {message, history}, calls Claude with tool use,
// executes tools against menam_workflow MySQL + ERP PostgreSQL, returns reply.

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// ---------- Load .env from Laravel project root ----------
function load_env(string $path): array {
    $env = [];
    if (!is_file($path)) return $env;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (!str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $v = trim($v);
        if (strlen($v) >= 2 && (($v[0] === '"' && substr($v, -1) === '"') || ($v[0] === "'" && substr($v, -1) === "'"))) {
            $v = substr($v, 1, -1);
        }
        $env[trim($k)] = $v;
    }
    return $env;
}
$env = load_env(__DIR__ . '/../.env');

// ---------- DB connections (lazy) ----------
$pdoCache = [];
function pdo_mysql(array $env): PDO {
    global $pdoCache;
    if (isset($pdoCache['mysql'])) return $pdoCache['mysql'];
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306', $env['DB_DATABASE'] ?? 'menam_workflow');
    $p = new PDO($dsn, $env['DB_USERNAME'] ?? 'root', $env['DB_PASSWORD'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ATTR_ERRMODE === PDO::ATTR_ERRMODE ? PDO::ERRMODE_EXCEPTION : PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    return $pdoCache['mysql'] = $p;
}
function pdo_pg(array $env, string $site): PDO {
    global $pdoCache;
    $key = "pg_$site";
    if (isset($pdoCache[$key])) return $pdoCache[$key];
    $prefix = strtolower($site) === 'plus' ? 'PGSQLP' : 'PGSQLW';
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s',
        $env["{$prefix}_HOST"] ?? '', $env["{$prefix}_PORT"] ?? '5432', $env["{$prefix}_DATABASE"] ?? '');
    $p = new PDO($dsn, $env["{$prefix}_USERNAME"] ?? '', $env["{$prefix}_PASSWORD"] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    return $pdoCache[$key] = $p;
}
function q_mysql(array $env, string $sql, array $params = []): array {
    $st = pdo_mysql($env)->prepare($sql); $st->execute($params); return $st->fetchAll();
}
function q_pg(array $env, string $site, string $sql, array $params = []): array {
    $st = pdo_pg($env, $site)->prepare($sql); $st->execute($params); return $st->fetchAll();
}

// ---------- Tool definitions (Anthropic schema) ----------
$TOOLS = [
    [
        'name' => 'search_customers',
        'description' => 'ค้นหาลูกค้า/บริษัทจากชื่อหรือรหัสลูกค้า (fuzzy)',
        'input_schema' => ['type' => 'object', 'properties' => [
            'keyword' => ['type' => 'string'],
            'limit' => ['type' => 'integer', 'default' => 20],
        ], 'required' => ['keyword']],
    ],
    [
        'name' => 'get_customer_by_code',
        'description' => 'ดึงข้อมูลลูกค้าจาก customer_code',
        'input_schema' => ['type' => 'object', 'properties' => [
            'customer_code' => ['type' => 'string'],
        ], 'required' => ['customer_code']],
    ],
    [
        'name' => 'list_billing_plans',
        'description' => 'ดู billing plan ทั้งหมด (รอบบิล)',
        'input_schema' => ['type' => 'object', 'properties' => new stdClass()],
    ],
    [
        'name' => 'count_customers',
        'description' => 'นับลูกค้าทั้งหมดแยกตาม ERP source',
        'input_schema' => ['type' => 'object', 'properties' => new stdClass()],
    ],
    [
        'name' => 'gl_daily_summary',
        'description' => 'สรุปยอด GL รายวันจาก ERP PostgreSQL (acc_trans). site = wire | plus',
        'input_schema' => ['type' => 'object', 'properties' => [
            'target_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD (default = today)'],
            'site' => ['type' => 'string', 'enum' => ['wire', 'plus'], 'default' => 'wire'],
        ]],
    ],
    [
        'name' => 'gl_by_account',
        'description' => 'ยอด GL วันนั้นแยกตามเลขบัญชี (chart of accounts)',
        'input_schema' => ['type' => 'object', 'properties' => [
            'target_date' => ['type' => 'string'],
            'site' => ['type' => 'string', 'enum' => ['wire', 'plus'], 'default' => 'wire'],
            'limit' => ['type' => 'integer', 'default' => 30],
        ]],
    ],
    [
        'name' => 'ar_outstanding',
        'description' => 'AR คงค้าง รวม + top N ลูกค้าที่ค้างมากสุด',
        'input_schema' => ['type' => 'object', 'properties' => [
            'site' => ['type' => 'string', 'enum' => ['wire', 'plus'], 'default' => 'wire'],
            'limit' => ['type' => 'integer', 'default' => 20],
        ]],
    ],
];

// ---------- Tool execution ----------
function run_tool(array $env, string $name, array $args) {
    switch ($name) {
        case 'search_customers':
            $like = '%' . ($args['keyword'] ?? '') . '%';
            $limit = (int)($args['limit'] ?? 20);
            return q_mysql($env,
                "SELECT id, erp_source, customer_code, customer_name, credit_days, credit_term_code, is_active
                 FROM customer_payment_terms
                 WHERE customer_name LIKE ? OR customer_code LIKE ?
                 ORDER BY is_active DESC, customer_name LIMIT $limit",
                [$like, $like]);

        case 'get_customer_by_code':
            return q_mysql($env,
                "SELECT * FROM customer_payment_terms WHERE customer_code = ? LIMIT 1",
                [$args['customer_code'] ?? '']);

        case 'list_billing_plans':
            return q_mysql($env,
                "SELECT id, code, name_th, name_en, billing_day_from, billing_day_to, is_cash, is_active
                 FROM mst_billing_plans ORDER BY code");

        case 'count_customers':
            $rows = q_mysql($env,
                "SELECT erp_source, COUNT(*) total FROM customer_payment_terms
                 WHERE is_active = 1 GROUP BY erp_source");
            $total = array_sum(array_column($rows, 'total'));
            return ['total' => $total, 'by_source' => $rows];

        case 'gl_daily_summary':
            $d = $args['target_date'] ?? date('Y-m-d');
            $site = $args['site'] ?? 'wire';
            $rows = q_pg($env, $site,
                "SELECT COUNT(*) AS trans_count,
                        COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS debit_total,
                        COALESCE(SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END), 0) AS credit_total,
                        COALESCE(SUM(amount), 0) AS net_amount,
                        COALESCE(SUM(ABS(amount)), 0) AS gross_volume
                 FROM acc_trans WHERE transdate = ?", [$d]);
            return ['site' => $site, 'date' => $d] + ($rows[0] ?? []);

        case 'gl_by_account':
            $d = $args['target_date'] ?? date('Y-m-d');
            $site = $args['site'] ?? 'wire';
            $limit = (int)($args['limit'] ?? 30);
            return q_pg($env, $site,
                "SELECT c.accno, c.description AS account_name, COUNT(*) AS trans_count,
                        COALESCE(SUM(at.amount), 0) AS net_amount,
                        COALESCE(SUM(ABS(at.amount)), 0) AS gross_volume
                 FROM acc_trans at JOIN chart c ON c.id = at.chart_id
                 WHERE at.transdate = ?
                 GROUP BY c.accno, c.description
                 ORDER BY gross_volume DESC LIMIT $limit", [$d]);

        case 'ar_outstanding':
            $site = $args['site'] ?? 'wire';
            $limit = (int)($args['limit'] ?? 20);
            $sum = q_pg($env, $site,
                "SELECT COUNT(*) AS invoice_count,
                        COALESCE(SUM(amount - paid), 0) AS outstanding_total
                 FROM ar WHERE amount - paid > 0");
            $top = q_pg($env, $site,
                "SELECT c.customernumber, c.name AS customer_name,
                        COUNT(*) AS open_invoices,
                        COALESCE(SUM(a.amount - a.paid), 0) AS outstanding
                 FROM ar a JOIN customer c ON c.id = a.customer_id
                 WHERE a.amount - a.paid > 0
                 GROUP BY c.customernumber, c.name
                 ORDER BY outstanding DESC LIMIT $limit");
            return ['site' => $site, 'summary' => $sum[0] ?? [], 'top_customers' => $top];
    }
    throw new RuntimeException("Unknown tool: $name");
}

// ---------- Anthropic API call ----------
function anthropic_call(string $apiKey, array $payload): array {
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 120,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) throw new RuntimeException('cURL: ' . curl_error($ch));
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($resp, true);
    if ($code >= 400) throw new RuntimeException("Anthropic HTTP $code: " . ($data['error']['message'] ?? $resp));
    return $data;
}

// ---------- Main: chat loop ----------
try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $userMsg = trim((string)($input['message'] ?? ''));
    $history = $input['history'] ?? [];
    if ($userMsg === '') throw new RuntimeException('empty message');

    $apiKey = $env['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY');
    if (!$apiKey) throw new RuntimeException('ANTHROPIC_API_KEY ไม่ได้ตั้งใน .env');

    $messages = array_values(array_filter($history, fn($m) => is_array($m) && isset($m['role'], $m['content'])));
    $messages[] = ['role' => 'user', 'content' => $userMsg];

    $system = "คุณเป็นผู้ช่วยตอบข้อมูลของระบบ menam_workflow ตอบเป็นภาษาไทย กระชับ ตรงประเด็น "
            . "ใช้ tool ที่มีเพื่อค้นข้อมูลจริงเสมอ ถ้าไม่พบข้อมูลให้บอกตรง ๆ ห้ามแต่งข้อมูล "
            . "ถ้าผู้ใช้ไม่ระบุ site ให้ใช้ wire เป็น default";

    for ($i = 0; $i < 8; $i++) {
        $resp = anthropic_call($apiKey, [
            'model' => 'claude-haiku-4-5-20251001',
            'max_tokens' => 2048,
            'system' => $system,
            'tools' => $TOOLS,
            'messages' => $messages,
        ]);

        if (($resp['stop_reason'] ?? '') !== 'tool_use') {
            $text = '';
            foreach ($resp['content'] ?? [] as $b) if (($b['type'] ?? '') === 'text') $text .= $b['text'];
            echo json_encode(['reply' => $text], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $messages[] = ['role' => 'assistant', 'content' => $resp['content']];
        $toolResults = [];
        foreach ($resp['content'] as $b) {
            if (($b['type'] ?? '') !== 'tool_use') continue;
            try {
                $result = run_tool($env, $b['name'], $b['input'] ?? []);
                $content = json_encode($result, JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                $content = 'Error: ' . $e->getMessage();
            }
            $toolResults[] = ['type' => 'tool_result', 'tool_use_id' => $b['id'], 'content' => $content];
        }
        $messages[] = ['role' => 'user', 'content' => $toolResults];
    }
    echo json_encode(['reply' => '(เกินจำนวนรอบ tool — ลองถามใหม่)'], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

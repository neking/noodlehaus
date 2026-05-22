<?php
declare(strict_types=1);

set_time_limit(0);
ignore_user_abort(false);
ini_set('output_buffering',        'off');
ini_set('zlib.output_compression', 'off');
ini_set('implicit_flush',          '1');
while (ob_get_level() > 0) ob_end_clean();

define('DB_HOST',    'localhost');
define('DB_PORT',    '3306');
define('DB_NAME',    'noodlehaus');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_CHARSET', 'utf8mb4');

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Accel-Buffering: no');
header('Access-Control-Allow-Origin: *');

/* DB connect */
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET),
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    echo "event: sse_error\ndata: {\"message\":\"DB failed\"}\n\n";
    flush();
    exit;
}

/* Step 1: active orders ပြန်ပို့ */
$active = $pdo->query("
    SELECT
        kq.id          AS kds_id,
        kq.order_id,
        kq.status,
        kq.pushed_at,
        o.customer_name,
        o.special_notes,
        GROUP_CONCAT(
            JSON_OBJECT('qty', oi.qty, 'name', oi.item_name)
            ORDER BY oi.id SEPARATOR '|||'
        ) AS items_raw
    FROM kds_queue kq
    JOIN orders      o  ON o.id = kq.order_id
    JOIN order_items oi ON oi.order_id = o.id
    WHERE kq.status IN ('pending','preparing','ready')
    GROUP BY kq.id
    ORDER BY kq.pushed_at ASC
")->fetchAll();

sseOut('init', array_values(array_map('buildOrder', $active)));

/* Step 2: lastId = DB ထဲ max id (served ပါ) — ဒါမှ new order ကျော်မသွား */
$lastId = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM kds_queue")->fetchColumn();

/* Step 3: polling loop */
$newStmt = $pdo->prepare("
    SELECT
        kq.id          AS kds_id,
        kq.order_id,
        kq.status,
        kq.pushed_at,
        o.customer_name,
        o.special_notes,
        GROUP_CONCAT(
            JSON_OBJECT('qty', oi.qty, 'name', oi.item_name)
            ORDER BY oi.id SEPARATOR '|||'
        ) AS items_raw
    FROM kds_queue kq
    JOIN orders      o  ON o.id = kq.order_id
    JOIN order_items oi ON oi.order_id = o.id
    WHERE kq.id > :last_id
    GROUP BY kq.id
    ORDER BY kq.id ASC
");

$pingAt = time();

while (true) {
    if (connection_aborted()) exit;

    try {
        $newStmt->execute([':last_id' => $lastId]);
        $rows = $newStmt->fetchAll();
        foreach ($rows as $row) {
            /* served မဟုတ်တာပဲ KDS ကို ပို့ */
            if (in_array($row['status'], ['pending','preparing','ready'])) {
                sseOut('new_order', buildOrder($row));
            }
            /* lastId ကိုတော့ served ပါ update — ဒါမှ loop မဝိုင်း */
            $lastId = max($lastId, (int)$row['kds_id']);
        }
    } catch (PDOException $e) {
        sleep(2);
        continue;
    }

    if (time() - $pingAt >= 15) {
        echo ": ping\n\n";
        flush();
        $pingAt = time();
    }

    sleep(1);
}

function sseOut(string $event, mixed $data): void
{
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}

function buildOrder(array $r): array
{
    $items = [];
    foreach (explode('|||', $r['items_raw'] ?? '') as $j) {
        $obj = json_decode($j, true);
        if ($obj) $items[] = $obj;
    }
    return [
        'kds_id'    => (int)$r['kds_id'],
        'order_id'  => (int)$r['order_id'],
        'order_ref' => 'NH-' . str_pad((string)$r['order_id'], 6, '0', STR_PAD_LEFT),
        'status'    => $r['status'],
        'customer'  => $r['customer_name'],
        'notes'     => $r['special_notes'] ?? '',
        'items'     => $items,
        'time'      => $r['pushed_at'],
    ];
}

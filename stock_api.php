<?php
/**
 * NoodleHaus — Stock API  (Phase 5E)
 * Endpoint: /stock_api.php?action=...
 *
 * Actions:
 *   GET  overview        — all items + stock + low alerts
 *   GET  log             — stock change history (paginated)
 *   POST adjust          — manual stock adjust (admin)
 *   POST order_deduct    — order_handler hook — auto deduct
 *
 * Rule: menu_items.stock_qty ကို UPDATE သာ — structure မထိ
 */

declare(strict_types=1);
require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$pdo    = getPDO();
$action = trim($_GET['action'] ?? '');

function ok(mixed $data = []): never {
    echo json_encode(array_merge(['ok' => true], (array)$data), JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'msg' => $msg]);
    exit;
}
function requireAdmin(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['admin'])) fail('Unauthorized', 401);
}

/* ════════════════════════════════════════════════════════════════
   GET  overview?low_threshold=10
   All menu items with stock info + low stock alerts
   ════════════════════════════════════════════════════════════════ */
if ($action === 'overview' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    requireAdmin();

    $threshold = max(1, (int)($_GET['low_threshold'] ?? 10));

    $items = $pdo->query("
        SELECT id, name, category, emoji, price, stock_qty, is_active
        FROM   menu_items
        ORDER  BY category, name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $lowStock  = [];
    $outOfStock = [];
    foreach ($items as $item) {
        $qty = (int)$item['stock_qty'];
        if ($qty <= 0) $outOfStock[] = $item;
        elseif ($qty <= $threshold) $lowStock[] = $item;
    }

    $totalItems  = count($items);
    $totalStock  = array_sum(array_column($items, 'stock_qty'));

    ok([
        'items'       => $items,
        'low_stock'   => $lowStock,
        'out_of_stock'=> $outOfStock,
        'summary'     => [
            'total_items'   => $totalItems,
            'total_stock'   => $totalStock,
            'low_count'     => count($lowStock),
            'out_count'     => count($outOfStock),
            'threshold'     => $threshold,
        ],
    ]);
}


/* ════════════════════════════════════════════════════════════════
   GET  log?item_id=&page=1&per=30
   Stock change history
   ════════════════════════════════════════════════════════════════ */
if ($action === 'log' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    requireAdmin();

    $itemId = (int)($_GET['item_id'] ?? 0);
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $per    = min(100, max(10, (int)($_GET['per'] ?? 30)));
    $offset = ($page - 1) * $per;

    $where  = '1=1';
    $params = [];
    if ($itemId) { $where = 'sl.menu_item_id = ?'; $params[] = $itemId; }

    $total = $pdo->prepare("SELECT COUNT(*) FROM stock_log sl WHERE $where");
    $total->execute($params);
    $totalRows = (int)$total->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT sl.*, mi.emoji
        FROM   stock_log sl
        LEFT JOIN menu_items mi ON mi.id = sl.menu_item_id
        WHERE  $where
        ORDER  BY sl.created_at DESC
        LIMIT  $per OFFSET $offset
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ok([
        'logs'  => $logs,
        'total' => $totalRows,
        'page'  => $page,
        'pages' => (int)ceil($totalRows / $per),
    ]);
}


/* ════════════════════════════════════════════════════════════════
   POST adjust  (admin manual)
   Body: { item_id, change_qty, reason, note, staff_name }
   ════════════════════════════════════════════════════════════════ */
if ($action === 'adjust' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();

    $d         = json_decode(file_get_contents('php://input'), true) ?? [];
    $itemId    = (int)($d['item_id']    ?? 0);
    $changeQty = (int)($d['change_qty'] ?? 0);
    $reason    = trim($d['reason']      ?? 'manual_adjust');
    $note      = trim($d['note']        ?? '');
    $staffName = trim($d['staff_name']  ?? 'Admin');

    if (!$itemId || $changeQty === 0) fail('item_id and change_qty required');

    $validReasons = ['restock','manual_adjust','waste','correction','returned'];
    if (!in_array($reason, $validReasons)) $reason = 'manual_adjust';

    $pdo->beginTransaction();
    try {
        // Get current stock
        $cur = $pdo->prepare("SELECT name, stock_qty FROM menu_items WHERE id = ? FOR UPDATE");
        $cur->execute([$itemId]);
        $item = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$item) { $pdo->rollBack(); fail('Item not found'); }

        $newQty = max(0, (int)$item['stock_qty'] + $changeQty);

        // Update stock
        $pdo->prepare("UPDATE menu_items SET stock_qty = ? WHERE id = ?")
            ->execute([$newQty, $itemId]);

        // Log
        $pdo->prepare("
            INSERT INTO stock_log (menu_item_id, item_name, change_qty, new_qty, reason, note, staff_name)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([$itemId, $item['name'], $changeQty, $newQty, $reason, $note ?: null, $staffName]);

        $pdo->commit();

        ok([
            'item_id'    => $itemId,
            'item_name'  => $item['name'],
            'old_qty'    => (int)$item['stock_qty'],
            'change_qty' => $changeQty,
            'new_qty'    => $newQty,
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        fail('Stock adjust failed: ' . $e->getMessage());
    }
}


/* ════════════════════════════════════════════════════════════════
   POST order_deduct
   order_handler.php hook — auto deduct stock on order
   Body: { order_id, items:[{item_id, name, qty}] }
   ════════════════════════════════════════════════════════════════ */
if ($action === 'order_deduct' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d       = json_decode(file_get_contents('php://input'), true) ?? [];
    $orderId = (int)($d['order_id'] ?? 0);
    $items   = $d['items'] ?? [];

    if (!$orderId || empty($items)) fail('Missing data');

    $deducted = 0;
    foreach ($items as $item) {
        $itemId = (int)($item['item_id'] ?? 0);
        $name   = trim($item['name']     ?? '');
        $qty    = max(1, (int)($item['qty'] ?? 1));
        if (!$itemId) continue;

        // Deduct stock (floor at 0)
        $pdo->prepare("UPDATE menu_items SET stock_qty = GREATEST(0, stock_qty - ?) WHERE id = ?")
            ->execute([$qty, $itemId]);

        // Get new stock
        $newQty = (int)$pdo->query("SELECT stock_qty FROM menu_items WHERE id = $itemId")->fetchColumn();

        // Log
        $pdo->prepare("
            INSERT INTO stock_log (menu_item_id, item_name, change_qty, new_qty, reason, order_id)
            VALUES (?, ?, ?, ?, 'order_deduct', ?)
        ")->execute([$itemId, $name, -$qty, $newQty, $orderId]);

        $deducted++;
    }

    ok(['deducted' => $deducted, 'order_id' => $orderId]);
}


fail('Unknown action');

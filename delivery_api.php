<?php
/**
 * NoodleHaus — Delivery API  (Phase 6C)
 * Endpoint: /delivery_api.php?action=...
 *
 * Actions:
 *   GET  pending_orders   — unassigned delivery orders
 *   GET  active           — currently active deliveries
 *   POST assign           — assign driver to order
 *   POST update_status    — driver updates delivery status
 *   GET  drivers          — all drivers
 *   POST driver_login     — driver PIN login
 *   POST driver_status    — driver set available/offline
 *   GET  driver_orders    — driver's assigned orders
 *   GET  zones            — delivery zones + fees
 *   POST zone_save        — create/update zone
 *   POST driver_save      — create/update driver
 *   GET  analytics        — delivery stats
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
   GET  pending_orders — delivery orders not yet assigned
   ════════════════════════════════════════════════════════════════ */
if ($action === 'pending_orders') {
    $rows = $pdo->query("
        SELECT o.id, o.customer_name, o.customer_phone,
               o.total_amount, o.payment_method, o.status,
               o.created_at, o.special_notes,
               SUBSTRING_INDEX(o.customer_phone, '', 1) AS address_hint
        FROM orders o
        LEFT JOIN delivery_assignments da ON da.order_id = o.id
        WHERE o.order_type = 'delivery'
          AND o.deleted_at IS NULL
          AND o.status NOT IN ('delivered','cancelled')
          AND da.id IS NULL
        ORDER BY o.created_at ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    ok(['orders' => $rows]);
}


/* ════════════════════════════════════════════════════════════════
   GET  active — current active deliveries
   ════════════════════════════════════════════════════════════════ */
if ($action === 'active') {
    $rows = $pdo->query("
        SELECT da.*, o.customer_name, o.customer_phone,
               o.total_amount, o.payment_method, o.created_at AS order_time,
               d.name AS driver_name, d.phone AS driver_phone, d.vehicle
        FROM delivery_assignments da
        JOIN orders o ON o.id = da.order_id
        JOIN drivers d ON d.id = da.driver_id
        WHERE da.status NOT IN ('delivered','failed')
          AND o.deleted_at IS NULL
        ORDER BY da.assigned_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    ok(['deliveries' => $rows]);
}


/* ════════════════════════════════════════════════════════════════
   POST assign  { order_id, driver_id }
   ════════════════════════════════════════════════════════════════ */
if ($action === 'assign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $orderId  = (int)($d['order_id']  ?? 0);
    $driverId = (int)($d['driver_id'] ?? 0);
    if (!$orderId || !$driverId) fail('order_id and driver_id required');

    // Check order exists and is delivery
    $order = $pdo->prepare("SELECT id FROM orders WHERE id=? AND order_type='delivery' AND deleted_at IS NULL");
    $order->execute([$orderId]);
    if (!$order->fetchColumn()) fail('Order not found or not delivery');

    // Check driver available
    $driver = $pdo->prepare("SELECT name FROM drivers WHERE id=? AND is_active=1");
    $driver->execute([$driverId]);
    $driverName = $driver->fetchColumn();
    if (!$driverName) fail('Driver not available');

    // Check not already assigned
    $exists = $pdo->prepare("SELECT id FROM delivery_assignments WHERE order_id=?");
    $exists->execute([$orderId]);
    if ($exists->fetchColumn()) fail('Order already assigned');

    $pdo->prepare("
        INSERT INTO delivery_assignments (order_id, driver_id) VALUES (?, ?)
    ")->execute([$orderId, $driverId]);

    // Set driver busy
    $pdo->prepare("UPDATE drivers SET status='busy' WHERE id=?")->execute([$driverId]);

    ok(['assigned' => true, 'driver_name' => $driverName]);
}


/* ════════════════════════════════════════════════════════════════
   POST update_status  { order_id, status, notes }
   ════════════════════════════════════════════════════════════════ */
if ($action === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d      = json_decode(file_get_contents('php://input'), true) ?? [];
    $orderId = (int)($d['order_id'] ?? 0);
    $status  = trim($d['status'] ?? '');
    $notes   = trim($d['notes']  ?? '') ?: null;

    if (!$orderId) fail('order_id required');
    $valid = ['assigned','picked_up','on_the_way','delivered','failed'];
    if (!in_array($status, $valid)) fail('Invalid status');

    $timeCol = match($status) {
        'picked_up' => ', picked_up_at=NOW()',
        'delivered' => ', delivered_at=NOW()',
        default     => '',
    };

    $pdo->prepare("
        UPDATE delivery_assignments
        SET status=?, notes=? $timeCol
        WHERE order_id=?
    ")->execute([$status, $notes, $orderId]);

    // If delivered, set driver available + increment counter
    if ($status === 'delivered') {
        $driverId = $pdo->prepare("SELECT driver_id FROM delivery_assignments WHERE order_id=?");
        $driverId->execute([$orderId]);
        $did = $driverId->fetchColumn();
        if ($did) {
            $pdo->prepare("UPDATE drivers SET status='available', total_deliveries=total_deliveries+1 WHERE id=?")
                ->execute([$did]);
        }
        // Update order status
        $pdo->prepare("UPDATE orders SET status='delivered' WHERE id=?")->execute([$orderId]);
    }
    if ($status === 'failed') {
        $driverId = $pdo->prepare("SELECT driver_id FROM delivery_assignments WHERE order_id=?");
        $driverId->execute([$orderId]);
        $did = $driverId->fetchColumn();
        if ($did) {
            $pdo->prepare("UPDATE drivers SET status='available' WHERE id=?")->execute([$did]);
        }
    }

    ok(['status' => $status]);
}


/* ════════════════════════════════════════════════════════════════
   GET  drivers
   ════════════════════════════════════════════════════════════════ */
if ($action === 'drivers') {
    $rows = $pdo->query("
        SELECT d.*,
            (SELECT COUNT(*) FROM delivery_assignments da
             WHERE da.driver_id=d.id AND da.status NOT IN ('delivered','failed')) AS active_orders
        FROM drivers d
        ORDER BY d.status ASC, d.name
    ")->fetchAll(PDO::FETCH_ASSOC);
    ok(['drivers' => $rows]);
}


/* ════════════════════════════════════════════════════════════════
   POST driver_login  { pin }
   ════════════════════════════════════════════════════════════════ */
if ($action === 'driver_login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d   = json_decode(file_get_contents('php://input'), true) ?? [];
    $pin = trim($d['pin'] ?? '');
    if (!$pin) fail('PIN required');

    $driver = $pdo->prepare("SELECT id, name, phone, vehicle, status FROM drivers WHERE pin=? AND is_active=1");
    $driver->execute([$pin]);
    $row = $driver->fetch(PDO::FETCH_ASSOC);
    if (!$row) fail('PIN မှားနေသည်');

    // Set online
    $pdo->prepare("UPDATE drivers SET status='available' WHERE id=?")->execute([$row['id']]);
    $row['status'] = 'available';

    ok(['driver' => $row]);
}


/* ════════════════════════════════════════════════════════════════
   POST driver_status  { driver_id, status }
   ════════════════════════════════════════════════════════════════ */
if ($action === 'driver_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['driver_id'] ?? 0);
    $status = trim($d['status'] ?? '');
    if (!$id || !in_array($status, ['available','offline'])) fail('Invalid');
    $pdo->prepare("UPDATE drivers SET status=? WHERE id=?")->execute([$status, $id]);
    ok();
}


/* ════════════════════════════════════════════════════════════════
   GET  driver_orders?driver_id=X
   ════════════════════════════════════════════════════════════════ */
if ($action === 'driver_orders') {
    $driverId = (int)($_GET['driver_id'] ?? 0);
    if (!$driverId) fail('driver_id required');

    $rows = $pdo->prepare("
        SELECT da.*, o.customer_name, o.customer_phone,
               o.total_amount, o.payment_method, o.special_notes,
               o.created_at AS order_time,
               GROUP_CONCAT(oi.item_name, ' x', oi.qty ORDER BY oi.id SEPARATOR ', ') AS items
        FROM delivery_assignments da
        JOIN orders o ON o.id = da.order_id
        LEFT JOIN order_items oi ON oi.order_id = o.id
        WHERE da.driver_id = ?
          AND da.status NOT IN ('delivered','failed')
          AND o.deleted_at IS NULL
        GROUP BY da.id
        ORDER BY da.assigned_at ASC
    ");
    $rows->execute([$driverId]);

    ok(['orders' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
}


/* ════════════════════════════════════════════════════════════════
   GET  zones
   ════════════════════════════════════════════════════════════════ */
if ($action === 'zones') {
    $rows = $pdo->query("SELECT * FROM delivery_zones ORDER BY zone_name")->fetchAll(PDO::FETCH_ASSOC);
    ok(['zones' => $rows]);
}


/* ════════════════════════════════════════════════════════════════
   POST zone_save  { id?, zone_name, fee, estimated_min }
   ════════════════════════════════════════════════════════════════ */
if ($action === 'zone_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    $d    = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = (int)($d['id'] ?? 0);
    $name = trim($d['zone_name'] ?? '');
    $fee  = max(0, (int)($d['fee'] ?? 1500));
    $est  = max(10, (int)($d['estimated_min'] ?? 30));
    if (!$name) fail('Zone name required');

    if ($id) {
        $pdo->prepare("UPDATE delivery_zones SET zone_name=?, fee=?, estimated_min=? WHERE id=?")
            ->execute([$name, $fee, $est, $id]);
    } else {
        $pdo->prepare("INSERT INTO delivery_zones (zone_name, fee, estimated_min) VALUES (?,?,?)")
            ->execute([$name, $fee, $est]);
        $id = (int)$pdo->lastInsertId();
    }
    ok(['id' => $id]);
}


/* ════════════════════════════════════════════════════════════════
   POST driver_save  { id?, name, phone, pin, vehicle }
   ════════════════════════════════════════════════════════════════ */
if ($action === 'driver_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    $d     = json_decode(file_get_contents('php://input'), true) ?? [];
    $id    = (int)($d['id'] ?? 0);
    $name  = trim($d['name'] ?? '');
    $phone = trim($d['phone'] ?? '');
    $pin   = trim($d['pin'] ?? '');
    $vehicle = trim($d['vehicle'] ?? 'motorbike');
    if (!$name || !$phone || !$pin) fail('Name, phone, pin required');

    if ($id) {
        $pdo->prepare("UPDATE drivers SET name=?, phone=?, pin=?, vehicle=? WHERE id=?")
            ->execute([$name, $phone, $pin, $vehicle, $id]);
    } else {
        $pdo->prepare("INSERT INTO drivers (name, phone, pin, vehicle) VALUES (?,?,?,?)")
            ->execute([$name, $phone, $pin, $vehicle]);
        $id = (int)$pdo->lastInsertId();
    }
    ok(['id' => $id]);
}


/* ════════════════════════════════════════════════════════════════
   GET  analytics?days=7
   ════════════════════════════════════════════════════════════════ */
if ($action === 'analytics') {
    requireAdmin();
    $days = max(1, min(90, (int)($_GET['days'] ?? 7)));

    $stats = $pdo->prepare("
        SELECT
            COUNT(*) AS total_deliveries,
            SUM(CASE WHEN status='delivered' THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed,
            AVG(CASE WHEN delivered_at IS NOT NULL AND picked_up_at IS NOT NULL
                THEN TIMESTAMPDIFF(MINUTE, picked_up_at, delivered_at) END) AS avg_delivery_min
        FROM delivery_assignments
        WHERE assigned_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    ");
    $stats->execute([$days]);
    $s = $stats->fetch(PDO::FETCH_ASSOC);

    // Top drivers
    $top = $pdo->prepare("
        SELECT d.name, d.vehicle, COUNT(*) AS deliveries
        FROM delivery_assignments da
        JOIN drivers d ON d.id = da.driver_id
        WHERE da.status = 'delivered'
          AND da.assigned_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
        GROUP BY d.id
        ORDER BY deliveries DESC
        LIMIT 5
    ");
    $top->execute([$days]);

    ok([
        'stats'       => $s,
        'top_drivers' => $top->fetchAll(PDO::FETCH_ASSOC),
        'days'        => $days,
    ]);
}


fail('Unknown action');

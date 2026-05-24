<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$orderId  = (int)($_GET['id'] ?? 0);
$deviceId = trim($_GET['device_id'] ?? '');

define('DB_HOST','localhost'); define('DB_PORT','3306');
define('DB_NAME','noodlehaus'); define('DB_USER','root'); define('DB_PASS','');

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) { echo json_encode(['success'=>false,'message'=>'DB error']); exit; }

// device_id နဲ့ most recent active order ရှာ
if ($orderId <= 0 && $deviceId) {
    $stmt = $pdo->prepare("
        SELECT id FROM orders
        WHERE device_id = :did
          AND deleted_at IS NULL
          AND status NOT IN ('delivered','cancelled')
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([':did' => $deviceId]);
    $row = $stmt->fetch();
    if ($row) $orderId = (int)$row['id'];
}
if ($orderId <= 0) { echo json_encode(['success'=>false,'message'=>'No active order']); exit; }

$order = $pdo->prepare("SELECT * FROM orders WHERE id = :id AND (deleted_at IS NULL OR status='cancelled')");
$order->execute([':id' => $orderId]);
$o = $order->fetch();
if (!$o) { echo json_encode(['success'=>false,'message'=>'Order not found']); exit; }

$items = $pdo->prepare("SELECT item_name, qty, unit_price, subtotal FROM order_items WHERE order_id = :id");
$items->execute([':id' => $orderId]);

$kds = $pdo->prepare("SELECT status, pushed_at, started_at, ready_at FROM kds_queue WHERE order_id = :id ORDER BY id DESC LIMIT 1");
$kds->execute([':id' => $orderId]);
$k = $kds->fetch();

echo json_encode([
    'success'  => true,
    'order'    => [
        'id'          => 'NH-' . str_pad((string)$o['id'], 6, '0', STR_PAD_LEFT),
        'db_id'       => (int)$o['id'],
        'status'        => $o['status'],
        'cancel_reason' => $o['delete_reason'] ?? null,
        'kds_status'    => $k['status'] ?? 'pending',
        'customer'    => $o['customer_name'],
        'phone'       => $o['customer_phone'],
        'address'     => $o['delivery_address'],
        'township'    => $o['township'],
        'city'        => $o['city'],
        'notes'       => $o['special_notes'],
        'payment'     => $o['payment_method'],
        'subtotal'    => (int)$o['subtotal'],
        'delivery_fee'=> (int)$o['delivery_fee'],
        'total'       => (int)$o['total_amount'],
        'created_at'  => $o['created_at'],
        'pushed_at'   => $k['pushed_at']  ?? null,
        'started_at'  => $k['started_at'] ?? null,
        'ready_at'    => $k['ready_at']   ?? null,
    ],
    'items' => $items->fetchAll(),
], JSON_UNESCAPED_UNICODE);

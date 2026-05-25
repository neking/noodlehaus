<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { jsonError('Method not allowed', 405); }

/* ── DB CONFIG — ဒီနေရာပြင်ပါ ── */
define('DB_HOST',    'localhost');
define('DB_PORT',    '3306');
define('DB_NAME',    'noodlehaus');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_CHARSET', 'utf8mb4');

/* ── INPUT ── */
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (json_last_error() !== JSON_ERROR_NONE) jsonError('Invalid JSON');

foreach (['customer','payment_method','items','subtotal','delivery_fee','total'] as $k) {
    if (!isset($body[$k])) jsonError("Missing: {$k}");
}

$customer      = $body['customer'];
$paymentMethod = sanitizeStr($body['payment_method']);
$items         = $body['items'];
$subtotal      = (int)$body['subtotal'];
$deliveryFee   = (int)$body['delivery_fee'];
$total         = (int)$body['total'];

$deviceId  = sanitizeStr($body['device_id'] ?? '');
$orderType = in_array(($body['order_type']??''), ['delivery','dine_in']) ? $body['order_type'] : 'delivery';
$tableId   = strtoupper(sanitizeStr($body['table_id'] ?? ''));
if ($orderType === 'dine_in' && !$tableId) $orderType = 'delivery';
$requiredFields = $orderType === 'dine_in' ? ['name'] : ['name','phone','address'];
foreach ($requiredFields as $f) {
    if (empty(trim($customer[$f] ?? ''))) jsonError("Customer field required: {$f}");
}
// Dine-in: delivery_fee = 0
if ($orderType === 'dine_in') $deliveryFee = 0;
if (empty($items) || !is_array($items)) jsonError('No items');
foreach ($items as $i => $item) {
    foreach (['item_id','qty','price'] as $f) {
        if (!isset($item[$f])) jsonError("Item[{$i}] missing: {$f}");
    }
}

$allowed = ['kpay','wavepay','cbpay','ayapay','cod','card'];
if (!in_array($paymentMethod, $allowed, true)) jsonError('Invalid payment method');

/* ── DB CONNECT ── */
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET),
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    logError('DB connect: ' . $e->getMessage());
    jsonError('Database connection failed', 503);
}

/* ── TRANSACTION ── */
try {
    $pdo->beginTransaction();

    /* 1. orders
     * Dine-in multi-round: same table + table_status='open' ရှိပြီးသားရင် append။
     * Delivery / no open table → new order create။
     */
    $orderId     = 0;
    $isAppend    = false;
    $tableStatus = $orderType === 'dine_in' ? 'open' : null;

    if ($orderType === 'dine_in' && $tableId) {
        $chk = $pdo->prepare("
            SELECT id FROM orders
            WHERE table_id = :tid
              AND table_status = 'open'
              AND deleted_at IS NULL
            ORDER BY id DESC
            LIMIT 1
        ");
        $chk->execute([':tid' => $tableId]);
        $existingId = $chk->fetchColumn();
        if ($existingId) {
            $orderId  = (int)$existingId;
            $isAppend = true;
            // subtotal / total_amount ကို accumulate လုပ်
            $pdo->prepare("
                UPDATE orders
                SET subtotal      = subtotal      + :sub,
                    total_amount  = total_amount  + :tot,
                    updated_at    = NOW()
                WHERE id = :id
            ")->execute([':sub' => $subtotal, ':tot' => $total, ':id' => $orderId]);
        }
    }

    if (!$isAppend) {
        $s = $pdo->prepare("
            INSERT INTO orders
                (customer_name, customer_phone, delivery_address, township, city,
                 special_notes, payment_method, subtotal, delivery_fee, total_amount,
                 status, device_id, order_type, table_id, table_status, created_at)
            VALUES
                (:name, :phone, :address, :township, :city,
                 :notes, :payment, :subtotal, :delivery_fee, :total,
                 'pending', :device_id, :order_type, :table_id, :table_status, NOW())
        ");
        $s->execute([
            ':name'         => sanitizeStr($customer['name']),
            ':phone'        => sanitizeStr($customer['phone']),
            ':address'      => sanitizeStr($customer['address'] ?? ''),
            ':township'     => sanitizeStr($customer['township'] ?? ''),
            ':city'         => sanitizeStr($customer['city']     ?? ''),
            ':notes'        => sanitizeStr($customer['notes']    ?? ''),
            ':payment'      => $paymentMethod,
            ':subtotal'     => $subtotal,
            ':delivery_fee' => $deliveryFee,
            ':total'        => $total,
            ':device_id'    => $deviceId,
            ':order_type'   => $orderType,
            ':table_id'     => $tableId ?: null,
            ':table_status' => $tableStatus,
        ]);
        $orderId = (int)$pdo->lastInsertId();
    }

    /* 2. order_items + stock deduction */
    $itemStmt = $pdo->prepare("
        INSERT INTO order_items
            (order_id, menu_item_id, item_name, unit_price, qty, subtotal)
        VALUES
            (:order_id, :item_id, :item_name, :item_price, :item_qty, :item_subtotal)
    ");
    $stockStmt = $pdo->prepare("
        UPDATE menu_items
        SET stock_qty = stock_qty - :qty
        WHERE id = :id AND stock_qty >= :qty_check
    ");

    foreach ($items as $item) {
        $itemId   = (int)$item['item_id'];
        $qty      = (int)$item['qty'];
        $price    = (int)$item['price'];
        $itemName = sanitizeStr($item['name'] ?? '');

        $stockStmt->execute([':qty' => $qty, ':qty_check' => $qty, ':id' => $itemId]);
        if ($stockStmt->rowCount() === 0) {
            throw new RuntimeException("Insufficient stock for: {$itemName}");
        }

        $itemStmt->execute([
            ':order_id'      => $orderId,
            ':item_id'       => $itemId,
            ':item_name'     => $itemName,
            ':item_price'    => $price,
            ':item_qty'      => $qty,
            ':item_subtotal' => $price * $qty,
        ]);
        $orderItemId = (int)$pdo->lastInsertId();

        // Get item station
        $stationRow = $pdo->prepare("SELECT station FROM menu_items WHERE id=:id");
        $stationRow->execute([':id'=>$itemId]);
        $itemStation = $stationRow->fetchColumn() ?: 'kitchen';

        // Update order_item station
        $pdo->prepare("UPDATE order_items SET station=:s WHERE id=:id")
            ->execute([':s'=>$itemStation, ':id'=>$orderItemId]);

        // Save modifier selections
        $modifiers = $item['modifiers'] ?? [];
        if (!empty($modifiers)) {
            $modStmt = $pdo->prepare("
                INSERT INTO order_item_modifiers
                    (order_item_id, group_id, option_id, group_name, label, price_add, free_text)
                VALUES
                    (:oiid, :gid, :oid, :gname, :label, :price, :txt)
            ");
            $modTotal = 0;
            foreach ($modifiers as $mod) {
                $priceAdd = (int)($mod['price_add'] ?? 0);
                $modTotal += $priceAdd;
                $modStmt->execute([
                    ':oiid'  => $orderItemId,
                    ':gid'   => $mod['group_id'] ?: null,
                    ':oid'   => $mod['option_id'] ?: null,
                    ':gname' => sanitizeStr($mod['group_name'] ?? ''),
                    ':label' => sanitizeStr($mod['label'] ?? ''),
                    ':price' => $priceAdd,
                    ':txt'   => $mod['free_text'] ? sanitizeStr($mod['free_text']) : null,
                ]);
            }
            // Update modifier_total on order_item
            $pdo->prepare("UPDATE order_items SET modifier_total=:m WHERE id=:id")
                ->execute([':m'=>$modTotal, ':id'=>$orderItemId]);
        }
    }

    /* 3. kds_queue — station အလိုက် route
     * Append mode: station row ရှိပြီးသားရင် INSERT IGNORE (duplicate မဖြစ်အောင်)
     * Status ကို 'pending' သို့ reset — kitchen မှာ ထပ်မြင်ရမည်
     */
    $stationsRes = $pdo->prepare("
        SELECT DISTINCT station FROM order_items WHERE order_id=:oid
    ");
    $stationsRes->execute([':oid'=>$orderId]);
    $stations = $stationsRes->fetchAll(PDO::FETCH_COLUMN);

    if (empty($stations)) $stations = ['kitchen'];

    foreach ($stations as $st) {
        if ($isAppend) {
            // ရှိပြီးသား row → status ကို pending reset (served ဆိုရင်တောင်)
            $ex = $pdo->prepare("
                SELECT id FROM kds_queue
                WHERE order_id=:oid AND station=:st
                LIMIT 1
            ");
            $ex->execute([':oid'=>$orderId, ':st'=>$st]);
            $kqId = $ex->fetchColumn();
            if ($kqId) {
                $pdo->prepare("
                    UPDATE kds_queue SET status='pending', pushed_at=NOW()
                    WHERE id=:id
                ")->execute([':id'=>$kqId]);
            } else {
                $pdo->prepare("
                    INSERT INTO kds_queue (order_id, station, status, pushed_at)
                    VALUES (:order_id, :station, 'pending', NOW())
                ")->execute([':order_id'=>$orderId, ':station'=>$st]);
            }
        } else {
            $pdo->prepare("
                INSERT INTO kds_queue (order_id, station, status, pushed_at)
                VALUES (:order_id, :station, 'pending', NOW())
            ")->execute([':order_id'=>$orderId, ':station'=>$st]);
        }
    }

    $pdo->commit();

} catch (RuntimeException $e) {
    $pdo->rollBack();
    jsonError($e->getMessage(), 409);
} catch (PDOException $e) {
    $pdo->rollBack();
    logError('Transaction: ' . $e->getMessage());
    jsonError('Order could not be saved', 500);
}

/* ── RESPONSE ── */
echo json_encode([
    'success'            => true,
    'order_id'           => 'NH-' . str_pad((string)$orderId, 6, '0', STR_PAD_LEFT),
    'db_id'              => $orderId,
    'message'            => $isAppend ? 'Items added to your table order' : 'Order placed successfully',
    'is_append'          => $isAppend,
    'estimated_minutes'  => 30,
]);
exit;

/* ── HELPERS ── */
function sanitizeStr(mixed $v): string {
    return htmlspecialchars(strip_tags(trim((string)($v ?? ''))), ENT_QUOTES, 'UTF-8');
}
function jsonError(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}
function logError(string $msg): void {
    $f = __DIR__ . '/logs/errors.log';
    @mkdir(dirname($f), 0755, true);
    file_put_contents($f, '['.date('Y-m-d H:i:s').'] '.$msg.PHP_EOL, FILE_APPEND);
}
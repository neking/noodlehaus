<?php
declare(strict_types=1);

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

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { jsonError('Method not allowed', 405); }

require_once __DIR__ . '/db_connect.php';
$pdo = getPDO();

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
$deviceId      = sanitizeStr($body['device_id'] ?? '');
$orderType     = in_array(($body['order_type']??''), ['delivery','dine_in']) ? $body['order_type'] : 'delivery';
$tableId       = strtoupper(sanitizeStr($body['table_id'] ?? ''));
if ($orderType === 'dine_in' && !$tableId) $orderType = 'delivery';

$requiredFields = $orderType === 'dine_in' ? ['name'] : ['name','phone','address'];
foreach ($requiredFields as $f) {
    if (empty(trim($customer[$f] ?? ''))) jsonError("Customer field required: {$f}");
}
if ($orderType === 'dine_in') $deliveryFee = 0;
if (empty($items) || !is_array($items)) jsonError('No items');
foreach ($items as $i => $item) {
    foreach (['item_id','qty','price'] as $f) {
        if (!isset($item[$f])) jsonError("Item[{$i}] missing: {$f}");
    }
}
$allowed = ['kpay','wave','wavepay','cb','cbpay','aya','ayapay','cod','cash','card'];
if (!in_array($paymentMethod, $allowed, true)) jsonError('Invalid payment method');

try {
    $pdo->beginTransaction();

    $orderId     = 0;
    $isAppend    = false;
    $tableStatus = $orderType === 'dine_in' ? 'open' : null;

    if ($orderType === 'dine_in' && $tableId) {
        $chk = $pdo->prepare("SELECT id FROM orders WHERE table_id=:tid AND table_status='open' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
        $chk->execute([':tid' => $tableId]);
        $existingId = $chk->fetchColumn();
        if ($existingId) {
            $orderId  = (int)$existingId;
            $isAppend = true;
            $pdo->prepare("UPDATE orders SET subtotal=subtotal+:sub, total_amount=total_amount+:tot, updated_at=NOW() WHERE id=:id")
                ->execute([':sub'=>$subtotal, ':tot'=>$total, ':id'=>$orderId]);
        }
    }

    if (!$isAppend) {
        $s = $pdo->prepare("
            INSERT INTO orders
                (customer_name,customer_phone,delivery_address,township,city,
                 special_notes,payment_method,subtotal,delivery_fee,total_amount,
                 status,device_id,order_type,table_id,table_status,created_at)
            VALUES
                (:name,:phone,:address,:township,:city,
                 :notes,:payment,:subtotal,:delivery_fee,:total,
                 'pending',:device_id,:order_type,:table_id,:table_status,NOW())
        ");
        $s->execute([
            ':name'         => sanitizeStr($customer['name']),
            ':phone'        => sanitizeStr($customer['phone']    ?? ''),
            ':address'      => sanitizeStr($customer['address']  ?? ''),
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

    $itemStmt  = $pdo->prepare("INSERT INTO order_items (order_id,menu_item_id,item_name,unit_price,qty,subtotal) VALUES (:order_id,:item_id,:item_name,:item_price,:item_qty,:item_subtotal)");
    $stockStmt = $pdo->prepare("UPDATE menu_items SET stock_qty=stock_qty-:qty WHERE id=:id AND stock_qty>=:qty_check");

    foreach ($items as $item) {
        $itemId   = (int)$item['item_id'];
        $qty      = (int)$item['qty'];
        $price    = (int)$item['price'];
        $itemName = sanitizeStr($item['name'] ?? '');

        $stockStmt->execute([':qty'=>$qty, ':qty_check'=>$qty, ':id'=>$itemId]);
        if ($stockStmt->rowCount() === 0) throw new RuntimeException("Insufficient stock for: {$itemName}");

        $itemStmt->execute([
            ':order_id'      => $orderId,
            ':item_id'       => $itemId,
            ':item_name'     => $itemName,
            ':item_price'    => $price,
            ':item_qty'      => $qty,
            ':item_subtotal' => $price * $qty,
        ]);
        $orderItemId = (int)$pdo->lastInsertId();

        $stRow = $pdo->prepare("SELECT station FROM menu_items WHERE id=:id");
        $stRow->execute([':id'=>$itemId]);
        $itemStation = $stRow->fetchColumn() ?: 'kitchen';
        $pdo->prepare("UPDATE order_items SET station=:s WHERE id=:id")->execute([':s'=>$itemStation, ':id'=>$orderItemId]);

        $modifiers = $item['modifiers'] ?? [];
        if (!empty($modifiers)) {
            $modStmt  = $pdo->prepare("INSERT INTO order_item_modifiers (order_item_id,group_id,option_id,group_name,label,price_add,free_text) VALUES (:oiid,:gid,:oid,:gname,:label,:price,:txt)");
            $modTotal = 0;
            foreach ($modifiers as $mod) {
                $priceAdd = (int)($mod['price_add'] ?? 0);
                $modTotal += $priceAdd;
                $modStmt->execute([
                    ':oiid'  => $orderItemId,
                    ':gid'   => $mod['group_id']  ?: null,
                    ':oid'   => $mod['option_id']  ?: null,
                    ':gname' => sanitizeStr($mod['group_name'] ?? ''),
                    ':label' => sanitizeStr($mod['label']      ?? ''),
                    ':price' => $priceAdd,
                    ':txt'   => $mod['free_text'] ? sanitizeStr($mod['free_text']) : null,
                ]);
            }
            $pdo->prepare("UPDATE order_items SET modifier_total=:m WHERE id=:id")->execute([':m'=>$modTotal, ':id'=>$orderItemId]);
        }
    }

    $stRes = $pdo->prepare("SELECT DISTINCT station FROM order_items WHERE order_id=:oid");
    $stRes->execute([':oid'=>$orderId]);
    $stations = $stRes->fetchAll(PDO::FETCH_COLUMN);
    if (empty($stations)) $stations = ['kitchen'];

    foreach ($stations as $st) {
        if ($isAppend) {
            $ex = $pdo->prepare("SELECT id FROM kds_queue WHERE order_id=:oid AND station=:st LIMIT 1");
            $ex->execute([':oid'=>$orderId, ':st'=>$st]);
            $kqId = $ex->fetchColumn();
            if ($kqId) {
                $pdo->prepare("UPDATE kds_queue SET status='pending',pushed_at=NOW() WHERE id=:id")->execute([':id'=>$kqId]);
            } else {
                $pdo->prepare("INSERT INTO kds_queue (order_id,station,status,pushed_at) VALUES (:oid,:st,'pending',NOW())")->execute([':oid'=>$orderId, ':st'=>$st]);
            }
        } else {
            $pdo->prepare("INSERT INTO kds_queue (order_id,station,status,pushed_at) VALUES (:oid,:st,'pending',NOW())")->execute([':oid'=>$orderId, ':st'=>$st]);
        }
    }

    $pdo->commit();

} catch (RuntimeException $e) {
    $pdo->rollBack();
    jsonError($e->getMessage(), 409);
} catch (PDOException $e) {
    $pdo->rollBack();
    logError('Transaction: '.$e->getMessage());
    jsonError('Order could not be saved', 500);
}

echo json_encode([
    'success'           => true,
    'order_id'          => 'NH-'.str_pad((string)$orderId, 6, '0', STR_PAD_LEFT),
    'db_id'             => $orderId,
    'message'           => $isAppend ? 'Items added to your table order' : 'Order placed successfully',
    'is_append'         => $isAppend,
    'estimated_minutes' => 30,
]);

// Server-side loyalty stamp
$customerPhone = sanitizeStr($customer['phone'] ?? '');
if (!empty($customerPhone)) {
    try {
        $cfg = $pdo->query("SELECT setting_key,setting_value FROM site_settings WHERE setting_key IN ('loyalty_enabled','loyalty_stamps_required')")->fetchAll(PDO::FETCH_KEY_PAIR);
        if (($cfg['loyalty_enabled'] ?? '1') === '1') {
            $pdo->prepare("INSERT INTO loyalty_cards(phone,stamps,last_order_id) VALUES(?,1,?)
                ON DUPLICATE KEY UPDATE stamps=stamps+1, last_order_id=?, updated_at=NOW()")
                ->execute([$customerPhone, $orderId, $orderId]);
        }
    } catch(Exception $e) { /* stamp fail သည် order ကို မထိ */ }
}

// ── Phase 5A: CRM profile sync (fire-and-forget, order ကို မထိ) ──
if (!empty($customerPhone)) {
    try {
        $crmItems = [];
        foreach ($items as $item) {
            $crmItems[] = [
                'menu_item_id' => (int)$item['item_id'],
                'item_name'    => sanitizeStr($item['name'] ?? ''),
                'qty'          => (int)$item['qty'],
            ];
        }
        $crmPayload = json_encode([
            'phone'          => $customerPhone,
            'name'           => sanitizeStr($customer['name'] ?? ''),
            'payment_method' => $paymentMethod,
            'order_id'       => $orderId,
            'total'          => $total,
            'items'          => $crmItems,
        ]);
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/json',
            'content' => $crmPayload,
            'timeout' => 2,
        ]]);
        @file_get_contents('http://localhost/crm_api.php?action=upsert', false, $ctx);
    } catch(Exception $e) { /* CRM sync fail သည် order ကို မထိ */ }
}

exit;


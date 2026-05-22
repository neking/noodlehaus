<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { jsonError('Method not allowed', 405); }

define('DB_HOST', 'localhost'); define('DB_PORT', '3306');
define('DB_NAME', 'noodlehaus'); define('DB_USER', 'root'); define('DB_PASS', '');

$body   = json_decode(file_get_contents('php://input'), true);
$kdsId  = (int)($body['kds_id'] ?? 0);
$status = trim($body['status'] ?? '');

if ($kdsId <= 0) jsonError('Invalid kds_id');
if (!in_array($status, ['preparing','ready','served'])) jsonError('Invalid status');

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) { jsonError('DB connection failed', 503); }

try {
    $extra = match($status) {
        'preparing' => ', started_at = NOW()',
        'ready'     => ', ready_at = NOW()',
        default     => ''
    };
    $s = $pdo->prepare("UPDATE kds_queue SET status = :status {$extra} WHERE id = :id");
    $s->execute([':status' => $status, ':id' => $kdsId]);
    if ($s->rowCount() === 0) jsonError('Ticket not found', 404);

    $oMap = ['preparing' => 'preparing', 'ready' => 'ready', 'served' => 'delivered'];
    $pdo->prepare("
        UPDATE orders o JOIN kds_queue kq ON kq.order_id = o.id
        SET o.status = :os WHERE kq.id = :kid
    ")->execute([':os' => $oMap[$status], ':kid' => $kdsId]);

    echo json_encode(['success' => true, 'kds_id' => $kdsId, 'status' => $status]);
} catch (PDOException $e) {
    jsonError('Update failed', 500);
}

function jsonError(string $m, int $c = 400): never {
    http_response_code($c);
    echo json_encode(['success' => false, 'message' => $m]);
    exit;
}

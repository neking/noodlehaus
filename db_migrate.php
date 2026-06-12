<?php
$key = $_GET['k'] ?? '';
if ($key !== 'nh2026migrate') { http_response_code(403); die('forbidden'); }
require_once __DIR__ . '/db_connect.php';
$pdo = getPDO();
$out = [];

// branch_stock table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS branch_stock (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        branch_id INT UNSIGNED NOT NULL,
        tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
        menu_item_id INT UNSIGNED NOT NULL,
        stock_qty INT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_bi (branch_id, menu_item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $out[] = "branch_stock: OK";
} catch(PDOException $e){ $out[] = "branch_stock ERR: ".$e->getMessage(); }

// Seed branch_stock
try {
    $pdo->exec("INSERT IGNORE INTO branch_stock (branch_id,tenant_id,menu_item_id,stock_qty)
        SELECT b.id, b.tenant_id, m.id, m.stock_qty FROM branches b
        JOIN menu_items m ON m.tenant_id=b.tenant_id WHERE m.stock_qty>0");
    $cnt = $pdo->query("SELECT COUNT(*) FROM branch_stock")->fetchColumn();
    $out[] = "branch_stock seeded: $cnt rows";
} catch(PDOException $e){ $out[] = "seed ERR: ".$e->getMessage(); }

// Add columns safely
$cols = [
    ['tables','branch_id','INT UNSIGNED NOT NULL DEFAULT 1'],
    ['tables','tenant_id','INT UNSIGNED NOT NULL DEFAULT 1'],
    ['reservations','branch_id','INT UNSIGNED NOT NULL DEFAULT 1'],
    ['reservations','tenant_id','INT UNSIGNED NOT NULL DEFAULT 1'],
    ['stock_logs','branch_id','INT UNSIGNED DEFAULT 0'],
    ['stock_logs','tenant_id','INT UNSIGNED DEFAULT 1'],
];
foreach ($cols as [$tbl,$col,$def]) {
    $exists = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$tbl' AND COLUMN_NAME='$col'")->fetchColumn();
    if (!$exists) {
        try {
            $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN `$col` $def");
            $out[] = "Added $col to $tbl";
        } catch(PDOException $e){ $out[] = "ERR $tbl.$col: ".$e->getMessage(); }
    } else {
        $out[] = "OK: $tbl.$col exists";
    }
}

header('Content-Type: text/plain');
echo implode("\n", $out) . "\nDone!";

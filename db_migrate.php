<?php
$key = $_GET['k'] ?? '';
if ($key !== 'nh2026migrate') { http_response_code(403); die('forbidden'); }
require_once __DIR__ . '/db_connect.php';
$pdo = getPDO();

function addColumnIfMissing(PDO $pdo, string $table, string $col, string $def): string {
    $exists = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND COLUMN_NAME='$col'")->fetchColumn();
    if (!$exists) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
        return "✅ Added $col to $table";
    }
    return "⏭ $col already in $table";
}

$results = [];

// 1. branch_stock table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS branch_stock (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        branch_id    INT UNSIGNED NOT NULL,
        tenant_id    INT UNSIGNED NOT NULL DEFAULT 1,
        menu_item_id INT UNSIGNED NOT NULL,
        stock_qty    INT NOT NULL DEFAULT 0,
        updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_branch_item (branch_id, menu_item_id),
        KEY idx_branch (branch_id),
        KEY idx_tenant (tenant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $results[] = "✅ branch_stock table OK";
} catch(PDOException $e) { $results[] = "⚠ branch_stock: ".$e->getMessage(); }

// 2. Seed branch_stock from existing stock_qty
try {
    $pdo->exec("INSERT IGNORE INTO branch_stock (branch_id, tenant_id, menu_item_id, stock_qty)
        SELECT b.id, b.tenant_id, m.id, m.stock_qty
        FROM branches b JOIN menu_items m ON m.tenant_id = b.tenant_id WHERE m.stock_qty > 0");
    $count = $pdo->query("SELECT COUNT(*) FROM branch_stock")->fetchColumn();
    $results[] = "✅ branch_stock seeded: $count rows";
} catch(PDOException $e) { $results[] = "⚠ seed: ".$e->getMessage(); }

// 3. tables columns
$results[] = addColumnIfMissing($pdo, 'tables',       'branch_id', 'INT UNSIGNED NOT NULL DEFAULT 1');
$results[] = addColumnIfMissing($pdo, 'tables',       'tenant_id', 'INT UNSIGNED NOT NULL DEFAULT 1');
$results[] = addColumnIfMissing($pdo, 'reservations', 'branch_id', 'INT UNSIGNED NOT NULL DEFAULT 1');
$results[] = addColumnIfMissing($pdo, 'reservations', 'tenant_id', 'INT UNSIGNED NOT NULL DEFAULT 1');
$results[] = addColumnIfMissing($pdo, 'stock_logs',   'branch_id', 'INT UNSIGNED DEFAULT 0');
$results[] = addColumnIfMissing($pdo, 'stock_logs',   'tenant_id', 'INT UNSIGNED DEFAULT 1');

foreach ($results as $r) echo $r . "\n";
echo "\n✅ Migration complete";

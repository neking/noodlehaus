<?php
// NoodleHaus DB Migration — run once
$key = $_GET['k'] ?? '';
if ($key !== 'nh2026migrate') { http_response_code(403); die('forbidden'); }

require_once __DIR__ . '/db_connect.php';
$pdo = getPDO();
$results = [];

$migrations = [
    // 1. branch_stock table for per-branch inventory
    "CREATE TABLE IF NOT EXISTS branch_stock (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        branch_id   INT UNSIGNED NOT NULL,
        tenant_id   INT UNSIGNED NOT NULL DEFAULT 1,
        menu_item_id INT UNSIGNED NOT NULL,
        stock_qty   INT NOT NULL DEFAULT 0,
        updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_branch_item (branch_id, menu_item_id),
        KEY idx_branch (branch_id),
        KEY idx_tenant (tenant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // 2. Seed branch_stock from existing menu_items.stock_qty
    "INSERT IGNORE INTO branch_stock (branch_id, tenant_id, menu_item_id, stock_qty)
     SELECT b.id, b.tenant_id, m.id, m.stock_qty
     FROM branches b
     JOIN menu_items m ON m.tenant_id = b.tenant_id
     WHERE m.stock_qty > 0",

    // 3. tables — ensure branch_id column
    "ALTER TABLE `tables` ADD COLUMN IF NOT EXISTS branch_id INT UNSIGNED NOT NULL DEFAULT 1",
    "ALTER TABLE `tables` ADD COLUMN IF NOT EXISTS tenant_id INT UNSIGNED NOT NULL DEFAULT 1",

    // 4. reservations — ensure branch_id
    "ALTER TABLE reservations ADD COLUMN IF NOT EXISTS branch_id INT UNSIGNED NOT NULL DEFAULT 1",
    "ALTER TABLE reservations ADD COLUMN IF NOT EXISTS tenant_id INT UNSIGNED NOT NULL DEFAULT 1",

    // 5. stock_logs — branch_id
    "ALTER TABLE stock_logs ADD COLUMN IF NOT EXISTS branch_id INT UNSIGNED DEFAULT 0",
    "ALTER TABLE stock_logs ADD COLUMN IF NOT EXISTS tenant_id INT UNSIGNED DEFAULT 1",
];

foreach ($migrations as $i => $sql) {
    try {
        $pdo->exec($sql);
        $results[] = "✅ Migration " . ($i+1) . " OK";
    } catch (PDOException $e) {
        $results[] = "⚠ Migration " . ($i+1) . ": " . $e->getMessage();
    }
}

// Verify
$tableCount = $pdo->query("SELECT COUNT(*) FROM branch_stock")->fetchColumn();
$results[] = "branch_stock rows: " . $tableCount;

foreach ($results as $r) echo $r . "\n";

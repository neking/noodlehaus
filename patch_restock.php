<?php
$file = '/var/www/html/admin.php';
$old = "    if (\$_GET['api'] === 'restock') {
        \$b  = json_decode(file_get_contents('php://input'), true);
        \$s  = db()->prepare(\"UPDATE menu_items SET stock_qty = stock_qty + :qty WHERE id = :id\");
        \$s->execute([':qty'=>(int)\$b['qty'], ':id'=>(int)\$b['id']]);
        echo json_encode(['ok'=>true]);
        exit;
    }";

$new = "    if (\$_GET['api'] === 'restock') {
        \$b  = json_decode(file_get_contents('php://input'), true);
        \$item_id = (int)\$b['id'];
        \$qty_add = (int)\$b['qty'];
        // get current qty and name before update
        \$cur = db()->prepare(\"SELECT name, stock_qty, unit FROM menu_items WHERE id=:id\");
        \$cur->execute([':id'=>\$item_id]);
        \$row = \$cur->fetch(PDO::FETCH_ASSOC);
        \$qty_before = (float)(\$row['stock_qty'] ?? 0);
        \$qty_after  = \$qty_before + \$qty_add;
        // update stock
        \$s = db()->prepare(\"UPDATE menu_items SET stock_qty = stock_qty + :qty WHERE id = :id\");
        \$s->execute([':qty'=>\$qty_add, ':id'=>\$item_id]);
        // write log
        require_once __DIR__.'/stock_log_api.php';
        write_stock_log(
            db(),
            \$item_id,
            \$row['name'] ?? 'Unknown',
            \$qty_add >= 0 ? 'add' : 'remove',
            \$qty_before,
            \$qty_after,
            \$row['unit'] ?? '',
            \$b['reason'] ?? 'Restock',
            (int)(\$_SESSION['user_id'] ?? 0),
            \$_SESSION['user_name'] ?? 'Admin',
            (int)(\$_SESSION['branch_id'] ?? 1),
            \$_SESSION['branch_name'] ?? ''
        );
        echo json_encode(['ok'=>true]);
        exit;
    }";

$content = file_get_contents($file);
if (strpos($content, $old) !== false) {
    file_put_contents($file, str_replace($old, $new, $content));
    echo "✅ Patch applied successfully\n";
} else {
    echo "❌ Pattern not found — check manually\n";
}

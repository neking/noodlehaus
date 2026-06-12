<?php
session_start();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/auth_helper.php';
$pdo = getPDO();
$csrfToken = generateCsrfToken();

/* ── ADMIN LOGIN ──
   Password ပြောင်းချင်ရင်:
   1. admin.php ဖွင့်ပါ
   2. ADMIN_PASS_HASH ကို PHP တွင် password_hash('yourpassword', PASSWORD_BCRYPT) ဖြင့် generate လုပ်
   3. ဒါမှမဟုတ် http://localhost/noodlehaus/genhash.php မှ copy ပါ
── */
define('ADMIN_USER', 'admin');
// bcrypt hash of 'noodlehaus2024' — genhash.php သုံးပြီး ပြောင်းနိုင်
define('ADMIN_PASS_HASH', '$2y$12$DwR3F2j7J6W7kOwP2upfF.jaE7O64EBTjimp8UO4qI2bueDIwDtV2');  // ← blank ဆိုရင် auto-set ဖြစ်မည်

/* ── DB ── */

function db(): PDO {
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function parseCsvLine(string $line): array
{
    // Strip trailing \r (Windows line endings)
    $line   = rtrim($line, "\r\n");
    $result = [];
    $len    = mb_strlen($line,'UTF-8');
    $i      = 0;
    $field  = '';

    while ($i < $len) {
        $ch = mb_substr($line,$i,1,'UTF-8');
        if ($ch === '"') {
            $i++;
            while ($i < $len) {
                $c2 = mb_substr($line,$i,1,'UTF-8');
                if ($c2 === '"' && mb_substr($line,$i+1,1,'UTF-8') === '"') {
                    $field .= '"'; $i += 2;          // escaped ""
                } elseif ($c2 === '"') {
                    $i++; break;                     // closing quote
                } else {
                    $field .= $c2; $i++;
                }
            }
        } elseif ($ch === ',') {
            $result[] = $field;                      // save field as-is (no trim — preserves Unicode)
            $field    = '';
            $i++;
        } else {
            $field .= $ch;
            $i++;
        }
    }
    $result[] = $field;

    // Trim only leading/trailing ASCII whitespace from each field (not Unicode chars)
    return array_map(fn($f) => trim($f, " \t\r\n\0\x0B"), $result);
}

function sanitize(mixed $v): string {
    return htmlspecialchars(strip_tags(trim((string)($v??''))), ENT_QUOTES, 'UTF-8');
}

/* ── HANDLE ACTIONS (JSON API) ── */
if (isset($_GET['api'])) { // GET+POST both handled
    header('Content-Type: application/json; charset=utf-8');

    /* login */
    if ($_GET['api'] === 'login') {
        $b = json_decode(file_get_contents('php://input'), true);
        $inputUser = $b['user'] ?? '';
        $inputPass = $b['pass'] ?? '';

        // ── Brute-force lockout ──────────────────────────────
        $ip        = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $lockFile  = sys_get_temp_dir() . '/nh_fail_' . md5($ip) . '.json';
        $maxFails  = 5;
        $lockMins  = 15;
        $lockData  = file_exists($lockFile) ? json_decode(file_get_contents($lockFile), true) : ['fails'=>0,'until'=>0];
        if (time() < ($lockData['until'] ?? 0)) {
            $remaining = ceil(($lockData['until'] - time()) / 60);
            echo json_encode(['ok'=>false,'msg'=>"Too many failed attempts. Try again in {$remaining} minute(s)."]);
            exit;
        }
        // ────────────────────────────────────────────────────

        $hash = ADMIN_PASS_HASH ?: password_hash('noodlehaus2024', PASSWORD_BCRYPT);
        // Demo user (read-only demo access)
        $demoHash = '$2y$12$m/erGB4KFb4H/x/f6EA/zuri0Ekl8aXe88FF6nk2kpwppTuYI0kFq';
        if ($inputUser === 'demo' && password_verify($inputPass, $demoHash)) {
            $_SESSION['admin'] = true;
            $_SESSION['demo_mode'] = true;
            $_SESSION['login_time'] = time();
            @unlink($lockFile);
            echo json_encode(['ok'=>true,'demo'=>true]);
            exit;
        }
        if ($inputUser === ADMIN_USER && password_verify($inputPass, $hash)) {
            $_SESSION['admin'] = true;
            $_SESSION['login_time'] = time();
            // Reset fail counter on success
            @unlink($lockFile);
            echo json_encode(['ok'=>true]);
        } else {
            usleep(200000);
            $lockData['fails'] = ($lockData['fails'] ?? 0) + 1;
            $remaining = $maxFails - $lockData['fails'];
            if ($lockData['fails'] >= $maxFails) {
                $lockData['until'] = time() + ($lockMins * 60);
                $lockData['fails'] = 0;
                file_put_contents($lockFile, json_encode($lockData));
                echo json_encode(['ok'=>false,'msg'=>"Too many failed attempts. Locked for {$lockMins} minutes."]);
            } else {
                file_put_contents($lockFile, json_encode($lockData));
                echo json_encode(['ok'=>false,'msg'=>"Wrong username or password. {$remaining} attempt(s) remaining."]);
            }
        }
        exit;
    }

    /* logout — auth check မတိုင်မီ စစ် */
    if ($_GET['api'] === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        echo json_encode(['ok'=>true]);
        exit;
    }

    // Auth check
    if (empty($_SESSION['admin'])) { http_response_code(401); echo json_encode(['ok'=>false,'msg'=>'Not logged in']); exit; }
    if (!empty($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > 7200) {
        $_SESSION = [];
        session_destroy();
        http_response_code(401); echo json_encode(['ok'=>false,'msg'=>'Session expired']); exit;
    }

    /* get items */
    if ($_GET['api'] === 'items') {
        $rows = db()->query("SELECT * FROM menu_items ORDER BY is_active DESC, sort_order ASC, category, name")->fetchAll();
        echo json_encode(['ok'=>true,'items'=>$rows]);
        exit;
    }

    /* add item */
    if ($_GET['api'] === 'add') {
        $b = json_decode(file_get_contents('php://input'), true);
        $s = db()->prepare("INSERT INTO menu_items (name,category,description,price,stock_qty,emoji,is_active) VALUES (:n,:c,:d,:p,:s,:e,1)");
        $s->execute([
            ':n'=>sanitize($b['name']),   ':c'=>sanitize($b['category']),
            ':d'=>sanitize($b['desc']),   ':p'=>(int)$b['price'],
            ':s'=>(int)$b['stock'],       ':e'=>sanitize($b['emoji']),
        ]);
        echo json_encode(['ok'=>true,'id'=>db()->lastInsertId()]);
        exit;
    }

    /* update item */
    if ($_GET['api'] === 'update') {
        $b = json_decode(file_get_contents('php://input'), true);
        $station = in_array($b['station']??'', ['kitchen','counter','bar','all']) ? $b['station'] : 'kitchen';
        $s = db()->prepare("UPDATE menu_items SET name=:n,category=:c,description=:d,price=:p,stock_qty=:s,emoji=:e,is_active=:a,station=:st WHERE id=:id");
        $s->execute([
            ':n'=>sanitize($b['name']),  ':c'=>sanitize($b['category']),
            ':d'=>sanitize($b['desc']), ':p'=>(int)$b['price'],
            ':s'=>(int)$b['stock'],     ':e'=>sanitize($b['emoji']),
            ':a'=>(int)$b['active'],    ':st'=>$station, ':id'=>(int)$b['id'],
        ]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    /* restock only */
    if ($_GET['api'] === 'restock') {
        $b  = json_decode(file_get_contents('php://input'), true);
        $item_id = (int)$b['id'];
        $qty_add = (int)$b['qty'];
        // get current qty and name before update
        $cur = db()->prepare("SELECT name, stock_qty, unit FROM menu_items WHERE id=:id");
        $cur->execute([':id'=>$item_id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        $qty_before = (float)($row['stock_qty'] ?? 0);
        $qty_after  = $qty_before + $qty_add;
        // update stock
        $s = db()->prepare("UPDATE menu_items SET stock_qty = stock_qty + :qty WHERE id = :id");
        $s->execute([':qty'=>$qty_add, ':id'=>$item_id]);
        // write log
        require_once __DIR__.'/stock_log_helper.php';
        write_stock_log(
            db(),
            $item_id,
            $row['name'] ?? 'Unknown',
            $qty_add >= 0 ? 'add' : 'remove',
            $qty_before,
            $qty_after,
            $row['unit'] ?? '',
            $b['reason'] ?? 'Restock',
            (int)($_SESSION['user_id'] ?? 0),
            $_SESSION['user_name'] ?? 'Admin',
            (int)($_SESSION['branch_id'] ?? 1),
            $_SESSION['branch_name'] ?? ''
        );
        echo json_encode(['ok'=>true]);
        exit;
    }

    /* toggle active */
    if ($_GET['api'] === 'toggle') {
        $b = json_decode(file_get_contents('php://input'), true);
        db()->prepare("UPDATE menu_items SET is_active = NOT is_active WHERE id=:id")->execute([':id'=>(int)$b['id']]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    /* delete */
    if ($_GET['api'] === 'delete') {
        $b = json_decode(file_get_contents('php://input'), true);
        // Soft delete — FK constraint ကြောင့် hard delete မဖြစ်
        $check = db()->prepare("SELECT COUNT(*) FROM order_items WHERE menu_item_id=:id");
        $check->execute([':id'=>(int)$b['id']]);
        if ((int)$check->fetchColumn() > 0) {
            // Has order history — deactivate only
            db()->prepare("UPDATE menu_items SET is_active=0 WHERE id=:id")->execute([':id'=>(int)$b['id']]);
        } else {
            // No orders — safe to hard delete
            db()->prepare("DELETE FROM modifier_groups WHERE menu_item_id=:id")->execute([':id'=>(int)$b['id']]);
            db()->prepare("DELETE FROM menu_items WHERE id=:id")->execute([':id'=>(int)$b['id']]);
        }
        echo json_encode(['ok'=>true]);
        exit;
    }

    /* reorder menu items */
    if ($_GET['api'] === 'reorder') {
        $b    = json_decode(file_get_contents('php://input'), true);
        $ids  = $b['ids'] ?? [];
        if (empty($ids) || !is_array($ids)) { echo json_encode(['ok'=>false,'msg'=>'No ids']); exit; }
        $stmt = db()->prepare("UPDATE menu_items SET sort_order=:o WHERE id=:id");
        foreach ($ids as $order => $id) {
            $stmt->execute([':o' => ($order + 1) * 10, ':id' => (int)$id]);
        }
        echo json_encode(['ok'=>true]);
        exit;
    }

    /* ── MODIFIER APIs ── */

    /* get modifiers for a menu item */
    if ($_GET['api'] === 'get_modifiers') {
        $itemId = (int)($_GET['item_id'] ?? 0);
        if (!$itemId) { echo json_encode(['ok'=>false,'msg'=>'No item_id']); exit; }
        $groups = db()->prepare("
            SELECT * FROM modifier_groups WHERE menu_item_id=:id ORDER BY sort_order,id
        ");
        $groups->execute([':id'=>$itemId]);
        $groups = $groups->fetchAll();
        foreach ($groups as &$g) {
            $opts = db()->prepare("
                SELECT * FROM modifier_options WHERE group_id=:gid ORDER BY sort_order,id
            ");
            $opts->execute([':gid'=>$g['id']]);
            $g['options'] = $opts->fetchAll();
        }
        echo json_encode(['ok'=>true,'groups'=>$groups]);
        exit;
    }

    /* save modifier group (add or update) */
    if ($_GET['api'] === 'save_modifier_group') {
        $b = json_decode(file_get_contents('php://input'), true);
        $itemId   = (int)($b['menu_item_id'] ?? 0);
        $name     = trim($b['name'] ?? '');
        $type     = in_array($b['type']??'', ['single','multi','text']) ? $b['type'] : 'single';
        $required = (int)($b['required'] ?? 0);
        $sortOrder= (int)($b['sort_order'] ?? 0);
        $gid      = (int)($b['id'] ?? 0);
        if (!$itemId || !$name) { echo json_encode(['ok'=>false,'msg'=>'Missing fields']); exit; }
        if ($gid) {
            db()->prepare("UPDATE modifier_groups SET name=:n,type=:t,required=:r,sort_order=:s WHERE id=:id AND menu_item_id=:mid")
                ->execute([':n'=>$name,':t'=>$type,':r'=>$required,':s'=>$sortOrder,':id'=>$gid,':mid'=>$itemId]);
        } else {
            $stmt = db()->prepare("INSERT INTO modifier_groups (menu_item_id,name,type,required,sort_order) VALUES (:mid,:n,:t,:r,:s)");
            $stmt->execute([':mid'=>$itemId,':n'=>$name,':t'=>$type,':r'=>$required,':s'=>$sortOrder]);
            $gid = (int)db()->lastInsertId();
        }
        echo json_encode(['ok'=>true,'id'=>$gid]);
        exit;
    }

    /* delete modifier group */
    if ($_GET['api'] === 'delete_modifier_group') {
        $b = json_decode(file_get_contents('php://input'), true);
        $gid = (int)($b['id'] ?? 0);
        if (!$gid) { echo json_encode(['ok'=>false,'msg'=>'No id']); exit; }
        db()->prepare("DELETE FROM modifier_groups WHERE id=:id")->execute([':id'=>$gid]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    /* save modifier option (add or update) */
    if ($_GET['api'] === 'save_modifier_option') {
        $b = json_decode(file_get_contents('php://input'), true);
        $gid       = (int)($b['group_id'] ?? 0);
        $label     = trim($b['label'] ?? '');
        $priceAdd  = (int)($b['price_add'] ?? 0);
        $isDefault = (int)($b['is_default'] ?? 0);
        $sortOrder = (int)($b['sort_order'] ?? 0);
        $oid       = (int)($b['id'] ?? 0);
        if (!$gid || !$label) { echo json_encode(['ok'=>false,'msg'=>'Missing fields']); exit; }
        if ($oid) {
            db()->prepare("UPDATE modifier_options SET label=:l,price_add=:p,is_default=:d,sort_order=:s WHERE id=:id AND group_id=:gid")
                ->execute([':l'=>$label,':p'=>$priceAdd,':d'=>$isDefault,':s'=>$sortOrder,':id'=>$oid,':gid'=>$gid]);
        } else {
            $stmt = db()->prepare("INSERT INTO modifier_options (group_id,label,price_add,is_default,sort_order) VALUES (:gid,:l,:p,:d,:s)");
            $stmt->execute([':gid'=>$gid,':l'=>$label,':p'=>$priceAdd,':d'=>$isDefault,':s'=>$sortOrder]);
            $oid = (int)db()->lastInsertId();
        }
        echo json_encode(['ok'=>true,'id'=>$oid]);
        exit;
    }

    /* delete modifier option */
    if ($_GET['api'] === 'delete_modifier_option') {
        $b = json_decode(file_get_contents('php://input'), true);
        $oid = (int)($b['id'] ?? 0);
        if (!$oid) { echo json_encode(['ok'=>false,'msg'=>'No id']); exit; }
        db()->prepare("DELETE FROM modifier_options WHERE id=:id")->execute([':id'=>$oid]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    /* update menu item station */
    if ($_GET['api'] === 'update_station') {
        $b = json_decode(file_get_contents('php://input'), true);
        $id      = (int)($b['id'] ?? 0);
        $station = trim($b['station'] ?? 'kitchen');
        if (!$id) { echo json_encode(['ok'=>false,'msg'=>'No id']); exit; }
        $allowed = ['kitchen','counter','bar','all'];
        if (!in_array($station, $allowed)) $station = 'kitchen';
        db()->prepare("UPDATE menu_items SET station=:s WHERE id=:id")->execute([':s'=>$station,':id'=>$id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    /* orders list — deleted_at IS NULL သာ ပြ */
    if ($_GET['api'] === 'orders') {
        $rows = db()->query("
            SELECT o.id, o.branch_id, o.tenant_id, o.customer_name, o.customer_phone, o.total_amount,
                   o.payment_method, o.status, o.created_at, o.delete_reason,
                   COALESCE(GROUP_CONCAT(oi.item_name,'×',oi.qty SEPARATOR ', '),'—') AS items,
                   COALESCE(b.name,'—') AS branch_name,
                   COALESCE(b.code,'—') AS branch_code
            FROM orders o
            LEFT JOIN order_items oi ON oi.order_id = o.id
            LEFT JOIN branches b ON b.id = o.branch_id
            WHERE (o.deleted_at IS NULL OR o.status = 'cancelled')
            GROUP BY o.id ORDER BY o.id DESC LIMIT 200
        ")->fetchAll();
        $bid = (int)($_GET['branch_id'] ?? 0);
        $tid = (int)($_GET['tenant_id'] ?? 0);
        if($bid > 0) $rows = array_values(array_filter($rows, fn($r) => (int)$r['branch_id'] === $bid));
        if($tid > 0) $rows = array_values(array_filter($rows, fn($r) => (int)$r['tenant_id'] === $tid));
        echo json_encode(['ok'=>true,'orders'=>$rows]); exit;
    }

    /* deleted orders log */
    if ($_GET['api'] === 'deleted_orders') {
        $rows = db()->query("
            SELECT * FROM deleted_orders_log ORDER BY deleted_at DESC LIMIT 100
        ")->fetchAll();
        echo json_encode(['ok'=>true,'orders'=>$rows]);
        exit;
    }

    /* delete order — soft delete + archive */
    if ($_GET['api'] === 'delete_order') {
        $b      = json_decode(file_get_contents('php://input'), true);
        $id     = (int)($b['id'] ?? 0);
        $reason = trim($b['reason'] ?? '');
        if ($id <= 0 || !$reason) { echo json_encode(['ok'=>false,'msg'=>'ID နဲ့ reason လိုသည်']); exit; }

        $pdo = db();
        // 1. order + items snapshot ယူ
        $order = $pdo->prepare("SELECT * FROM orders WHERE id=:id AND deleted_at IS NULL");
        $order->execute([':id'=>$id]);
        $o = $order->fetch();
        if (!$o) { echo json_encode(['ok'=>false,'msg'=>'Order not found']); exit; }

        $items = $pdo->prepare("SELECT item_name,qty,unit_price,subtotal FROM order_items WHERE order_id=:id");
        $items->execute([':id'=>$id]);
        $itemsData = $items->fetchAll();

        // 2. deleted_orders_log ထဲ archive
        $pdo->prepare("
            INSERT INTO deleted_orders_log
                (original_id,order_ref,customer_name,customer_phone,
                 total_amount,payment_method,order_status,items_snapshot,
                 delete_reason,deleted_by,deleted_at)
            VALUES
                (:oid,:ref,:name,:phone,
                 :total,:pay,:status,:items,
                 :reason,'admin',NOW())
        ")->execute([
            ':oid'    => $id,
            ':ref'    => 'NH-'.str_pad((string)$id,6,'0',STR_PAD_LEFT),
            ':name'   => $o['customer_name'],
            ':phone'  => $o['customer_phone'],
            ':total'  => $o['total_amount'],
            ':pay'    => $o['payment_method'],
            ':status' => $o['status'],
            ':items'  => json_encode($itemsData, JSON_UNESCAPED_UNICODE),
            ':reason' => $reason,
        ]);

        // 3. orders table မှာ soft delete
        $pdo->prepare("
            UPDATE orders SET deleted_at=NOW(), delete_reason=:reason, deleted_by='admin'
            WHERE id=:id
        ")->execute([':reason'=>$reason, ':id'=>$id]);

        // 4. Cancel hooks — stock restore, CRM adjust, delivery cancel, shift remove
        require_once __DIR__ . '/order_cancel_hooks.php';
        hookStockRestore($pdo, $id);
        hookCrmReverse($pdo, $o['customer_phone'] ?? '', (int)($o['total_amount'] ?? 0));
        hookDeliveryCancel($pdo, $id);
        hookShiftRemove($pdo, $id);

        echo json_encode(['ok'=>true]);
        exit;
    }

    /* image upload */
    /* batch upload CSV/Excel */
    if ($_GET['api'] === 'batch_upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_FILES['csv'])) { echo json_encode(['ok'=>false,'msg'=>'No file']); exit; }
        $file = $_FILES['csv'];
        $allowed = ['text/csv','application/csv','application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'text/plain'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv','txt'])) {
            echo json_encode(['ok'=>false,'msg'=>'CSV file သာ upload လုပ်ပါ (.csv)']); exit;
        }
        $raw = file_get_contents($file['tmp_name']);
        // BOM (UTF-8/UTF-16) ဖယ်
        if (substr($raw,0,3) === "\xEF\xBB\xBF") $raw = substr($raw,3);
        if (substr($raw,0,2) === "\xFF\xFE")     $raw = substr($raw,2);
        if (substr($raw,0,2) === "\xFE\xFF")     $raw = substr($raw,2);
        // Windows encoding → UTF-8
        if (!mb_check_encoding($raw,'UTF-8')) {
            $raw = mb_convert_encoding($raw,'UTF-8','auto');
        }
        $content = $raw;
        $lines   = preg_split('/\r\n|\r|\n/', trim($content));
        if (count($lines) < 2) { echo json_encode(['ok'=>false,'msg'=>'Data မပါပါ']); exit; }

        $header = parseCsvLine(array_shift($lines));
        $header = array_map(fn($h) => strtolower(trim($h)), $header);
        $required = ['name','category','price'];
        foreach ($required as $r) {
            if (!in_array($r, $header)) {
                echo json_encode(['ok'=>false,'msg'=>"Column မပါ: {$r}"]); exit;
            }
        }

        $validCats = ['Noodles','Rice','Starters','Soups','Desserts','Drinks'];
        $rows = []; $errors = [];

        foreach ($lines as $lineNum => $line) {
            if (!trim($line)) continue;
            $row = parseCsvLine($line);
            if (count($row) < count($header)) {
                $errors[] = ['row'=>$lineNum+2, 'msg'=>'Column count မကိုက်'];
                continue;
            }
            if (count($row) !== count($header)) {
                // Pad or trim to match header count
                while (count($row) < count($header)) $row[] = '';
                $row = array_slice($row, 0, count($header));
            }
            $data = array_combine($header, $row);
            $name  = $data['name'] ?? '';
            $cat   = $data['category'] ?? '';
            $price = (int)preg_replace('/[^0-9.]/','',$data['price'] ?? '0');
            if (str_contains((string)($data['price']??''), '.')) {
                // dollar.cents format (e.g. 4.50) → multiply by 100 if needed
                // Keep as-is since DB stores display value
                $price = (int)round((float)preg_replace('/[^0-9.]/','',$data['price']??'0'));
            }
            $stock = (int)($data['stock'] ?? $data['stock_qty'] ?? 0);
            $emoji = ($data['emoji'] ?? '') ?: '🍽️';
            $desc  = $data['description'] ?? $data['desc'] ?? '';

            if (!$name)  { $errors[] = ['row'=>$lineNum+2,'msg'=>'Name ဗလာ']; continue; }
            if ($price<0){ $errors[] = ['row'=>$lineNum+2,'msg'=>'Price မမှန်']; continue; }
            // Category mapping — English + Myanmar aliases
            $catAliases = [
                'Noodles'  => ['noodles','noodle','ခေါက်ဆွဲ','မုန့်','မုန်','noodle dish'],
                'Rice'     => ['rice','ထမင်း','ကြော်ထမင်း'],
                'Starters' => ['starters','starter','appetizer','appetisers','အစာဦး','ဆာလောင်မွတ်သိပ်'],
                'Soups'    => ['soups','soup','ဟင်းချို','ဟင်းရည်','ဟင်း'],
                'Desserts' => ['desserts','dessert','အချိုပွဲ','မုန့်ချို','dessert'],
                'Drinks'   => ['drinks','drink','beverage','beverages','အချိုရည်','ဖျော်ရည်','လက်ဖက်ရည်','ကော်ဖီ'],
            ];
            $catMatch = '';
            $catLower = mb_strtolower(trim($cat), 'UTF-8');
            foreach ($catAliases as $canonical => $aliases) {
                // Exact match (case-insensitive)
                if (mb_strtolower($canonical,'UTF-8') === $catLower) {
                    $catMatch = $canonical; break;
                }
                foreach ($aliases as $alias) {
                    if (mb_strtolower($alias,'UTF-8') === $catLower ||
                        mb_stripos($catLower, $alias, 0, 'UTF-8') !== false ||
                        mb_stripos($alias, $catLower, 0, 'UTF-8') !== false) {
                        $catMatch = $canonical; break 2;
                    }
                }
            }
            if (!$catMatch) {
                // Still no match — add error note but use Noodles as default
                $errors[] = ['row'=>$lineNum+2, 'msg'=>"Category မသိ: '{$cat}' → Noodles ထားသည်"];
                $catMatch = 'Noodles';
            }
            $cat = $catMatch;
            $rows[] = compact('name','cat','price','stock','emoji','desc');
        }

        if (empty($rows)) {
            echo json_encode(['ok'=>false,'msg'=>'Valid row မရှိ','errors'=>$errors]); exit;
        }

        // Preview mode (no DB write)
        if (!empty($_POST['preview'])) {
            echo json_encode(['ok'=>true,'preview'=>true,'rows'=>$rows,'errors'=>$errors], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Insert to DB
        $pdo  = db();
        $stmt = $pdo->prepare("
            INSERT INTO menu_items (name,category,description,price,stock_qty,emoji,is_active,sort_order)
            VALUES (:n,:c,:d,:p,:s,:e,1,
                (SELECT COALESCE(MAX(m2.sort_order),0)+10 FROM menu_items m2))
        ");
        $inserted = 0; $skipped = 0;
        foreach ($rows as $r) {
            // Check duplicate name
            $chk = $pdo->prepare("SELECT id FROM menu_items WHERE name=:n LIMIT 1");
            $chk->execute([':n'=>$r['name']]);
            if ($chk->fetch()) { $skipped++; continue; }
            $stmt->execute([
                ':n'=>$r['name'], ':c'=>$r['cat'], ':d'=>$r['desc'],
                ':p'=>$r['price'], ':s'=>$r['stock'], ':e'=>$r['emoji'],
            ]);
            $inserted++;
        }
        echo json_encode([
            'ok'       => true,
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_GET['api'] === 'upload_image' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_FILES['img'])) { echo json_encode(['ok'=>false,'msg'=>'No file']); exit; }
        $file    = $_FILES['img'];
        $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
        if (!in_array($file['type'], $allowed)) { echo json_encode(['ok'=>false,'msg'=>'JPG/PNG/GIF/WEBP သာ']); exit; }
        if ($file['size'] > 5 * 1024 * 1024) { echo json_encode(['ok'=>false,'msg'=>'Max 5MB']); exit; }

        $dir    = __DIR__ . '/uploads/menu/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $itemId = (int)($_POST['item_id'] ?? time());
        $name   = 'item_' . $itemId . '_' . time() . '.jpg';
        $dest   = $dir . $name;

        if (!function_exists('imagecreatefromjpeg')) {
            // GD မရှိ — original ကိုသိမ်း
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                echo json_encode(['ok'=>false,'msg'=>'Upload failed']); exit;
            }
        } else {
            $src = match($file['type']) {
                'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
                'image/png'  => imagecreatefrompng($file['tmp_name']),
                'image/gif'  => imagecreatefromgif($file['tmp_name']),
                'image/webp' => imagecreatefromwebp($file['tmp_name']),
                default      => false,
            };
            if (!$src) { echo json_encode(['ok'=>false,'msg'=>'Image read failed']); exit; }
            $origW = imagesx($src); $origH = imagesy($src);
            $targetW = 400; $targetH = 300;
            $origR = $origW/$origH; $targetR = $targetW/$targetH;
            if ($origR > $targetR) {
                $cropH=$origH; $cropW=(int)round($origH*$targetR);
                $cropX=(int)round(($origW-$cropW)/2); $cropY=0;
            } else {
                $cropW=$origW; $cropH=(int)round($origW/$targetR);
                $cropX=0; $cropY=(int)round(($origH-$cropH)/2);
            }
            $dst = imagecreatetruecolor($targetW,$targetH);
            imagefill($dst,0,0,imagecolorallocate($dst,255,255,255));
            imagecopyresampled($dst,$src,0,0,$cropX,$cropY,$targetW,$targetH,$cropW,$cropH);
            imagejpeg($dst,$dest,88);
            imagedestroy($src); imagedestroy($dst);
        }

        $relPath = 'uploads/menu/'.$name;
        if ($itemId > 0) {
            $chk = db()->prepare("SELECT id FROM menu_items WHERE id=:id");
            $chk->execute([':id'=>$itemId]);
            if ($chk->fetch()) {
                db()->prepare("UPDATE menu_items SET image_path=:p WHERE id=:id")
                    ->execute([':p'=>$relPath, ':id'=>$itemId]);
            }
        }
        echo json_encode(['ok'=>true,'path'=>$relPath]);
        exit;
    }

    /* upload KPay QR image */
    if ($_GET['api'] === 'upload_kpay_qr' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_FILES['img'])) { echo json_encode(['ok'=>false,'msg'=>'No file']); exit; }
        $file    = $_FILES['img'];
        $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
        if (!in_array($file['type'], $allowed)) { echo json_encode(['ok'=>false,'msg'=>'JPG/PNG/GIF/WEBP only']); exit; }
        if ($file['size'] > 3*1024*1024) { echo json_encode(['ok'=>false,'msg'=>'Max 3MB']); exit; }
        $dir  = __DIR__ . '/uploads/kpay/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $name = 'kpay_qr_' . time() . '.jpg';
        $dest = $dir . $name;
        $relPath = 'uploads/kpay/' . $name;
        if (function_exists('imagecreatefromjpeg')) {
            $src = match($file['type']) {
                'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
                'image/png'  => imagecreatefrompng($file['tmp_name']),
                'image/gif'  => imagecreatefromgif($file['tmp_name']),
                'image/webp' => imagecreatefromwebp($file['tmp_name']),
                default      => false,
            };
            if ($src) {
                $w = imagesx($src); $h = imagesy($src);
                $dst = imagecreatetruecolor($w, $h);
                imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
                imagejpeg($dst, $dest, 90);
                imagedestroy($src); imagedestroy($dst);
            } else { move_uploaded_file($file['tmp_name'], $dest); }
        } else { move_uploaded_file($file['tmp_name'], $dest); }
        // site_settings မှာ သိမ်း
        $chk = db()->prepare("SELECT setting_key FROM site_settings WHERE setting_key='kpay_qr_image'");
        $chk->execute();
        if ($chk->fetch()) {
            db()->prepare("UPDATE site_settings SET setting_value=:v WHERE setting_key='kpay_qr_image'")->execute([':v'=>$relPath]);
        } else {
            db()->prepare("INSERT INTO site_settings(setting_key,setting_value,label) VALUES('kpay_qr_image',:v,'KPay QR Image')")->execute([':v'=>$relPath]);
        }
        echo json_encode(['ok'=>true,'path'=>$relPath]);
        exit;
    }

    /* upload footer image (bg or logo) */
    if ($_GET['api'] === 'upload_footer_img' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_FILES['img'])) { echo json_encode(['ok'=>false,'msg'=>'No file']); exit; }
        $file    = $_FILES['img'];
        $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
        if (!in_array($file['type'], $allowed)) { echo json_encode(['ok'=>false,'msg'=>'JPG/PNG/GIF/WEBP only']); exit; }
        if ($file['size'] > 3*1024*1024) { echo json_encode(['ok'=>false,'msg'=>'Max 3MB']); exit; }

        $rawType = $_POST['type'] ?? 'bg';
        $type = in_array($rawType, ['logo','bg','header']) ? $rawType : 'bg';
        $subdir = $type === 'header' ? 'uploads/header/' : 'uploads/footer/';
        $dir  = __DIR__ . '/' . $subdir;
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $prefix = $type === 'header' ? 'header' : 'footer_'.$type;
        $name = $prefix . '_' . time() . '.jpg';
        $dest = $dir . $name;

        if (function_exists('imagecreatefromjpeg')) {
            $src = match($file['type']) {
                'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
                'image/png'  => imagecreatefrompng($file['tmp_name']),
                'image/gif'  => imagecreatefromgif($file['tmp_name']),
                'image/webp' => imagecreatefromwebp($file['tmp_name']),
                default      => false,
            };
            if ($src) {
                $w = imagesx($src); $h = imagesy($src);
                // Max 1200×400 for bg, 400×200 for logo
                $maxW = $type==='bg' ? 1200 : 400;
                $maxH = $type==='bg' ? 400  : 200;
                $scale = min(1, $maxW/$w, $maxH/$h);
                $nw = (int)round($w*$scale); $nh = (int)round($h*$scale);
                $dst = imagecreatetruecolor($nw, $nh);
                // Preserve transparency for PNG
                imagealphablending($dst, false); imagesavealpha($dst, true);
                imagefill($dst,0,0,imagecolorallocatealpha($dst,0,0,0,127));
                imagecopyresampled($dst,$src,0,0,0,0,$nw,$nh,$w,$h);
                imagejpeg($dst,$dest,90);
                imagedestroy($src); imagedestroy($dst);
            } else { move_uploaded_file($file['tmp_name'], $dest); }
        } else {
            move_uploaded_file($file['tmp_name'], $dest);
        }
        echo json_encode(['ok'=>true,'path'=>$subdir.$name]);
        exit;
    }

    /* remove image */
    if ($_GET['api'] === 'remove_image') {
        $b  = json_decode(file_get_contents('php://input'), true);
        $id = (int)($b['id'] ?? 0);
        $row = db()->prepare("SELECT image_path FROM menu_items WHERE id=:id");
        $row->execute([':id'=>$id]);
        $r = $row->fetch();
        if ($r && $r['image_path']) {
            $fullPath = __DIR__ . '/' . $r['image_path'];
            if (file_exists($fullPath)) unlink($fullPath);
        }
        db()->prepare("UPDATE menu_items SET image_path=NULL WHERE id=:id")->execute([':id'=>$id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    /* dashboard stats */
    if ($_GET['api'] === 'stats') {
        $pdo = db();
        $bid = (int)($_GET['branch_id'] ?? 0);
        $tid = (int)($_GET['tenant_id'] ?? 0);

        // Build WHERE clause for branch/tenant filter
        $where = "deleted_at IS NULL AND status != 'cancelled'";
        $params = [];
        if ($bid > 0) { $where .= " AND branch_id = ?"; $params[] = $bid; }
        if ($tid > 0) { $where .= " AND tenant_id = ?"; $params[] = $tid; }

        $s = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE() AND $where");
        $s->execute($params);
        $today = $s->fetchColumn();

        $s2 = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE DATE(created_at)=CURDATE() AND $where");
        $s2->execute($params);
        $revenue = $s2->fetchColumn();

        // Stock + KDS — always global (not branch-specific)
        $lowstock = $pdo->query("SELECT COUNT(*) FROM menu_items WHERE stock_qty<=5 AND is_active=1")->fetchColumn();
        $pending  = $pdo->query("SELECT COUNT(*) FROM kds_queue WHERE status='pending'")->fetchColumn();

        // Pending orders for this branch
        $pendingWhere = "status='pending'";
        if ($bid > 0) { $pendingWhere .= " AND branch_id=$bid"; }
        $pending = $pdo->query("SELECT COUNT(*) FROM orders WHERE $pendingWhere AND deleted_at IS NULL")->fetchColumn();

        echo json_encode(['ok'=>true,'today'=>$today,'revenue'=>$revenue,'low'=>$lowstock,'pending'=>$pending,'branch_id'=>$bid]);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}

/* ── GET: serve HTML ── */
$loggedIn = !empty($_SESSION['admin']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NoodleHaus Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&family=Noto+Sans+Myanmar:wght@400;500&family=Noto+Sans+SC:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{
  --ink:#1a1209;--paper:#fdf6ec;--warm:#f5ede0;--accent:#e84c2b;--accent2:#f0a500;
  --muted:#8a7560;--border:#e2d5c3;--card:#ffffff;--green:#2d7a4f;
  --radius:12px;--shadow:0 4px 24px rgba(26,18,9,.10);
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans','Noto Sans Myanmar','Noto Sans SC','Noto Sans',sans-serif;background:var(--paper);color:var(--ink);min-height:100vh;}

/* LOGIN */
.login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem;}
.login-box{background:var(--card);border-radius:20px;padding:2.5rem;width:100%;max-width:380px;box-shadow:var(--shadow);border:1px solid var(--border);}
.login-logo{font-family:'Playfair Display',serif;font-size:1.8rem;text-align:center;margin-bottom:.3rem;}
.login-logo span{color:var(--accent2);}
.login-sub{text-align:center;color:var(--muted);font-size:.85rem;margin-bottom:1.8rem;}

/* LAYOUT */
.app{display:flex;min-height:100vh;}
.sidebar{width:220px;background:var(--ink);color:var(--paper);flex-shrink:0;display:flex;flex-direction:column;}
.sidebar-logo{padding:1.4rem 1.2rem;font-family:'Playfair Display',serif;font-size:1.2rem;border-bottom:1px solid rgba(255,255,255,.1);}
.sidebar-logo span{color:var(--accent2);}
.sidebar-badge{font-size:.7rem;background:rgba(255,255,255,.1);padding:.15rem .5rem;border-radius:4px;margin-left:.4rem;vertical-align:middle;}
nav{flex:1;padding:.8rem 0;}
.nav-item{display:flex;align-items:center;gap:.7rem;padding:.75rem 1.2rem;cursor:pointer;font-size:.88rem;color:rgba(255,255,255,.7);transition:all .15s;border-left:3px solid transparent;}
.nav-item:hover{background:rgba(255,255,255,.06);color:#fff;}
.nav-item.active{background:rgba(255,255,255,.1);color:#fff;border-left-color:var(--accent2);}
.nav-icon{font-size:1rem;width:20px;text-align:center;}
.nav-section-header{padding:.75rem 1rem .25rem;font-size:.72rem;font-weight:700;letter-spacing:.08em;color:#f0a500;text-transform:uppercase;user-select:none;cursor:pointer;display:flex;justify-content:space-between;align-items:center;transition:color .15s;}
.nav-section-divider{height:1px;background:rgba(255,255,255,.09);margin:.4rem .8rem .3rem;}
#branch-ops-selector{padding:.25rem .7rem .45rem;}
#branch-ops-selector select{width:100%;padding:.32rem .55rem;border-radius:6px;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.09);color:#fff;font-size:.76rem;cursor:pointer;outline:none;}

.sidebar-foot{padding:1rem 1.2rem;border-top:1px solid rgba(255,255,255,.1);}
.logout-btn{width:100%;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);color:rgba(255,255,255,.7);padding:.5rem;border-radius:8px;cursor:pointer;font-size:.82rem;font-family:'DM Sans',sans-serif;transition:all .15s;}
.logout-btn:hover{background:var(--accent);border-color:var(--accent);color:#fff;}

/* MAIN */
.main{flex:1;overflow:auto;}
.page-head{padding:1.4rem 2rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--card);position:sticky;top:0;z-index:10;}
.page-title{font-family:'Playfair Display',serif;font-size:1.3rem;}
.content{padding:1.5rem 2rem;}

/* STATS */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:1rem;margin-bottom:1.5rem;}
.stat-card{background:var(--card);border-radius:var(--radius);padding:1.1rem 1.2rem;box-shadow:var(--shadow);border:1px solid var(--border);}
.stat-label{font-size:.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.4rem;}
.stat-val{font-size:1.6rem;font-weight:700;font-family:'DM Mono',monospace;}
.stat-val.green{color:var(--green);}
.stat-val.red{color:var(--accent);}
.stat-val.amber{color:var(--accent2);}

/* TABLE */
.table-wrap{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);border:1px solid var(--border);overflow:hidden;}
.table-toolbar{padding:.9rem 1.2rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.6rem;}
.search-input{border:1px solid var(--border);border-radius:8px;padding:.45rem .9rem;font-family:'DM Sans',sans-serif;font-size:.85rem;outline:none;min-width:200px;}
.search-input:focus{border-color:var(--ink);}
table{width:100%;border-collapse:collapse;}
th{background:var(--warm);font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);padding:.7rem 1rem;text-align:left;white-space:nowrap;}
td{padding:.7rem 1rem;border-bottom:1px solid var(--border);font-size:.85rem;vertical-align:middle;}
tr:last-child td{border:none;}
tr:hover td{background:#fef9f4;}
.emoji-cell{font-size:1.4rem;text-align:center;}
.price-cell{font-family:'DM Mono',monospace;white-space:nowrap;}
.stock-pill{display:inline-block;padding:.2rem .6rem;border-radius:50px;font-size:.75rem;font-weight:600;}
.stock-ok  {background:#d1fae5;color:#065f46;}
.stock-low {background:#fef3c7;color:#92400e;}
.stock-out {background:#fee2e2;color:#991b1b;}
.active-dot{width:8px;height:8px;border-radius:50%;display:inline-block;}
.dot-on{background:var(--green);}
.dot-off{background:var(--muted);}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.45rem .9rem;border-radius:8px;font-size:.82rem;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;border:none;transition:all .15s;}
.btn-primary{background:var(--ink);color:#fff;}
.btn-primary:hover{background:#333;}
.btn-success{background:var(--green);color:#fff;}
.btn-success:hover{background:#235f3d;}
.btn-warn{background:var(--accent2);color:var(--ink);}
.btn-warn:hover{opacity:.85;}
.btn-danger{background:var(--accent);color:#fff;}
.btn-danger:hover{background:#c8351a;}
.btn-ghost{background:none;border:1px solid var(--border);color:var(--ink);}
.btn-ghost:hover{background:var(--warm);}
.btn-sm{padding:.3rem .7rem;font-size:.78rem;}

/* FORM */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:.9rem;}
@media(max-width:500px){.form-grid{grid-template-columns:1fr;}}
.form-group{margin-bottom:.8rem;}
.form-group label{display:block;font-size:.8rem;font-weight:600;margin-bottom:.3rem;}
.form-group input,.form-group select,.form-group textarea{
  width:100%;border:1.5px solid var(--border);background:var(--card);
  padding:.6rem .9rem;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.88rem;outline:none;
  transition:border-color .15s;
}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--ink);}
.form-group textarea{resize:vertical;min-height:60px;}
.full-width{grid-column:1/-1;}

/* MODAL */
.modal-bg{position:fixed;inset:0;background:rgba(26,18,9,.5);backdrop-filter:blur(3px);z-index:200;display:flex;align-items:center;justify-content:center;padding:1rem;opacity:0;pointer-events:none;transition:opacity .2s;}
.modal-bg.open{opacity:1;pointer-events:all;}
.modal{background:var(--paper);border-radius:18px;width:100%;max-width:520px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(26,18,9,.3);transform:translateY(16px);transition:transform .2s;}
.modal-bg.open .modal{transform:translateY(0);}
.modal-head{background:var(--ink);color:var(--paper);padding:1rem 1.3rem;border-radius:18px 18px 0 0;display:flex;align-items:center;justify-content:space-between;}
.modal-head h3{font-family:'Playfair Display',serif;font-size:1.05rem;}
.modal-body{padding:1.3rem;}
.modal-foot{padding:.9rem 1.3rem;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:.6rem;}
.x-btn{background:none;border:none;color:var(--paper);font-size:1.2rem;cursor:pointer;opacity:.7;}
.x-btn:hover{opacity:1;}

/* CATEGORY TABS */
.cat-tabs{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem;}
.cat-tab{padding:.35rem .9rem;border-radius:50px;border:1px solid var(--border);background:var(--card);font-size:.8rem;font-weight:500;cursor:pointer;transition:all .15s;}
.cat-tab:hover,.cat-tab.on{background:var(--ink);color:#fff;border-color:var(--ink);}

/* TOAST */
.toast{position:fixed;bottom:1.5rem;right:1.5rem;z-index:999;background:var(--ink);color:var(--paper);padding:.65rem 1.1rem;border-radius:10px;font-size:.84rem;font-weight:500;box-shadow:0 8px 24px rgba(0,0,0,.2);transform:translateY(70px);opacity:0;transition:all .3s ease;max-width:280px;}
.toast.show{transform:translateY(0);opacity:1;}
.toast.ok{border-left:4px solid var(--green);}
.toast.err{border-left:4px solid var(--accent);}

/* ORDERS */
.order-status{display:inline-block;padding:.2rem .6rem;border-radius:50px;font-size:.73rem;font-weight:600;}
.os-pending  {background:#fff3cd;color:#856404;}
.os-preparing{background:#cce5ff;color:#004085;}
.os-ready    {background:#d4edda;color:#155724;}
.os-delivered{background:#d1fae5;color:#065f46;}
.os-cancelled{background:#fee2e2;color:#991b1b;}
.drag-handle{cursor:grab;color:var(--muted);font-size:1rem;padding:0 6px;user-select:none;line-height:1}
.drag-handle:hover{color:var(--ink);}
tr.drag-over td{background:var(--color-background-info,#e6f1fb);}
tr.dragging{opacity:.4;}
tr.drop-above{box-shadow:0 -2px 0 var(--accent);}
tr.drop-below{box-shadow:0 2px 0 var(--accent);}

/* ════ MOBILE RESPONSIVE ════ */
/* Bottom nav bar (mobile only) */
.mobile-nav{
  display:none;
  position:fixed;bottom:0;left:0;right:0;z-index:200;
  background:var(--ink);border-top:1px solid rgba(255,255,255,.15);
  padding:.4rem 0 calc(.4rem + env(safe-area-inset-bottom));
}
.mobile-nav-inner{display:flex;justify-content:space-around;align-items:center;}
.mnav-btn{
  display:flex;flex-direction:column;align-items:center;gap:2px;
  background:none;border:none;color:rgba(255,255,255,.6);
  font-family:'DM Sans',sans-serif;font-size:.62rem;font-weight:500;
  cursor:pointer;padding:.35rem .6rem;border-radius:8px;
  transition:color .15s;min-width:56px;
}
.mnav-btn:hover,.mnav-btn.active{color:#fff;}
.mnav-btn.active{color:var(--accent2);}
.mnav-icon{font-size:1.25rem;line-height:1;}

/* Hamburger button (tablet) */
.hamburger{
  display:none;background:none;border:none;
  color:var(--paper);font-size:1.4rem;cursor:pointer;
  padding:.3rem .5rem;margin-left:.5rem;
}

/* Overlay when sidebar open on mobile */
.sidebar-overlay{
  display:none;position:fixed;inset:0;
  background:rgba(0,0,0,.5);z-index:149;
}
.sidebar-overlay.open{display:block;}

@media(max-width:900px){
  .sidebar{
    position:fixed;left:-240px;top:0;bottom:0;
    z-index:150;width:240px;
    transition:left .25s ease;
    box-shadow:4px 0 20px rgba(0,0,0,.3);
  }
  .sidebar.open{left:0;}
  .main{width:100%;}
  .hamburger{display:block;}
  .content{padding:1rem;}
  .stats-grid{grid-template-columns:1fr 1fr;}
  .page-head{padding:1rem;}
  .table-wrap{overflow-x:auto;}
  table{min-width:600px;}
}

@media(max-width:600px){
  /* ── NAV ── */
  .mobile-nav{display:block;}
  .main{padding-bottom:72px;}
  .hamburger{display:none;}
  .sidebar{display:none !important;}
  .sidebar-overlay{display:none !important;}

  /* ── STATS ── */
  .stats-grid{grid-template-columns:1fr 1fr;gap:.5rem;}
  .stat-card{padding:.75rem .8rem;}
  .stat-val{font-size:1.2rem;}
  .stat-label{font-size:.72rem;}

  /* ── PAGE HEAD ── */
  .page-head{padding:.75rem 1rem;flex-wrap:wrap;gap:.4rem;min-height:auto;}
  .page-title{font-size:1rem;}

  /* ── CONTENT ── */
  .content{padding:.7rem;}
  .btn{padding:.4rem .65rem;font-size:.75rem;}
  .btn-sm{padding:.3rem .6rem;font-size:.72rem;}

  /* ── TABLES ── */
  .table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
  table{min-width:420px;}
  th,td{padding:.45rem .5rem;font-size:.75rem;white-space:nowrap;}
  .drag-handle{display:none;}
  .hide-mobile{display:none;}

  /* ── MODALS (bottom sheet) ── */
  .modal{
    border-radius:18px 18px 0 0;
    max-height:92vh;
    position:fixed;bottom:0;left:0;right:0;
    width:100%;max-width:100%;
    overflow-y:auto;
  }
  .modal-bg{align-items:flex-end;padding:0;}
  .modal-head{padding:.9rem 1.2rem .7rem;position:sticky;top:0;z-index:1;background:var(--ink);}
  .modal-body{padding:1rem 1.2rem;}
  .modal-foot{padding:.8rem 1.2rem;position:sticky;bottom:0;background:var(--card);}

  /* ── FORMS ── */
  .form-grid{grid-template-columns:1fr;}
  .full-width{grid-column:1!important;}
  .reason-grid{grid-template-columns:1fr 1fr;gap:.4rem;}
  .reason-btn{padding:.45rem .5rem;font-size:.75rem;}

  /* ── MENU ITEMS ── */
  .cat-tabs{gap:.35rem;flex-wrap:wrap;}
  .cat-tab{padding:.28rem .65rem;font-size:.73rem;}
  .payment-grid{grid-template-columns:repeat(3,1fr);}
  .emoji-cell{width:36px;}
  .emoji-cell img{width:32px;height:32px;}

  /* ── SETTINGS ── */
  .form-group label{font-size:.78rem;}
  input[type=color]{height:36px;}
  input[type=range]{margin-top:4px;}

  /* ── PREVIEW BOXES ── */
  #header-preview-box{height:48px;}
  #hero-preview-box{padding:1rem;}
  #footer-preview-box{padding:.8rem;}

  /* ── BATCH MODAL ── */
  #batch-preview-body td{font-size:.72rem;padding:.35rem .5rem;}

  /* ── QR GRID ── */
  #tables-grid{grid-template-columns:1fr 1fr;}
  #qr-grid{grid-template-columns:1fr 1fr;}
}

/* DELETE ORDER MODAL */
.reason-grid{display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.8rem;}
.reason-btn{padding:.5rem .7rem;border:1.5px solid var(--border);border-radius:8px;background:var(--card);
  font-size:.8rem;font-weight:500;cursor:pointer;transition:all .15s;text-align:left;font-family:'DM Sans',sans-serif;}
.reason-btn:hover{border-color:var(--accent);background:#fff5f3;}
.reason-btn.picked{border-color:var(--accent);background:#fff5f3;color:var(--accent);}

/* IMAGE UPLOAD */
.img-upload-area{border:2px dashed var(--border);border-radius:10px;padding:1.2rem;text-align:center;
  cursor:pointer;transition:border-color .15s;background:var(--warm);}
.img-upload-area:hover{border-color:var(--ink);}
.img-upload-area input[type=file]{display:none;}
.img-preview{width:100%;max-height:140px;object-fit:cover;border-radius:8px;margin-top:.6rem;}
.img-current{width:60px;height:60px;object-fit:cover;border-radius:6px;border:1px solid var(--border);}

/* DELETED ORDERS TAB */
.tab-row{display:flex;gap:.5rem;margin-bottom:1rem;}
.tab-pill{padding:.4rem 1rem;border-radius:50px;border:1px solid var(--border);
  font-size:.82rem;font-weight:500;cursor:pointer;transition:all .15s;background:var(--card);}
.tab-pill.on{background:var(--ink);color:#fff;border-color:var(--ink);}
.del-badge{background:#fee2e2;color:#991b1b;font-size:.72rem;padding:.15rem .5rem;
  border-radius:4px;font-weight:500;}
/* Modifier modal */
.modifier-group-card{transition:box-shadow .15s;}
.modifier-group-card:hover{box-shadow:0 2px 8px rgba(0,0,0,.08);}
#modifier-groups-list .btn-danger{background:#fee2e2;color:#dc2626;border:none;}
#modifier-groups-list .btn-danger:hover{background:#fecaca;}

/* ── TABLET (768px) ── */
@media(max-width:768px){
  .stats-grid{grid-template-columns:repeat(2,1fr);}
  .form-grid{grid-template-columns:1fr 1fr;}
  .table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
}

/* ── SMALL MOBILE (≤380px) ── */
@media(max-width:380px){
  .stats-grid{grid-template-columns:1fr 1fr;gap:.4rem;}
  .stat-card{padding:.6rem .7rem;}
  .stat-val{font-size:1rem;}
  .btn{padding:.35rem .55rem;font-size:.72rem;}
  .btn-sm{padding:.28rem .5rem;font-size:.7rem;}
  .page-head{padding:.6rem .8rem;}
  .content{padding:.5rem;}
  .cat-tab{padding:.25rem .55rem;font-size:.7rem;}
  th,td{padding:.4rem .4rem;font-size:.72rem;}
  .mobile-nav-inner button{font-size:.55rem;}
  .mnav-icon{font-size:1.1rem;}
  #tables-grid{grid-template-columns:1fr;}
  #qr-grid{grid-template-columns:1fr 1fr;}
  .reason-grid{grid-template-columns:1fr;}
}
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>

<?php if (!$loggedIn): ?>
<!-- ═══════════ LOGIN PAGE ═══════════ -->
<div class="login-wrap" id="login-page">
  <div class="login-box">
    <div class="login-logo">🍜 Noodle<span>Haus</span></div>
    <div class="login-sub">Admin Dashboard</div>
    <div class="form-group">
      <label>Username</label>
      <input type="text" id="l-user" placeholder="admin" autocomplete="username">
    </div>
    <div class="form-group">
      <label>Password</label>
      <input type="password" id="l-pass" placeholder="••••••••" autocomplete="current-password"
             onkeydown="if(event.key==='Enter')doLogin()">
    </div>
    <div id="l-err" style="color:var(--accent);font-size:.82rem;margin-bottom:.8rem;display:none"></div>
    <button class="btn btn-primary" style="width:100%;padding:.75rem;font-size:.95rem;border-radius:10px" onclick="doLogin()">
      Login →
    </button>
  </div>
</div>
<?php else: ?>
<!-- ═══════════ ADMIN APP ═══════════ -->
<!-- Sidebar overlay (mobile) -->
<div class="sidebar-overlay" id="sidebar-overlay" onclick="closeSidebar()"></div>

<div class="app" id="app">
  <!-- SIDEBAR -->
  <div class="sidebar" id="sidebar">
    <div class="sidebar-logo" style="display:flex;align-items:center;justify-content:space-between">
      <span>🍜 Noodle<span>Haus</span><span class="sidebar-badge">Admin</span></span>
      <button onclick="closeSidebar()" style="background:none;border:none;color:rgba(255,255,255,.6);font-size:1.2rem;cursor:pointer;display:none" id="sidebar-close-btn">✕</button>
    </div>
    <div style="display:none">
      <select id="branch-select" onchange="switchBranch(this.value)"
        style="width:100%;padding:.4rem .6rem;border-radius:6px;border:1px solid rgba(255,255,255,.2);background:#2a1f14;color:#fff;font-size:.8rem;cursor:pointer">
        <option value="0" style="background:#2a1f14;color:#fff">🏢 All Branches</option>
      </select>
    </div>
    <?php if (!empty($_SESSION['demo_mode'])): ?>
    <div style="background:#f39c12;color:#fff;text-align:center;padding:6px;font-size:12px;font-weight:600;letter-spacing:.05em">
      🔒 READ ONLY DEMO MODE
    </div>
    <?php endif; ?>
    <nav>
      <!-- ── BUSINESS ── -->
      <div class="nav-section-header" onclick="toggleNavSection('business')">
        Business <span class="nav-chev" id="chev-business">▾</span>
      </div>
      <div class="nav-section-body" id="section-business">
        <div class="nav-item" onclick="showPage('dashboard')" id="nav-dashboard">
          <span class="nav-icon">📊</span> Dashboard
        </div>
        <div class="nav-item" onclick="showPage('menu')" id="nav-menu">
          <span class="nav-icon">🍜</span> Menu Items
        </div>
      </div>
      <!-- ── BRANCH OPS ── -->
      <div class="nav-section-divider"></div>
      <div class="nav-section-header" onclick="toggleNavSection('branch-ops')">
        Branch Ops <span class="nav-chev" id="chev-branch-ops">▾</span>
      </div>
      <div class="nav-section-body" id="section-branch-ops">
        <div id="branch-ops-selector">
          <select id="branch-select-ops" onchange="switchBranch(this.value)">
            <option value="0">🏢 All Branches</option>
          </select>
        </div>
        <div class="nav-item" onclick="showPage('orders')" id="nav-orders">
          <span class="nav-icon">📋</span> Orders
        </div>
        <div class="nav-item" onclick="window.open('kds.html','_blank')" id="nav-kds">
          <span class="nav-icon">🍳</span> KDS
        </div>
        <div class="nav-item" onclick="showPage('tables')" id="nav-tables">
          <span class="nav-icon">🍽️</span> Tables
        </div>
        <div class="nav-item" onclick="showPage('reserve')" id="nav-reserve">
          <span class="nav-icon">📅</span> Reservations
        </div>
        <div class="nav-item" onclick="showPage('stock')" id="nav-stock">
          <span class="nav-icon">📦</span> Stock
        </div>
        <div class="nav-item" onclick="showPage('stocklog')" id="nav-stocklog">
          <span class="nav-icon">📋</span> Stock Log
        </div>
        <div class="nav-item" onclick="showPage('crm')" id="nav-crm">
          <span class="nav-icon">👥</span> CRM
        </div>
        <div class="nav-item" onclick="showPage('shift')" id="nav-shift">
          <span class="nav-icon">🕐</span> Shifts
        </div>
        <div class="nav-item" onclick="showPage('delivery')" id="nav-delivery">
          <span class="nav-icon">🛵</span> Delivery
        </div>
        <div class="nav-item" onclick="showPage('branches')" id="nav-branches">
          <span class="nav-icon">🏢</span> Branches
        </div>
      </div>
      <!-- ── ADMIN ── -->
      <div class="nav-section-divider"></div>
      <div class="nav-section-header" onclick="toggleNavSection('admin')">
        Admin <span class="nav-chev" id="chev-admin">›</span>
      </div>
      <div class="nav-section-body" id="section-admin" style="display:none">
        <div class="nav-item" onclick="showPage('saas')" id="nav-saas">
          <span class="nav-icon">🌐</span> SaaS
        </div>
        <div class="nav-item" onclick="showPage('promos')" id="nav-promos">
          <span class="nav-icon">🏷️</span> Promotions
        </div>
        <div class="nav-item" onclick="showPage('expenses')" id="nav-expenses">
          <span class="nav-icon">💰</span> Expenses
        </div>
        <div class="nav-item" onclick="showPage('schedule')" id="nav-schedule">
          <span class="nav-icon">📅</span> Scheduling
        </div>
        <div class="nav-item" onclick="showPage('staff')" id="nav-staff">
          <span class="nav-icon">👥</span> Staff
        </div>
        <div class="nav-item" onclick="showPage('storefront')" id="nav-storefront">
          <span class="nav-icon">🎨</span> Storefront
        </div>
        <div class="nav-item" onclick="showPage('settings')" id="nav-settings">
          <span class="nav-icon">⚙️</span> Settings
        </div>
      </div>
    </nav>
    <div class="sidebar-foot">
      <button class="logout-btn" onclick="doLogout()">↩ Logout</button>
    </div>
  </div>

  <!-- MAIN CONTENT -->
  <div class="main">
    <!-- ── DASHBOARD ── -->
    <div id="page-dashboard">
      <div class="page-head">
        <div style="display:flex;align-items:center;gap:.5rem">
          <button class="hamburger" onclick="openSidebar()" title="Menu">☰</button>
          <div class="page-title">Dashboard</div>
        </div>
        <span style="font-size:.82rem;color:var(--muted)" id="dash-date"></span>
      </div>
      <div class="content">
        <div class="stats-grid" id="stats-grid">
          <div class="stat-card" onclick="showPage('orders')" style="cursor:pointer">
            <div class="stat-label">📋 Today's Orders</div>
            <div class="stat-val" id="s-orders">—</div>
          </div>
          <div class="stat-card" onclick="showPage('orders')" style="cursor:pointer">
            <div class="stat-label">💰 Today's Revenue</div>
            <div class="stat-val green" id="s-revenue">—</div>
          </div>
          <div class="stat-card" onclick="showPage('menu')" style="cursor:pointer">
            <div class="stat-label">⚠️ Low Stock Items</div>
            <div class="stat-val" id="s-low">—</div>
          </div>
          <div class="stat-card" onclick="filterPending()" style="cursor:pointer">
            <div class="stat-label">⏳ Pending Orders</div>
            <div class="stat-val" id="s-pending">—</div>
          </div>
        </div>

        
        
        
        <!-- Bulk Delete Modal -->
        <div id="bulk-delete-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center">
          <div style="background:var(--bg);border-radius:14px;padding:1.5rem;max-width:380px;width:92%;position:relative">
            <button onclick="document.getElementById('bulk-delete-modal').style.display='none'" style="position:absolute;top:1rem;right:1rem;background:none;border:none;font-size:1.2rem;cursor:pointer">✕</button>
            <div style="font-weight:600;margin-bottom:1rem">🗑 Bulk Delete Orders</div>
            <div style="margin-bottom:.75rem">
              <label style="font-size:.8rem;color:var(--muted);display:block;margin-bottom:3px">Phone number (ဒီ phone ရဲ့ orders အကုန်ဖျက်)</label>
              <input type="text" id="bulk-phone" placeholder="09xxxxxxxxx" style="width:100%;padding:.4rem .6rem;border:1px solid #ddd;border-radius:6px;font-size:.88rem">
            </div>
            <div style="margin-bottom:.75rem">
              <label style="font-size:.8rem;color:var(--muted);display:block;margin-bottom:3px">Date range ဖျက် (ဗလာ = အကုန်)</label>
              <div style="display:flex;gap:.5rem">
                <input type="date" id="bulk-date-from" style="flex:1;padding:.4rem .5rem;border:1px solid #ddd;border-radius:6px;font-size:.82rem">
                <input type="date" id="bulk-date-to" style="flex:1;padding:.4rem .5rem;border:1px solid #ddd;border-radius:6px;font-size:.82rem">
              </div>
            </div>
            <div style="margin-bottom:1rem">
              <label style="font-size:.8rem;color:var(--muted);display:block;margin-bottom:3px">Delete reason</label>
              <input type="text" id="bulk-reason" value="Bulk delete by admin" style="width:100%;padding:.4rem .6rem;border:1px solid #ddd;border-radius:6px;font-size:.88rem">
            </div>
            <div id="bulk-preview" style="font-size:.82rem;color:var(--muted);margin-bottom:.75rem"></div>
            <div style="display:flex;gap:.5rem">
              <button onclick="previewBulkDelete()" style="flex:1;padding:.6rem;background:#6c757d;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:.88rem">🔍 Preview</button>
              <button onclick="confirmBulkDelete()" style="flex:1;padding:.6rem;background:#dc3545;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:.88rem">🗑 Delete All</button>
            </div>
          </div>
        </div>

        <!-- KDS Clear Modal -->
        <div id="kds-clear-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center">
          <div style="background:var(--bg);border-radius:14px;padding:1.5rem;max-width:340px;width:92%;position:relative">
            <button onclick="document.getElementById('kds-clear-modal').style.display='none'" style="position:absolute;top:1rem;right:1rem;background:none;border:none;font-size:1.2rem;cursor:pointer">✕</button>
            <div style="font-weight:600;margin-bottom:1rem">🧹 KDS Queue Clear</div>
            <div style="margin-bottom:1rem;font-size:.88rem;color:var(--muted)">KDS queue ထဲမှာ pending/preparing/ready tickets တွေကို served အဖြစ် mark လုပ်မည်</div>
            <div id="kds-pending-count" style="font-size:1.1rem;font-weight:600;margin-bottom:1rem;text-align:center"></div>
            <div style="display:flex;gap:.5rem">
              <button onclick="document.getElementById('kds-clear-modal').style.display='none'" style="flex:1;padding:.6rem;background:#6c757d;color:#fff;border:none;border-radius:8px;cursor:pointer">Cancel</button>
              <button onclick="clearKDSQueue()" style="flex:1;padding:.6rem;background:#e84c2b;color:#fff;border:none;border-radius:8px;cursor:pointer">🧹 Clear Now</button>
            </div>
          </div>
        </div>

<!-- Split Bill Modal -->
<div id="split-bill-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center">
  <div style="background:var(--bg);border-radius:14px;padding:1.5rem;max-width:360px;width:92%;position:relative">
    <button onclick="document.getElementById('split-bill-modal').style.display='none'" style="position:absolute;top:1rem;right:1rem;background:none;border:none;font-size:1.2rem;cursor:pointer">✕</button>
    <div style="font-weight:600;font-size:1rem;margin-bottom:1rem">💳 Split Bill</div>
    <div id="split-order-info" style="font-size:.85rem;color:var(--muted);margin-bottom:1rem"></div>
    <div style="margin-bottom:.75rem">
      <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:.4rem">Primary payment</label>
      <select id="split-primary" style="width:100%;padding:.5rem;border:1px solid #ddd;border-radius:8px;font-size:.88rem">
        <option value="cash">💵 Cash</option>
        <option value="kpay">💜 KPay</option>
        <option value="wave">🌊 Wave Pay</option>
        <option value="cb">🏦 CB Pay</option>
        <option value="aya">🟢 AYA Pay</option>
        <option value="card">💳 Card</option>
      </select>
    </div>
    <div style="margin-bottom:.75rem">
      <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:.4rem">Split with (optional)</label>
      <select id="split-secondary" style="width:100%;padding:.5rem;border:1px solid #ddd;border-radius:8px;font-size:.88rem">
        <option value="">— None (single payment) —</option>
        <option value="cash">💵 Cash</option>
        <option value="kpay">💜 KPay</option>
        <option value="wave">🌊 Wave Pay</option>
        <option value="cb">🏦 CB Pay</option>
        <option value="aya">🟢 AYA Pay</option>
        <option value="card">💳 Card</option>
      </select>
    </div>
    <div id="split-amount-row" style="display:none;margin-bottom:1rem">
      <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:.4rem">Amount paid by secondary (Ks)</label>
      <input type="number" id="split-amount" min="0" placeholder="0" style="width:100%;padding:.5rem;border:1px solid #ddd;border-radius:8px;font-size:.88rem">
    </div>
    <div style="display:flex;gap:.5rem">
      <button onclick="document.getElementById('split-bill-modal').style.display='none'" style="flex:1;padding:.65rem;background:#6c757d;color:#fff;border:none;border-radius:8px;cursor:pointer">Cancel</button>
      <button onclick="openSplitBill(${o.id},${o.total_amount})" style="flex:1;padding:.65rem;background:#e84c2b;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600">💳 Split & Close</button>
    </div>
  </div>
</div>
<!-- Customer History Modal -->
        <div id="cust-history-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;align-items:center;justify-content:center">
          <div style="background:var(--bg);border-radius:16px;padding:1.5rem;max-width:500px;width:95%;max-height:85vh;overflow-y:auto;position:relative">
            <button onclick="document.getElementById('cust-history-modal').style.display='none'" style="position:absolute;top:1rem;right:1rem;background:none;border:none;font-size:1.3rem;cursor:pointer">✕</button>
            <div style="font-weight:600;font-size:1rem;margin-bottom:1rem">👤 Customer Order History</div>
            <div style="display:flex;gap:.5rem;margin-bottom:1rem">
              <input type="text" id="cust-phone-input" placeholder="09xxxxxxxxx" style="flex:1;padding:.5rem .75rem;border:1px solid #ddd;border-radius:8px;font-size:.9rem">
              <button onclick="loadCustHistory()" style="padding:.5rem 1rem;background:#e84c2b;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:.88rem">Search</button>
            </div>
            <div id="cust-history-result"></div>
          </div>
        </div>
<!-- ═══ ANALYTICS SECTION ═══ -->
        <div id="analytics-section" style="margin-top:1.2rem">

          <!-- Date range selector -->
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem;flex-wrap:wrap;gap:.5rem">
            <div style="font-weight:600;font-size:.95rem">📈 Analytics</div>
            <div style="display:flex;gap:.4rem">
              <button onclick="loadAnalytics(7)"  id="abtn-7"  class="btn btn-sm btn-ghost" style="font-size:.78rem">7D</button>
              <button onclick="loadAnalytics(14)" id="abtn-14" class="btn btn-sm btn-ghost" style="font-size:.78rem">14D</button>
              <button onclick="loadAnalytics(30)" id="abtn-30" class="btn btn-sm btn-ghost" style="font-size:.78rem">30D</button>
            </div>
          </div>

          <!-- Summary mini cards -->
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.6rem;margin-bottom:1rem">
            <div class="stat-card" style="padding:.7rem;text-align:center">
              <div style="font-size:.72rem;color:var(--muted)">Total Orders</div>
              <div style="font-size:1.3rem;font-weight:700;color:var(--accent)" id="an-total-orders">—</div>
            </div>
            <div class="stat-card" style="padding:.7rem;text-align:center">
              <div style="font-size:.72rem;color:var(--muted)">Total Revenue</div>
              <div style="font-size:1.1rem;font-weight:700;color:#28a745" id="an-total-rev">—</div>
            </div>
            <div class="stat-card" style="padding:.7rem;text-align:center">
              <div style="font-size:.72rem;color:var(--muted)">Avg Order</div>
              <div style="font-size:1.1rem;font-weight:700;color:var(--accent2)" id="an-avg-order">—</div>
            </div>
          </div>

          <!-- Revenue chart -->
          <div class="stat-card" style="padding:1rem;margin-bottom:.8rem">
            <div style="font-size:.82rem;font-weight:600;margin-bottom:.6rem">💰 Daily Revenue</div>
            <div style="position:relative;height:180px">
              <canvas id="chart-revenue"></canvas>
            </div>
          </div>

          <!-- Top items + Payment breakdown -->
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem;margin-bottom:.8rem">
            <div class="stat-card" style="padding:1rem">
              <div style="font-size:.82rem;font-weight:600;margin-bottom:.6rem">🍜 Top Items</div>
              <div style="position:relative;height:200px">
                <canvas id="chart-items"></canvas>
              </div>
            </div>
            <div class="stat-card" style="padding:1rem">
              <div style="font-size:.82rem;font-weight:600;margin-bottom:.6rem">💳 Payment Split</div>
              <div style="position:relative;height:200px">
                <canvas id="chart-payments"></canvas>
              </div>
            </div>
          </div>

          <!-- Hourly heatmap -->
          <div class="stat-card" style="padding:1rem;margin-bottom:1rem">
            <div style="font-size:.82rem;font-weight:600;margin-bottom:.6rem">🕐 Peak Hours</div>
            <div style="position:relative;height:120px">
              <canvas id="chart-hourly"></canvas>
            </div>
          </div>

        </div>
        <!-- ═══ END ANALYTICS ═══ -->

<!-- Recent Orders (Dashboard) -->
        <div class="table-wrap" style="margin-top:1rem">
          <div class="table-toolbar">
            <span style="font-weight:600;font-size:.9rem">📋 Recent Orders</span>
            <button class="btn btn-ghost btn-sm" onclick="showPage('orders')">View All →</button>
            <button class="btn btn-ghost btn-sm" onclick="openDailyReport()">📊 Daily Report</button>
            <button class="btn btn-ghost btn-sm" onclick="openKDSClear()" style="color:#e84c2b">🧹 KDS</button>
          </div>
          <div style="overflow-x:auto">
            <table>
              <thead><tr>
                <th>Ref</th><th>Customer</th><th>Items</th>
                <th>Amount</th><th>Payment</th><th>Status</th><th>Time</th><th></th>
              </tr></thead>
              <tbody id="dash-orders-body">
                <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:2rem">Loading…</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- ── MENU ITEMS ── -->
    <div id="page-menu" style="display:none">
      <div class="page-head">
        <div style="display:flex;align-items:center;gap:.5rem">
          <button class="hamburger" onclick="openSidebar()" title="Menu">☰</button>
          <div class="page-title">Menu Items</div>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
          <button class="btn btn-ghost btn-sm" onclick="downloadTemplate()">⬇ CSV</button>
          <button class="btn btn-ghost btn-sm" onclick="openBatchModal()">📥 Batch</button>
          <button class="btn btn-primary" onclick="openAddModal()">+ Add</button>
        </div>
      </div>
      <div class="content">
        <div class="cat-tabs" id="cat-tabs"></div>
        <div class="table-toolbar" style="border:none;padding:0 0 .8rem">
          <input class="search-input" id="menu-search" placeholder="🔍  Search items…" oninput="renderMenuTable()">
          <span style="font-size:.82rem;color:var(--muted)" id="menu-count"></span>
        </div>
        <div class="table-wrap">
          <div style="overflow-x:auto">
            <table>
              <thead><tr>
                <th style="width:32px" title="Drag to reorder"></th>
                <th style="width:48px"></th>
                <th>Name</th><th>Category</th>
                <th>Price</th><th>Stock</th><th>Status</th><th>Actions</th>
              </tr></thead>
              <tbody id="menu-body"><tr><td colspan="8" style="text-align:center;color:var(--muted);padding:2rem">Loading…</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- ── TABLES PAGE ── -->
    <div id="page-tables" style="display:none">
      <div class="page-head">
        <div style="display:flex;align-items:center;gap:.5rem">
          <button class="hamburger" onclick="openSidebar()">☰</button>
          <div class="page-title">Tables & QR Codes</div>
        </div>
        <button class="btn btn-primary" onclick="openAddTableModal()">+ Add Table</button>
      </div>
      <div class="content">
        <!-- Live table status -->
        <div id="tables-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem"></div>

        <!-- QR section -->
        <div style="background:var(--card);border-radius:var(--radius);border:1px solid var(--border);padding:1.2rem">
          <div style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:.8rem">📱 QR Codes — Print & Place on Tables</div>
          <div id="qr-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:1rem"></div>
          <button class="btn btn-ghost" style="margin-top:1rem;width:100%" onclick="printAllQR()">🖨️ Print All QR Codes</button>
        </div>
      </div>
    </div>

    <!-- Add Table Modal -->
    <div class="modal-bg" id="add-table-modal">
      <div class="modal" style="max-width:360px">
        <div class="modal-head"><h3>+ Add Table</h3>
          <button class="x-btn" onclick="document.getElementById('add-table-modal').classList.remove('open')">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label>Table Code (e.g. T01, VIP1)</label>
            <input type="text" id="new-table-code" placeholder="T09" style="text-transform:uppercase">
          </div>
          <div class="form-group">
            <label>Label</label>
            <input type="text" id="new-table-label" placeholder="Window Seat">
          </div>
          <div class="form-group">
            <label>Seats</label>
            <input type="number" id="new-table-seats" value="4" min="1" max="20">
          </div>
        </div>
        <div class="modal-foot">
          <button class="btn btn-ghost" onclick="document.getElementById('add-table-modal').classList.remove('open')">Cancel</button>
          <button class="btn btn-primary" onclick="saveNewTable()">Save Table</button>
        </div>
      </div>
    </div>

    <!-- ── RESERVATIONS PAGE ── -->
    <div id="page-reserve" style="display:none">
      <div class="page-head">
        <h1 class="page-title">📅 Table Reservations</h1>
      </div>

      <!-- Date picker + New button -->
      <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1.5rem;align-items:center">
        <input id="res-date" type="date" onchange="resLoad()"
          style="padding:.6rem 1rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
        <select id="res-status-filter" onchange="resLoad()"
          style="padding:.6rem 1rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          <option value="">All Status</option>
          <option value="pending">⏳ Pending</option>
          <option value="confirmed">✅ Confirmed</option>
          <option value="seated">🪑 Seated</option>
          <option value="completed">✔️ Completed</option>
          <option value="cancelled">❌ Cancelled</option>
          <option value="no_show">👻 No Show</option>
        </select>
        <span id="res-count" style="color:var(--text-muted);font-size:.85rem"></span>
        <button class="btn btn-primary" onclick="resOpenNew()" style="margin-left:auto;padding:.6rem 1.2rem">
          + New Reservation
        </button>
      </div>

      <!-- Reservations Table -->
      <div class="card" style="overflow-x:auto;padding:0">
        <table style="width:100%;border-collapse:collapse;font-size:.87rem">
          <thead>
            <tr style="border-bottom:1px solid var(--border);background:var(--surface2)">
              <th style="padding:.7rem 1rem;text-align:left">Time</th>
              <th style="padding:.7rem 1rem;text-align:left">Customer</th>
              <th style="padding:.7rem 1rem;text-align:center">Party</th>
              <th style="padding:.7rem 1rem;text-align:left">Table</th>
              <th style="padding:.7rem 1rem;text-align:center">Status</th>
              <th style="padding:.7rem 1rem;text-align:left">Notes</th>
              <th style="padding:.7rem 1rem;text-align:center">Actions</th>
            </tr>
          </thead>
          <tbody id="res-tbody">
            <tr><td colspan="7" style="padding:2rem;text-align:center;color:var(--text-muted)">Loading...</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Reservation Create/Edit Modal -->
    <div id="res-modal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.6);overflow-y:auto">
      <div style="max-width:480px;margin:2rem auto;background:var(--surface);border-radius:16px;padding:2rem;position:relative">
        <button onclick="document.getElementById('res-modal').style.display='none'"
          style="position:absolute;top:1rem;right:1rem;background:none;border:none;color:var(--text-muted);font-size:1.4rem;cursor:pointer">✕</button>
        <div style="font-weight:700;font-size:1.1rem;margin-bottom:1.2rem" id="res-modal-title">📅 New Reservation</div>
        <div style="display:flex;flex-direction:column;gap:1rem">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem">
            <div>
              <label style="font-size:.82rem;color:var(--text-muted)">Name *</label>
              <input id="res-name" type="text" placeholder="Customer name"
                style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
            </div>
            <div>
              <label style="font-size:.82rem;color:var(--text-muted)">Phone *</label>
              <input id="res-phone" type="tel" placeholder="09xxxxxxxxx"
                style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.8rem">
            <div>
              <label style="font-size:.82rem;color:var(--text-muted)">Date *</label>
              <input id="res-date-input" type="date"
                style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
            </div>
            <div>
              <label style="font-size:.82rem;color:var(--text-muted)">Time *</label>
              <input id="res-time" type="time"
                style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
            </div>
            <div>
              <label style="font-size:.82rem;color:var(--text-muted)">Party Size</label>
              <input id="res-party" type="number" min="1" max="20" value="2"
                style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem">
            <div>
              <label style="font-size:.82rem;color:var(--text-muted)">Table</label>
              <select id="res-table"
                style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
                <option value="">Auto-assign</option>
              </select>
            </div>
            <div>
              <label style="font-size:.82rem;color:var(--text-muted)">Duration (min)</label>
              <input id="res-duration" type="number" min="30" step="30" value="90"
                style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
            </div>
          </div>
          <div>
            <label style="font-size:.82rem;color:var(--text-muted)">Notes</label>
            <input id="res-notes" type="text" placeholder="Special requests..."
              style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          </div>
          <button class="btn btn-primary" onclick="resCreate()" style="padding:.7rem;font-size:1rem">
            ✅ Save Reservation
          </button>
        </div>
      </div>
    </div>

    <!-- ── STOCK PAGE ── -->
    <div id="page-stock" style="display:none">
      <div class="page-head">
        <h1 class="page-title">📦 Stock Management</h1>
      </div>

      <!-- Summary Cards -->
      <div id="stock-summary" style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem">
      </div>

      <!-- Stock Table -->
      <div class="card" style="padding:0;overflow-x:auto">
        <div style="padding:1rem 1.2rem;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border)">
          <span style="font-weight:700">📋 All Items</span>
          <input id="stock-search" type="text" placeholder="🔍 Search..."
            style="padding:.4rem .8rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:.85rem;width:200px"
            oninput="stockFilter()">
        </div>
        <table style="width:100%;border-collapse:collapse;font-size:.87rem">
          <thead>
            <tr style="background:var(--surface2);border-bottom:1px solid var(--border)">
              <th style="padding:.7rem 1rem;text-align:left">Item</th>
              <th style="padding:.7rem 1rem;text-align:left">Category</th>
              <th style="padding:.7rem 1rem;text-align:right">Stock</th>
              <th style="padding:.7rem 1rem;text-align:center">Status</th>
              <th style="padding:.7rem 1rem;text-align:center">Actions</th>
            </tr>
          </thead>
          <tbody id="stock-tbody">
            <tr><td colspan="5" style="padding:2rem;text-align:center;color:var(--text-muted)">Loading...</td></tr>
          </tbody>
        </table>
      </div>

      <!-- Stock Log -->
      <div class="card" style="padding:0;overflow-x:auto;margin-top:1.5rem">
        <div style="padding:1rem 1.2rem;font-weight:700;border-bottom:1px solid var(--border)">📝 Recent Stock Changes</div>
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
          <thead>
            <tr style="background:var(--surface2);border-bottom:1px solid var(--border)">
              <th style="padding:.6rem 1rem;text-align:left">Time</th>
              <th style="padding:.6rem 1rem;text-align:left">Item</th>
              <th style="padding:.6rem 1rem;text-align:right">Change</th>
              <th style="padding:.6rem 1rem;text-align:right">New Qty</th>
              <th style="padding:.6rem 1rem;text-align:left">Reason</th>
              <th style="padding:.6rem 1rem;text-align:left">Note</th>
            </tr>
          </thead>
          <tbody id="stock-log-tbody">
            <tr><td colspan="6" style="padding:1.5rem;text-align:center;color:var(--text-muted)">No changes yet</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- STOCK LOG PAGE -->
    <div id="page-stocklog" style="display:none">
      <div class="page-head"><h1 class="page-title">📋 Stock Log</h1></div>
      <div style="background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:16px">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;align-items:end">
          <div><label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px">ရှာဖွေ</label><input id="sl-search" type="text" placeholder="Item, reason, staff..." oninput="loadStockLogs()" style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:var(--surface);color:var(--text)"></div>
          <div><label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px">Action</label><select id="sl-action" onchange="loadStockLogs()" style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:var(--surface);color:var(--text)"><option value="">All</option><option value="add">ထည့်သွင်း</option><option value="remove">ထုတ်ယူ</option><option value="adjust">ပြင်ဆင်</option><option value="waste">ဖျက်ဆီး</option><option value="order_deduct">Order နုတ်</option></select></div>
          <div><label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px">မှ ရက်</label><input id="sl-date-from" type="date" onchange="loadStockLogs()" style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:var(--surface);color:var(--text)"></div>
          <div><label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px">ထိ ရက်</label><input id="sl-date-to" type="date" onchange="loadStockLogs()" style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:var(--surface);color:var(--text)"></div>
          <div style="display:flex;gap:8px"><button onclick="loadStockLogs()" style="flex:1;padding:7px;border:none;border-radius:6px;background:#3b82f6;color:#fff;font-size:13px;cursor:pointer">🔍 Search</button><button onclick="exportSL()" style="flex:1;padding:7px;border:none;border-radius:6px;background:#16a34a;color:#fff;font-size:13px;cursor:pointer">⬇ CSV</button></div>
        </div>
      </div>
      <div id="sl-summary" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:16px"></div>
      <div style="overflow-x:auto;border:1px solid var(--border);border-radius:10px">
        <table style="width:100%;border-collapse:collapse;font-size:13px">
          <thead><tr style="background:var(--surface2);border-bottom:1px solid var(--border)"><th style="padding:10px 12px;text-align:left">#</th><th style="padding:10px 12px;text-align:left">Item</th><th style="padding:10px 12px;text-align:center">Action</th><th style="padding:10px 12px;text-align:center">အဟောင်း</th><th style="padding:10px 12px;text-align:center">အသစ်</th><th style="padding:10px 12px;text-align:center">ပြောင်းလဲ</th><th style="padding:10px 12px;text-align:left">အကြောင်း</th><th style="padding:10px 12px;text-align:left">ဝန်ထမ်း</th><th style="padding:10px 12px;text-align:left">ရက်/အချိန်</th></tr></thead>
          <tbody id="sl-tbody"><tr><td colspan="9" style="text-align:center;padding:40px;color:var(--text-muted)">Loading...</td></tr></tbody>
        </table>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;flex-wrap:wrap;gap:8px"><span id="sl-count" style="font-size:13px;color:var(--text-muted)"></span><div style="display:flex;gap:8px"><button id="sl-prev" onclick="slPage(-1)" style="padding:6px 14px;border:1px solid var(--border);border-radius:6px;font-size:13px;cursor:pointer;background:var(--surface);color:var(--text)">← Prev</button><button id="sl-next" onclick="slPage(1)" style="padding:6px 14px;border:1px solid var(--border);border-radius:6px;font-size:13px;cursor:pointer;background:var(--surface);color:var(--text)">Next →</button></div></div>
    </div>


    <!-- STAFF PAGE -->
    <div id="page-staff" style="display:none">
      <div class="page-head" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
        <h1 class="page-title">👥 Staff Management</h1>
        <button onclick="staffOpenAdd()" style="padding:.6rem 1.2rem;background:var(--accent);color:#fff;border:none;border-radius:8px;font-size:.88rem;cursor:pointer;font-weight:600">+ Add Staff</button>
      </div>
      <div id="staff-list" style="display:grid;gap:10px;margin-top:1rem"></div>
    </div>

    <!-- STAFF MODAL -->
    <div id="staff-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9100;align-items:center;justify-content:center">
      <div style="background:var(--surface);border-radius:16px;padding:1.5rem;max-width:460px;width:93%;max-height:88vh;overflow-y:auto;position:relative">
        <button onclick="document.getElementById('staff-modal').style.display='none'" style="position:absolute;top:1rem;right:1rem;background:#e74c3c;color:#fff;border:none;width:28px;height:28px;border-radius:50%;font-size:14px;cursor:pointer">✕</button>
        <div id="staff-modal-title" style="font-size:1.1rem;font-weight:700;margin-bottom:1.2rem">Add Staff</div>
        <input type="hidden" id="sf-id">
        <div style="display:grid;gap:.85rem">
          <div>
            <label style="font-size:.8rem;color:var(--text-muted);display:block;margin-bottom:.3rem">Name *</label>
            <input id="sf-name" type="text" placeholder="Ko Aung" style="width:100%;padding:.6rem .8rem;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text);font-size:.9rem">
          </div>
          <div>
            <label style="font-size:.8rem;color:var(--text-muted);display:block;margin-bottom:.3rem">PIN * (4-6 digits)</label>
            <input id="sf-pin" type="password" placeholder="••••" maxlength="6" style="width:100%;padding:.6rem .8rem;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text);font-size:.9rem;letter-spacing:.2em">
          </div>
          <div>
            <label style="font-size:.8rem;color:var(--text-muted);display:block;margin-bottom:.3rem">Role</label>
            <select id="sf-role" style="width:100%;padding:.6rem .8rem;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text);font-size:.9rem">
              <option value="waiter">🧑‍🍳 Waiter</option>
              <option value="manager">👔 Manager</option>
            </select>
          </div>
          <div>
            <label style="font-size:.8rem;color:var(--text-muted);display:block;margin-bottom:.5rem">Permissions — ဘယ် page သုံးခွင့်ပေးမလဲ</label>
            <div id="sf-perms" style="display:grid;grid-template-columns:1fr 1fr;gap:.4rem"></div>
          </div>
          <div>
            <label style="font-size:.8rem;color:var(--text-muted);display:block;margin-bottom:.3rem">Notes</label>
            <input id="sf-notes" type="text" placeholder="Optional notes" style="width:100%;padding:.6rem .8rem;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text);font-size:.9rem">
          </div>
        </div>
        <div style="display:flex;gap:.75rem;margin-top:1.2rem">
          <button onclick="staffSave()" style="flex:1;padding:.7rem;background:var(--accent);color:#fff;border:none;border-radius:8px;font-size:.9rem;font-weight:600;cursor:pointer">Save</button>
          <button onclick="document.getElementById('staff-modal').style.display='none'" style="padding:.7rem 1.2rem;background:var(--surface2);border:1px solid var(--border);border-radius:8px;font-size:.9rem;cursor:pointer;color:var(--text)">Cancel</button>
        </div>
      </div>
    </div>

    <!-- Stock Adjust Modal -->
    <div id="stock-modal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.6);overflow-y:auto">
      <div style="max-width:420px;margin:3rem auto;background:var(--surface);border-radius:16px;padding:2rem;position:relative">
        <button onclick="document.getElementById('stock-modal').style.display='none'"
          style="position:absolute;top:1rem;right:1rem;background:none;border:none;color:var(--text-muted);font-size:1.4rem;cursor:pointer">✕</button>
        <div style="font-weight:700;font-size:1.1rem;margin-bottom:1.2rem" id="stock-modal-title">Adjust Stock</div>
        <input type="hidden" id="stock-adj-id">
        <div style="display:flex;flex-direction:column;gap:1rem">
          <div>
            <label style="font-size:.82rem;color:var(--text-muted)">Quantity Change</label>
            <div style="display:flex;gap:.5rem;margin-top:.3rem">
              <button class="btn btn-sm" onclick="document.getElementById('stock-adj-qty').value=10" style="background:var(--surface2);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:.4rem .8rem;cursor:pointer">+10</button>
              <button class="btn btn-sm" onclick="document.getElementById('stock-adj-qty').value=50" style="background:var(--surface2);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:.4rem .8rem;cursor:pointer">+50</button>
              <button class="btn btn-sm" onclick="document.getElementById('stock-adj-qty').value=100" style="background:var(--surface2);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:.4rem .8rem;cursor:pointer">+100</button>
              <button class="btn btn-sm" onclick="document.getElementById('stock-adj-qty').value=-5" style="background:var(--surface2);border:1px solid var(--border);color:#e74c3c;border-radius:8px;padding:.4rem .8rem;cursor:pointer">-5</button>
            </div>
            <input id="stock-adj-qty" type="number" placeholder="e.g. 50 or -10"
              style="width:100%;margin-top:.5rem;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:1.1rem">
          </div>
          <div>
            <label style="font-size:.82rem;color:var(--text-muted)">Reason</label>
            <select id="stock-adj-reason" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
              <option value="restock">📥 Restock</option>
              <option value="manual_adjust">✏️ Manual Adjust</option>
              <option value="waste">🗑 Waste</option>
              <option value="correction">🔧 Correction</option>
              <option value="returned">↩ Returned</option>
            </select>
          </div>
          <div>
            <label style="font-size:.82rem;color:var(--text-muted)">Note (optional)</label>
            <input id="stock-adj-note" type="text" placeholder="e.g. Morning restock"
              style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          </div>
          <button class="btn btn-primary" onclick="stockDoAdjust()" style="padding:.7rem;font-size:1rem">
            ✅ Apply
          </button>
        </div>
      </div>
    </div>

    <!-- ── SHIFT PAGE ── -->
    <div id="page-shift" style="display:none">
      <div class="page-head">
        <h1 class="page-title">🕐 Shift Management</h1>
      </div>

      <!-- Current Shift Status Card -->
      <div id="shift-status-card" class="card" style="margin-bottom:1.5rem;padding:1.5rem">
        <div id="shift-status-body">
          <div style="text-align:center;color:var(--text-muted);padding:1rem">Loading...</div>
        </div>
      </div>

      <!-- Open Shift Form (shown when no shift open) -->
      <div id="shift-open-form" class="card" style="display:none;padding:1.5rem;margin-bottom:1.5rem">
        <div style="font-weight:700;font-size:1rem;margin-bottom:1rem">🔓 Shift ဖွင့်မည်</div>
        <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end">
          <div style="flex:1;min-width:150px">
            <label style="font-size:.82rem;color:var(--text-muted);display:block;margin-bottom:.3rem">Staff PIN</label>
            <input id="shift-pin" type="password" maxlength="6" placeholder="••••"
              style="width:100%;padding:.6rem 1rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:1rem;letter-spacing:.3em">
          </div>
          <div style="flex:1;min-width:180px">
            <label style="font-size:.82rem;color:var(--text-muted);display:block;margin-bottom:.3rem">Opening Cash (MMK)</label>
            <input id="shift-opening-cash" type="number" min="0" placeholder="50000"
              style="width:100%;padding:.6rem 1rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          </div>
          <button class="btn btn-primary" onclick="shiftOpen()" style="padding:.6rem 1.5rem">
            ▶ Open Shift
          </button>
        </div>
      </div>

      <!-- Close Shift Form (shown when shift open) -->
      <div id="shift-close-form" class="card" style="display:none;padding:1.5rem;margin-bottom:1.5rem">
        <div style="font-weight:700;font-size:1rem;margin-bottom:1rem">🔒 Shift ပိတ်မည်</div>
        <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end">
          <div style="flex:1;min-width:180px">
            <label style="font-size:.82rem;color:var(--text-muted);display:block;margin-bottom:.3rem">Closing Cash (MMK)</label>
            <input id="shift-closing-cash" type="number" min="0" placeholder="0"
              style="width:100%;padding:.6rem 1rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          </div>
          <div style="flex:2;min-width:200px">
            <label style="font-size:.82rem;color:var(--text-muted);display:block;margin-bottom:.3rem">Notes</label>
            <input id="shift-close-notes" type="text" placeholder="Optional notes..."
              style="width:100%;padding:.6rem 1rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          </div>
          <button class="btn" onclick="shiftClose()"
            style="padding:.6rem 1.5rem;background:#e74c3c;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600">
            ■ Close Shift
          </button>
        </div>
      </div>

      <!-- Shift History -->
      <div class="card" style="padding:0;overflow-x:auto">
        <div style="padding:1rem 1.2rem;font-weight:700;border-bottom:1px solid var(--border)">📋 Shift History</div>
        <table style="width:100%;border-collapse:collapse;font-size:.87rem">
          <thead>
            <tr style="background:var(--surface2);border-bottom:1px solid var(--border)">
              <th style="padding:.7rem 1rem;text-align:left">Staff</th>
              <th style="padding:.7rem 1rem;text-align:left">Opened</th>
              <th style="padding:.7rem 1rem;text-align:left">Duration</th>
              <th style="padding:.7rem 1rem;text-align:right">Orders</th>
              <th style="padding:.7rem 1rem;text-align:right">Revenue</th>
              <th style="padding:.7rem 1rem;text-align:right">Cash Diff</th>
              <th style="padding:.7rem 1rem;text-align:center">Status</th>
              <th style="padding:.7rem 1rem;text-align:center">Detail</th>
            </tr>
          </thead>
          <tbody id="shift-history-tbody">
            <tr><td colspan="8" style="padding:2rem;text-align:center;color:var(--text-muted)">Loading...</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Shift Detail Modal -->
    <div id="shift-modal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.6);overflow-y:auto">
      <div style="max-width:680px;margin:2rem auto;background:var(--surface);border-radius:16px;padding:2rem;position:relative">
        <button onclick="document.getElementById('shift-modal').style.display='none'"
          style="position:absolute;top:1rem;right:1rem;background:none;border:none;color:var(--text-muted);font-size:1.4rem;cursor:pointer">✕</button>
        <div id="shift-modal-body">Loading...</div>
      </div>
    </div>

    <!-- ── CRM PAGE ── -->
    <div id="page-crm" style="display:none">
      <div class="page-head">
        <h1 class="page-title">👥 Customer CRM</h1>
      </div>

      <!-- Search + Filter Bar -->
      <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1.5rem;align-items:center">
        <input id="crm-search" type="text" placeholder="🔍 Phone / Name ရှာမည်..."
          style="flex:1;min-width:200px;padding:.6rem 1rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:.9rem"
          oninput="crmSearchDebounce()">
        <select id="crm-tag-filter" onchange="crmLoadCustomers()"
          style="padding:.6rem 1rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:.9rem">
          <option value="">All Tags</option>
          <option value="vip">⭐ VIP</option>
          <option value="regular">🔄 Regular</option>
          <option value="normal">👤 Normal</option>
          <option value="blocked">🚫 Blocked</option>
        </select>
        <span id="crm-count" style="color:var(--text-muted);font-size:.85rem"></span>
      </div>

      <!-- Customer Table -->
      <div class="card" style="overflow-x:auto;padding:0">
        <table style="width:100%;border-collapse:collapse;font-size:.88rem">
          <thead>
            <tr style="border-bottom:1px solid var(--border);background:var(--surface2)">
              <th style="padding:.75rem 1rem;text-align:left">Customer</th>
              <th style="padding:.75rem 1rem;text-align:left">Tag</th>
              <th style="padding:.75rem 1rem;text-align:right">Orders</th>
              <th style="padding:.75rem 1rem;text-align:right">Total Spent</th>
              <th style="padding:.75rem 1rem;text-align:left">Last Order</th>
              <th style="padding:.75rem 1rem;text-align:left">Loyalty</th>
              <th style="padding:.75rem 1rem;text-align:center">Action</th>
            </tr>
          </thead>
          <tbody id="crm-tbody">
            <tr><td colspan="7" style="padding:2rem;text-align:center;color:var(--text-muted)">Loading...</td></tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div id="crm-pagination" style="display:flex;gap:.5rem;justify-content:center;margin-top:1rem;flex-wrap:wrap"></div>
    </div>

    <!-- CRM Profile Modal -->
    <div id="crm-modal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.6);overflow-y:auto">
      <div style="max-width:620px;margin:2rem auto;background:var(--surface);border-radius:16px;padding:2rem;position:relative">
        <button onclick="document.getElementById('crm-modal').style.display='none'"
          style="position:absolute;top:1rem;right:1rem;background:none;border:none;color:var(--text-muted);font-size:1.4rem;cursor:pointer">✕</button>
        <div id="crm-modal-body">Loading...</div>
      </div>
    </div>

    <!-- __ SCHEDULE PAGE __ -->
    <div id="page-schedule" style="display:none">
      <div class="page-head"><h1 class="page-title">📆 Staff Schedule</h1></div>
      <div style="display:flex;gap:.75rem;align-items:center;margin-bottom:1rem;flex-wrap:wrap">
        <button class="btn btn-ghost btn-sm" onclick="schedPrevWeek()">◀ Prev</button>
        <span id="sched-week-label" style="font-weight:700"></span>
        <button class="btn btn-ghost btn-sm" onclick="schedNextWeek()">Next ▶</button>
        <div id="sched-cost" style="margin-left:auto;font-size:.85rem;color:var(--text-muted)"></div>
        <button class="btn btn-primary" onclick="schedOpenAssign()" style="padding:.5rem 1rem">+ Assign Shift</button>
      </div>
      <div class="card" style="padding:0;overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.82rem">
          <thead><tr id="sched-header" style="background:var(--surface2);border-bottom:1px solid var(--border)"></tr></thead>
          <tbody id="sched-tbody"><tr><td colspan="8" style="padding:2rem;text-align:center;color:var(--text-muted)">Loading...</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- Schedule Assign Modal -->
    <div id="sched-modal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.6)">
      <div style="max-width:420px;margin:2rem auto;background:var(--surface);border-radius:16px;padding:2rem;position:relative">
        <button onclick="document.getElementById('sched-modal').style.display='none'" style="position:absolute;top:1rem;right:1rem;background:none;border:none;color:var(--text-muted);font-size:1.4rem;cursor:pointer">&#10005;</button>
        <div style="font-weight:700;font-size:1.1rem;margin-bottom:1rem">📆 Assign Shift</div>
        <div style="display:flex;flex-direction:column;gap:.8rem">
          <select id="sched-staff" style="padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></select>
          <input id="sched-date" type="date" style="padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem">
            <div><label style="font-size:.78rem;color:var(--text-muted)">Start</label><input id="sched-start" type="time" value="09:00" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></div>
            <div><label style="font-size:.78rem;color:var(--text-muted)">End</label><input id="sched-end" type="time" value="17:00" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem">
            <select id="sched-role" style="padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
              <option value="waiter">Waiter</option><option value="kitchen">Kitchen</option><option value="cashier">Cashier</option><option value="manager">Manager</option>
            </select>
            <div><label style="font-size:.78rem;color:var(--text-muted)">Rate/hr</label><input id="sched-rate" type="number" value="1500" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></div>
          </div>
          <button class="btn btn-primary" onclick="schedSave()" style="padding:.7rem;font-size:1rem">✅ Assign</button>
        </div>
      </div>
    </div>

    <!-- __ EXPENSES PAGE __ -->
    <div id="page-expenses" style="display:none">
      <div class="page-head"><h1 class="page-title">💼 Expense Tracking</h1></div>
      <div id="exp-pnl" style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem"></div>
      <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem;align-items:center">
        <input id="exp-month" type="month" onchange="expLoad()" style="padding:.5rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
        <select id="exp-cat-filter" onchange="expLoad()" style="padding:.5rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          <option value="">All Categories</option>
          <option value="ingredients">🥬 Ingredients</option><option value="packaging">📦 Packaging</option>
          <option value="utilities">⚡ Utilities</option><option value="rent">🏠 Rent</option>
          <option value="salary">👥 Salary</option><option value="equipment">🔧 Equipment</option>
          <option value="marketing">📢 Marketing</option><option value="other">📌 Other</option>
        </select>
        <button class="btn btn-primary" onclick="expOpenNew()" style="margin-left:auto;padding:.5rem 1.2rem">+ Add Expense</button>
      </div>
      <div class="card" style="padding:0;overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
          <thead><tr style="background:var(--surface2);border-bottom:1px solid var(--border)">
            <th style="padding:.7rem 1rem;text-align:left">Date</th>
            <th style="padding:.7rem 1rem;text-align:left">Category</th>
            <th style="padding:.7rem 1rem;text-align:left">Description</th>
            <th style="padding:.7rem 1rem;text-align:left">Supplier</th>
            <th style="padding:.7rem 1rem;text-align:right">Amount</th>
            <th style="padding:.7rem 1rem;text-align:center">Actions</th>
          </tr></thead>
          <tbody id="exp-tbody"><tr><td colspan="6" style="padding:2rem;text-align:center;color:var(--text-muted)">Loading...</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- Expense Modal -->
    <div id="exp-modal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.6);overflow-y:auto">
      <div style="max-width:450px;margin:2rem auto;background:var(--surface);border-radius:16px;padding:2rem;position:relative">
        <button onclick="document.getElementById('exp-modal').style.display='none'" style="position:absolute;top:1rem;right:1rem;background:none;border:none;color:var(--text-muted);font-size:1.4rem;cursor:pointer">&#10005;</button>
        <div style="font-weight:700;font-size:1.1rem;margin-bottom:1.2rem">💼 Add Expense</div>
        <div style="display:flex;flex-direction:column;gap:.8rem">
          <select id="exp-category" style="padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
            <option value="ingredients">🥬 Ingredients</option><option value="packaging">📦 Packaging</option>
            <option value="utilities">⚡ Utilities</option><option value="rent">🏠 Rent</option>
            <option value="salary">👥 Salary</option><option value="equipment">🔧 Equipment</option>
            <option value="marketing">📢 Marketing</option><option value="other">📌 Other</option>
          </select>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem">
            <div><label style="font-size:.78rem;color:var(--text-muted)">Amount (MMK)</label><input id="exp-amount" type="number" min="0" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></div>
            <div><label style="font-size:.78rem;color:var(--text-muted)">Date</label><input id="exp-date" type="date" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></div>
          </div>
          <input id="exp-desc" type="text" placeholder="Description" style="padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          <select id="exp-supplier" style="padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"><option value="">No supplier</option></select>
          <input id="exp-ref" type="text" placeholder="Receipt ref (optional)" style="padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          <button class="btn btn-primary" onclick="expSave()" style="padding:.7rem;font-size:1rem">✅ Save</button>
        </div>
      </div>
    </div>

    <!-- __ PROMOTIONS PAGE __ -->
    <div id="page-promos" style="display:none">
      <div class="page-head"><h1 class="page-title">🎁 Promotions</h1></div>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem">
        <div id="promo-stats" style="display:flex;gap:1rem;flex-wrap:wrap"></div>
        <button class="btn btn-primary" onclick="promoOpenNew()" style="padding:.5rem 1.2rem">+ New Promotion</button>
      </div>
      <div class="card" style="padding:0;overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
          <thead><tr style="background:var(--surface2);border-bottom:1px solid var(--border)">
            <th style="padding:.7rem 1rem;text-align:left">Name</th>
            <th style="padding:.7rem 1rem;text-align:left">Type</th>
            <th style="padding:.7rem 1rem;text-align:left">Code</th>
            <th style="padding:.7rem 1rem;text-align:right">Value</th>
            <th style="padding:.7rem 1rem;text-align:left">Conditions</th>
            <th style="padding:.7rem 1rem;text-align:center">Used</th>
            <th style="padding:.7rem 1rem;text-align:center">Status</th>
            <th style="padding:.7rem 1rem;text-align:center">Actions</th>
          </tr></thead>
          <tbody id="promo-tbody"><tr><td colspan="8" style="padding:2rem;text-align:center;color:var(--text-muted)">Loading...</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- Promo Modal -->
    <div id="promo-modal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.6);overflow-y:auto">
      <div style="max-width:520px;margin:2rem auto;background:var(--surface);border-radius:16px;padding:2rem;position:relative">
        <button onclick="document.getElementById('promo-modal').style.display='none'" style="position:absolute;top:1rem;right:1rem;background:none;border:none;color:var(--text-muted);font-size:1.4rem;cursor:pointer">✕</button>
        <div style="font-weight:700;font-size:1.1rem;margin-bottom:1.2rem" id="promo-modal-title">🎁 New Promotion</div>
        <input type="hidden" id="promo-edit-id">
        <div style="display:flex;flex-direction:column;gap:.8rem">
          <input id="promo-name" type="text" placeholder="Promotion name" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem">
            <select id="promo-type" style="padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
              <option value="percent_off">% Off</option><option value="fixed_off">Fixed MMK Off</option>
              <option value="bogo">Buy 1 Get 1</option><option value="combo">Combo Deal</option>
            </select>
            <input id="promo-code" type="text" placeholder="Code (empty=auto)" style="padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text);text-transform:uppercase">
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.8rem">
            <div><label style="font-size:.78rem;color:var(--text-muted)">Value</label><input id="promo-value" type="number" value="10" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></div>
            <div><label style="font-size:.78rem;color:var(--text-muted)">Min Order</label><input id="promo-min" type="number" value="0" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></div>
            <div><label style="font-size:.78rem;color:var(--text-muted)">Max Discount</label><input id="promo-max" type="number" placeholder="No cap" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem">
            <div><label style="font-size:.78rem;color:var(--text-muted)">Start Date</label><input id="promo-start" type="date" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></div>
            <div><label style="font-size:.78rem;color:var(--text-muted)">End Date</label><input id="promo-end" type="date" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem">
            <div><label style="font-size:.78rem;color:var(--text-muted)">Happy Hour Start</label><input id="promo-hh-start" type="time" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></div>
            <div><label style="font-size:.78rem;color:var(--text-muted)">Happy Hour End</label><input id="promo-hh-end" type="time" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></div>
          </div>
          <input id="promo-days" type="text" placeholder="Days: mon,tue,wed,thu,fri (empty=all)" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          <button class="btn btn-primary" onclick="promoSave()" style="padding:.7rem;font-size:1rem">✅ Save</button>
        </div>
      </div>
    </div>

    <!-- ── DELIVERY PAGE ── -->

    <div id="page-delivery" style="display:none">
      <div class="page-head">
        <h1 class="page-title">🛵 Delivery Management</h1>
      </div>

      <!-- Drivers Strip -->
      <div class="card" style="padding:1rem;margin-bottom:1rem">
        <div style="font-weight:700;margin-bottom:.6rem">🧑‍✈️ Drivers</div>
        <div id="dlv-drivers" style="display:flex;gap:.8rem;flex-wrap:wrap"></div>
      </div>

      <!-- Two columns: Pending + Active -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
        <!-- Pending -->
        <div class="card" style="padding:0">
          <div style="padding:1rem;font-weight:700;border-bottom:1px solid var(--border)">📋 Unassigned Orders</div>
          <div id="dlv-pending" style="padding:.8rem;max-height:500px;overflow-y:auto">
            <div style="padding:2rem;text-align:center;color:var(--text-muted)">Loading...</div>
          </div>
        </div>
        <!-- Active -->
        <div class="card" style="padding:0">
          <div style="padding:1rem;font-weight:700;border-bottom:1px solid var(--border)">🛵 Active Deliveries</div>
          <div id="dlv-active" style="padding:.8rem;max-height:500px;overflow-y:auto">
            <div style="padding:2rem;text-align:center;color:var(--text-muted)">Loading...</div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── BRANCHES PAGE ── -->
    <div id="page-branches" style="display:none">
      <div class="page-head">
        <h1 class="page-title">🏢 Branch Management</h1>
      </div>

      <!-- Cross-branch Dashboard -->
      <div id="branch-dashboard" style="margin-bottom:1.5rem"></div>

      <!-- Branch Cards -->
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <span style="font-weight:700;font-size:1rem">All Branches</span>
        <button class="btn btn-primary" onclick="branchOpenNew()" style="padding:.5rem 1.2rem">+ New Branch</button>
      </div>
      <div id="branch-list" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1rem">
      </div>
    </div>

    <!-- Branch Create/Edit Modal -->
    <div id="branch-modal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.6);overflow-y:auto">
      <div style="max-width:480px;margin:2rem auto;background:var(--surface);border-radius:16px;padding:2rem;position:relative">
        <button onclick="document.getElementById('branch-modal').style.display='none'"
          style="position:absolute;top:1rem;right:1rem;background:none;border:none;color:var(--text-muted);font-size:1.4rem;cursor:pointer">✕</button>
        <div style="font-weight:700;font-size:1.1rem;margin-bottom:1.2rem" id="branch-modal-title">🏢 New Branch</div>
        <input type="hidden" id="branch-edit-id">
        <div style="display:flex;flex-direction:column;gap:1rem">
          <div style="display:grid;grid-template-columns:2fr 1fr;gap:.8rem">
            <div>
              <label style="font-size:.82rem;color:var(--text-muted)">Branch Name *</label>
              <input id="branch-name" type="text" placeholder="NoodleHaus Mandalay"
                style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
            </div>
            <div>
              <label style="font-size:.82rem;color:var(--text-muted)">Code *</label>
              <input id="branch-code" type="text" placeholder="MDY1" maxlength="20"
                style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text);text-transform:uppercase">
            </div>
          </div>
          <div>
            <label style="font-size:.82rem;color:var(--text-muted)">Address</label>
            <input id="branch-address" type="text" placeholder="Street, City"
              style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.8rem">
            <div>
              <label style="font-size:.82rem;color:var(--text-muted)">Phone</label>
              <input id="branch-phone" type="tel" placeholder="09xxx"
                style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
            </div>
            <div>
              <label style="font-size:.82rem;color:var(--text-muted)">Open</label>
              <input id="branch-open" type="time" value="10:00"
                style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
            </div>
            <div>
              <label style="font-size:.82rem;color:var(--text-muted)">Close</label>
              <input id="branch-close" type="time" value="23:00"
                style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
            </div>
          </div>
          <button class="btn btn-primary" onclick="branchSave()" style="padding:.7rem;font-size:1rem">✅ Save Branch</button>
        </div>
      </div>
    </div>

        <!-- __ SAAS ADMIN __ -->
    <div id="page-saas" style="display:none">
      <div class="page-head"><h1 class="page-title">🌐 SaaS Dashboard</h1></div>
      <div id="saas-stats" style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem"></div>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <span style="font-weight:700">Tenants</span>
        <a href="signup.html" target="_blank" class="btn btn-primary" style="padding:.5rem 1rem;text-decoration:none">📋 Signup Page</a>
      </div>
      <div class="card" style="padding:0;overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
          <thead><tr style="background:var(--surface2);border-bottom:1px solid var(--border)">
            <th style="padding:.7rem 1rem;text-align:left">Tenant</th>
            <th style="padding:.7rem 1rem;text-align:left">Owner</th>
            <th style="padding:.7rem 1rem;text-align:center">Plan</th>
            <th style="padding:.7rem 1rem;text-align:right">Orders</th>
            <th style="padding:.7rem 1rem;text-align:right">Revenue</th>
            <th style="padding:.7rem 1rem;text-align:center">Status</th>
            <th style="padding:.7rem 1rem;text-align:center">Actions</th>
          </tr></thead>
          <tbody id="saas-tbody"><tr><td colspan="7" style="padding:2rem;text-align:center;color:var(--text-muted)">Loading...</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- ── SETTINGS PAGE (CMS) ── -->
    <div id="page-settings" style="display:none">
      <div class="page-head">
        <div style="display:flex;align-items:center;gap:.5rem">
          <button class="hamburger" onclick="openSidebar()" title="Menu">☰</button>
          <div class="page-title">Site Settings</div>
        </div>
        <button class="btn btn-primary" onclick="collectTownshipPromo(); saveSettings()">💾 Save</button>
      </div>
      <div class="content">

        <!-- STORE INFO -->
        <div style="margin-bottom:1.5rem">
          <div style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:.8rem">🏪 Store Info</div>
          <div class="form-grid">
            <div class="form-group">
              <label>Store Name</label>
              <input type="text" id="st-store_name" placeholder="NoodleHaus">
            </div>
            <div class="form-group">
              <label>Store Emoji</label>
              <input type="text" id="st-store_emoji" placeholder="🍜" maxlength="4" style="font-size:1.4rem;text-align:center">
            </div>
            <div class="form-group">
              <label>Open Hours Text</label>
              <input type="text" id="st-open_hours" placeholder="Open until 10 PM">
            </div>
            <div class="form-group">
              <label>Delivery Fee (Ks — e.g. 2000)</label>
              <input type="number" id="st-delivery_fee" placeholder="150" min="0">
            </div>
          </div>
        </div>



        <!-- TOWNSHIP FEES + PROMO CODES -->
        <div style="margin-bottom:1.5rem">
          <div style="font-weight:600;margin-bottom:.75rem;font-size:.95rem">🚚 Township Delivery Fees</div>
          <div id="township-fee-editor"></div>
          <button type="button" onclick="addTownshipRow()" style="margin-top:.5rem;padding:.35rem .8rem;background:#e84c2b;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.82rem">+ မြို့နယ်ထည့်</button>
          <input type="hidden" id="st-township_fees">
        </div>

        <div style="margin-bottom:1.5rem">
          <div style="font-weight:600;margin-bottom:.75rem;font-size:.95rem">🎟 Promo Codes</div>
          <div id="promo-code-editor"></div>
          <button type="button" onclick="addPromoRow()" style="margin-top:.5rem;padding:.35rem .8rem;background:#e84c2b;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.82rem">+ Promo ထည့်</button>
          <input type="hidden" id="st-promo_codes">
        </div>

   
        <!-- ORDER SETTINGS -->
        <div class="card">
          <div style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:.8rem">📋 Order Settings</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
            <div>
              <label style="font-size:.8rem;color:var(--muted);display:block;margin-bottom:.3rem">Order Number Prefix</label>
              <input type="text" id="st-order_prefix" placeholder="NH" maxlength="5" style="width:100%;padding:.5rem .75rem;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text);font-size:.88rem">
              <div style="font-size:.72rem;color:var(--muted);margin-top:.3rem">e.g. NH → NH-000001</div>
            </div>
            <div>
              <label style="font-size:.8rem;color:var(--muted);display:block;margin-bottom:.3rem">Default Prep Time (min)</label>
              <input type="number" id="st-default_prep_time" placeholder="20" min="1" max="120" style="width:100%;padding:.5rem .75rem;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text);font-size:.88rem">
            </div>
            <div>
              <label style="font-size:.8rem;color:var(--muted);display:block;margin-bottom:.3rem">Tax Rate (%)</label>
              <input type="number" id="st-tax_rate" placeholder="0" min="0" max="30" step="0.5" style="width:100%;padding:.5rem .75rem;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text);font-size:.88rem">
              <div style="font-size:.72rem;color:var(--muted);margin-top:.3rem">0 = tax မပါ</div>
            </div>
            <div>
              <label style="font-size:.8rem;color:var(--muted);display:block;margin-bottom:.3rem">Min Order Amount (MMK)</label>
              <input type="number" id="st-min_order_amount" placeholder="1000" min="0" style="width:100%;padding:.5rem .75rem;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text);font-size:.88rem">
            </div>
          </div>
          <div style="margin-top:1rem">
            <label style="font-size:.8rem;color:var(--muted);display:block;margin-bottom:.3rem">Receipt Header Text</label>
            <input type="text" id="st-receipt_header" placeholder="Thank you for dining with us!" style="width:100%;padding:.5rem .75rem;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text);font-size:.88rem">
          </div>
          <div style="margin-top:.75rem">
            <label style="font-size:.8rem;color:var(--muted);display:block;margin-bottom:.3rem">Receipt Footer Text</label>
            <input type="text" id="st-receipt_footer" placeholder="Please come again! 🙏" style="width:100%;padding:.5rem .75rem;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text);font-size:.88rem">
          </div>
        </div>

        <!-- NOTIFICATION SETTINGS -->
        <div class="card" style="margin-top:1rem">
          <div style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:.8rem">🔔 Notification Settings</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
            <div>
              <label style="font-size:.8rem;color:var(--muted);display:block;margin-bottom:.3rem">KDS Sound Alert</label>
              <select id="st-kds_sound" style="width:100%;padding:.5rem .75rem;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text);font-size:.88rem">
                <option value="1">✅ On</option>
                <option value="0">❌ Off</option>
              </select>
            </div>
            <div>
              <label style="font-size:.8rem;color:var(--muted);display:block;margin-bottom:.3rem">KDS Overdue Threshold (min)</label>
              <input type="number" id="st-kds_overdue_mins" placeholder="15" min="5" max="60" style="width:100%;padding:.5rem .75rem;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text);font-size:.88rem">
            </div>
            <div>
              <label style="font-size:.8rem;color:var(--muted);display:block;margin-bottom:.3rem">Waiter Order Alert</label>
              <select id="st-waiter_alert" style="width:100%;padding:.5rem .75rem;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text);font-size:.88rem">
                <option value="1">✅ On</option>
                <option value="0">❌ Off</option>
              </select>
            </div>
            <div>
              <label style="font-size:.8rem;color:var(--muted);display:block;margin-bottom:.3rem">Default Language</label>
              <select id="st-default_lang" style="width:100%;padding:.5rem .75rem;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text);font-size:.88rem">
                <option value="en">English</option>
                <option value="mm">မြန်မာ</option>
              </select>
            </div>
          </div>
        </div>


          <div style="margin-bottom:.75rem">
            <label style="font-size:.82rem;font-weight:600;display:block;margin-bottom:.3rem">Stamps required for reward</label>
            <input type="number" id="st-loyalty_stamps_required" min="1" max="50" value="10"
              style="width:100px;font-size:.85rem;padding:.35rem .6rem;border:1px solid #ddd;border-radius:6px">
          </div>

          <div style="margin-bottom:.75rem">
            <label style="font-size:.82rem;font-weight:600;display:block;margin-bottom:.3rem">Reward description</label>
            <input type="text" id="st-loyalty_reward_label" value="Free item တစ်ခု"
              style="width:100%;font-size:.85rem;padding:.35rem .6rem;border:1px solid #ddd;border-radius:6px">
          </div>

          <!-- Loyalty cards list -->
          <div style="margin-top:1rem">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem">
              <div style="font-size:.82rem;font-weight:600">Customer Stamp Cards</div>
              <button onclick="loadLoyaltyCards()" style="padding:.3rem .7rem;background:#e84c2b;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.78rem">🔄 Refresh</button>
            </div>
            <div id="loyalty-cards-list" style="font-size:.82rem;color:var(--muted)">— Load လုပ်ရန် Refresh နှိပ်ပါ —</div>

          <!-- Loyalty Edit Modal -->
          <div id="loy-edit-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center">
            <div style="background:var(--bg);border-radius:14px;padding:1.5rem;max-width:340px;width:92%;position:relative">
              <button onclick="document.getElementById('loy-edit-modal').style.display='none'" style="position:absolute;top:1rem;right:1rem;background:none;border:none;font-size:1.2rem;cursor:pointer">✕</button>
              <div style="font-weight:600;margin-bottom:1rem">✏️ Edit Loyalty Card</div>
              <input type="hidden" id="loy-edit-phone-orig">
              <div style="margin-bottom:.6rem">
                <label style="font-size:.8rem;color:var(--muted)">Phone</label>
                <input type="text" id="loy-edit-phone" style="width:100%;padding:.4rem .6rem;border:1px solid #ddd;border-radius:6px;font-size:.88rem;margin-top:2px">
              </div>
              <div style="margin-bottom:.6rem">
                <label style="font-size:.8rem;color:var(--muted)">Stamps</label>
                <input type="number" id="loy-edit-stamps" min="0" max="999" style="width:100%;padding:.4rem .6rem;border:1px solid #ddd;border-radius:6px;font-size:.88rem;margin-top:2px">
              </div>
              <div style="margin-bottom:1rem">
                <label style="font-size:.8rem;color:var(--muted)">Total Redeemed</label>
                <input type="number" id="loy-edit-redeemed" min="0" style="width:100%;padding:.4rem .6rem;border:1px solid #ddd;border-radius:6px;font-size:.88rem;margin-top:2px">
              </div>
              <div style="display:flex;gap:.5rem">
                <button onclick="saveLoyaltyEdit()" style="flex:1;padding:.6rem;background:#e84c2b;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:.88rem">💾 Save</button>
                <button onclick="deleteLoyaltyCard()" style="padding:.6rem 1rem;background:#dc3545;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:.88rem">🗑 Delete</button>
              </div>
            </div>
          </div>
          </div>





          <!-- Live footer preview -->
          <div style="margin-top:.8rem">
            <div style="font-size:.75rem;font-weight:500;color:var(--muted);margin-bottom:.4rem">Preview</div>
            <div id="footer-preview-box" style="border-radius:8px;padding:1rem 1.2rem;position:relative;overflow:hidden;min-height:60px">
              <div id="fp-overlay" style="position:absolute;inset:0;background:#1a1209;pointer-events:none"></div>
              <div id="fp-bg"      style="position:absolute;inset:0;background-size:cover;background-position:center;opacity:.18;pointer-events:none;display:none"></div>
              <div style="position:relative;z-index:1;color:#b8a48a;font-size:.82rem">
                <span id="fp-store" style="font-family:'Playfair Display',serif;font-size:1rem;color:#fff">NoodleHaus</span><br>
                <span id="fp-copy" style="font-size:.72rem">© 2025 NoodleHaus. All rights reserved.</span>
              </div>
            </div>
          </div>

        <button class="btn btn-primary" onclick="saveSettings()" style="width:100%;padding:.85rem;font-size:.95rem">
          💾 Save Settings
        </button>

        <!-- PAYMENT -->
        <div class="card" style="margin-bottom:1.5rem">
            <label style="font-size:.82rem;font-weight:600;display:block;margin-bottom:.4rem">KPay QR Code Image</label>
            <div style="font-size:.75rem;color:var(--muted);margin-bottom:.5rem">Customer checkout မှာ KPay ရွေးရင် ဒီ QR image ပြမည်</div>
            <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
              <input type="file" id="kpay-qr-input" accept="image/*"
                onchange="uploadKpayQr(this)"
                style="font-size:.82rem;max-width:220px">
              <button type="button" id="kpay-qr-remove-btn"
                onclick="removeKpayQr()"
                style="display:none;padding:.3rem .7rem;background:#dc3545;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.8rem">✕ Remove</button>
            </div>
            <div id="kpay-qr-preview" style="margin-top:.6rem;display:none">
              <img id="kpay-qr-img" src="" alt="KPay QR"
                style="max-width:100px;max-height:100px;border:2px solid #e84c2b;border-radius:8px;object-fit:contain;background:#fff;padding:4px">
              <div id="kpay-qr-status" style="font-size:.75rem;color:#28a745;margin-top:.3rem">✅ QR image ထည့်ပြီး</div>
            </div>
            <div id="kpay-qr-no-img" style="font-size:.78rem;color:var(--muted);margin-top:.4rem">⚠️ QR image မရှိသေး — upload လုပ်ပါ</div>
          </div>

          <!-- ═══ LOYALTY SETTINGS ═══ -->
          <div style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:.8rem;margin-top:1.5rem">🎟 Loyalty Program</div>

          <div style="margin-bottom:.75rem;display:flex;align-items:center;gap:.75rem">
            <label style="font-size:.85rem;font-weight:600">Enable Loyalty Program</label>
            <select id="st-loyalty_enabled" style="font-size:.82rem;padding:.3rem .6rem;border:1px solid #ddd;border-radius:6px">
              <option value="1">✅ On</option>
              <option value="0">❌ Off</option>
            </select>
          </div>

          <div style="margin-bottom:.75rem">
            <label style="font-size:.82rem;font-weight:600;display:block;margin-bottom:.3rem">Stamps required for reward</label>
            <input type="number" id="st-loyalty_stamps_required" min="1" max="50" value="10"
              style="width:100px;font-size:.85rem;padding:.35rem .6rem;border:1px solid #ddd;border-radius:6px">
          </div>

          <div style="margin-bottom:.75rem">
            <label style="font-size:.82rem;font-weight:600;display:block;margin-bottom:.3rem">Reward description</label>
            <input type="text" id="st-loyalty_reward_label" value="Free item တစ်ခု"
              style="width:100%;font-size:.85rem;padding:.35rem .6rem;border:1px solid #ddd;border-radius:6px">
          </div>

          <!-- Loyalty cards list -->
          <div style="margin-top:1rem">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem">
              <div style="font-size:.82rem;font-weight:600">Customer Stamp Cards</div>
              <button onclick="loadLoyaltyCards()" style="padding:.3rem .7rem;background:#e84c2b;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.78rem">🔄 Refresh</button>
            </div>
            <div id="loyalty-cards-list" style="font-size:.82rem;color:var(--muted)">— Load လုပ်ရန် Refresh နှိပ်ပါ —</div>

          <!-- Loyalty Edit Modal -->
          <div id="loy-edit-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center">
            <div style="background:var(--bg);border-radius:14px;padding:1.5rem;max-width:340px;width:92%;position:relative">
              <button onclick="document.getElementById('loy-edit-modal').style.display='none'" style="position:absolute;top:1rem;right:1rem;background:none;border:none;font-size:1.2rem;cursor:pointer">✕</button>
              <div style="font-weight:600;margin-bottom:1rem">✏️ Edit Loyalty Card</div>
              <input type="hidden" id="loy-edit-phone-orig">
              <div style="margin-bottom:.6rem">
                <label style="font-size:.8rem;color:var(--muted)">Phone</label>
                <input type="text" id="loy-edit-phone" style="width:100%;padding:.4rem .6rem;border:1px solid #ddd;border-radius:6px;font-size:.88rem;margin-top:2px">
              </div>
              <div style="margin-bottom:.6rem">
                <label style="font-size:.8rem;color:var(--muted)">Stamps</label>
                <input type="number" id="loy-edit-stamps" min="0" max="999" style="width:100%;padding:.4rem .6rem;border:1px solid #ddd;border-radius:6px;font-size:.88rem;margin-top:2px">
              </div>
              <div style="margin-bottom:1rem">
                <label style="font-size:.8rem;color:var(--muted)">Total Redeemed</label>
                <input type="number" id="loy-edit-redeemed" min="0" style="width:100%;padding:.4rem .6rem;border:1px solid #ddd;border-radius:6px;font-size:.88rem;margin-top:2px">
              </div>
              <div style="display:flex;gap:.5rem">
                <button onclick="saveLoyaltyEdit()" style="flex:1;padding:.6rem;background:#e84c2b;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:.88rem">💾 Save</button>
                <button onclick="deleteLoyaltyCard()" style="padding:.6rem 1rem;background:#dc3545;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:.88rem">🗑 Delete</button>
          </div>
      </div>
    </div>

      </div>
    </div>

    <div id="page-storefront" style="display:none">
      <div class="page-head">
        <div style="display:flex;align-items:center;gap:.5rem">
          <button class="hamburger" onclick="openSidebar()" title="Menu">☰</button>
          <div class="page-title">🎨 Storefront</div>
        </div>
        <button class="btn btn-primary" onclick="saveSettings()">💾 Save</button>
      </div>
      <div class="content">
        <p style="font-size:.82rem;color:var(--text-muted);margin-bottom:1rem">Customer ordering page (/index.html) ရဲ့ appearance ပြင်ဆင်ပါ</p>
     <!-- HEADER APPEARANCE -->
        <div style="margin-bottom:1.5rem">
          <div style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:.8rem">🖥 Header Appearance</div>
          <div class="form-grid">
            <div class="form-group">
              <label>Background Color</label>
              <input type="color" id="st-header_bg_color" value="#1a1209" style="height:40px;padding:.3rem;width:100%"
                oninput="updateHeaderPreview()">
            </div>
            <div class="form-group">
              <label>Logo Accent Color</label>
              <input type="color" id="st-header_logo_text_color" value="#f0a500" style="height:40px;padding:.3rem;width:100%"
                oninput="updateHeaderPreview()">
            </div>
            <div class="form-group">
              <label>Status Text Color</label>
              <input type="color" id="st-header_text_color" value="#b8a48a" style="height:40px;padding:.3rem;width:100%"
                oninput="updateHeaderPreview()">
            </div>
            <div class="form-group">
              <label>Image Opacity (0–1)</label>
              <input type="range" id="st-header_bg_img_opacity" min="0" max="1" step="0.05" value="0.2"
                oninput="document.getElementById('hdr-opacity-val').textContent=this.value;updateHeaderPreview()"
                style="width:100%;margin-top:8px">
              <span style="font-size:.82rem;color:var(--muted)" id="hdr-opacity-val">0.2</span>
            </div>
          </div>

          <!-- Header background image upload -->
          <div class="form-group">
            <label>Header Background Image</label>
            <div style="display:flex;gap:.8rem;align-items:center;flex-wrap:wrap">
              <img id="header-bg-preview" src="" alt="" style="max-height:50px;max-width:120px;border-radius:6px;object-fit:cover;display:none;border:1px solid var(--border)">
              <div>
                <input type="file" id="header-bg-file" accept="image/*"
                  onchange="uploadHeaderImg(this)" style="font-size:.82rem">
                <div style="font-size:.75rem;color:var(--muted);margin-top:.2rem">JPG/PNG/WEBP — max 3MB — အကောင်းဆုံး 1400×70px</div>
              </div>
              <button class="btn btn-danger btn-sm" id="header-bg-remove-btn"
                onclick="removeHeaderImg()" style="display:none">✕ Remove</button>
            </div>
          </div>

          <!-- Live header preview -->
          <div style="margin-top:.8rem">
            <div style="font-size:.75rem;font-weight:500;color:var(--muted);margin-bottom:.4rem">Preview</div>
            <div id="header-preview-box" style="border-radius:8px;height:56px;display:flex;align-items:center;justify-content:space-between;padding:0 1.2rem;position:relative;overflow:hidden;transition:background .3s">
              <div id="hp-bg-img" style="position:absolute;inset:0;background-size:cover;background-position:center;display:none;pointer-events:none"></div>
              <div style="position:relative;z-index:1;font-family:'Playfair Display',serif;font-size:1.1rem;font-weight:900;display:flex;align-items:center;gap:.4rem;color:#fff">
                <span id="hp-emoji">🍜</span>
                <span>Noodle<span id="hp-accent" style="color:#f0a500">Haus</span></span>
              </div>
              <div style="position:relative;z-index:1;display:flex;align-items:center;gap:.8rem">
                <span id="hp-status" style="font-size:.75rem">● Open until 10 PM</span>
                <div style="background:#e84c2b;color:#fff;padding:.3rem .8rem;border-radius:50px;font-size:.8rem;font-weight:600">🛒 Cart 0</div>
              </div>
            </div>
          </div>
        </div>

        <!-- HERO SECTION -->
        <div style="margin-bottom:1.5rem">
          <div style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:.8rem">🦸 Hero Banner</div>

          <!-- Content -->
          <div class="form-grid" style="margin-bottom:.8rem">
            <div class="form-group">
              <label>Badge Text</label>
              <input type="text" id="st-hero_badge" placeholder="🔥 Live Kitchen — 20 min delivery"
                oninput="updateHeroPreview()">
            </div>
            <div class="form-group">
              <label>Watermark Emoji</label>
              <input type="text" id="st-hero_emoji" placeholder="🍜" maxlength="4"
                style="font-size:1.4rem;text-align:center" oninput="updateHeroPreview()">
            </div>
            <div class="form-group">
              <label>Hero Title Line 1</label>
              <input type="text" id="st-hero_title_line1" placeholder="Authentic Asian"
                oninput="updateHeroPreview()">
            </div>
            <div class="form-group">
              <label>Hero Title Line 2</label>
              <input type="text" id="st-hero_title_line2" placeholder="Noodles & More"
                oninput="updateHeroPreview()">
            </div>
            <div class="form-group full-width">
              <label>Hero Subtitle</label>
              <input type="text" id="st-hero_subtitle" placeholder="Freshly prepared, delivered hot."
                oninput="updateHeroPreview()">
            </div>
          </div>

          <!-- Appearance -->
          <div class="form-grid" style="margin-bottom:.8rem">
            <div class="form-group">
              <label>Background Color</label>
              <input type="color" id="st-hero_bg_color" value="#1a1209"
                style="height:40px;padding:.3rem;width:100%" oninput="updateHeroPreview()">
            </div>
            <div class="form-group">
              <label>Title Color</label>
              <input type="color" id="st-hero_title_color" value="#ffffff"
                style="height:40px;padding:.3rem;width:100%" oninput="updateHeroPreview()">
            </div>
            <div class="form-group">
              <label>Subtitle Color</label>
              <input type="color" id="st-hero_subtitle_color" value="#b8a48a"
                style="height:40px;padding:.3rem;width:100%" oninput="updateHeroPreview()">
            </div>
            <div class="form-group">
              <label>Badge Color</label>
              <input type="color" id="st-hero_badge_color" value="#f0a500"
                style="height:40px;padding:.3rem;width:100%" oninput="updateHeroPreview()">
            </div>
            <div class="form-group full-width">
              <label>Image Opacity (0–1)</label>
              <input type="range" id="st-hero_bg_img_opacity" min="0" max="1" step="0.05" value="0.3"
                oninput="document.getElementById('hero-opacity-val').textContent=this.value;updateHeroPreview()"
                style="width:100%;margin-top:8px">
              <span style="font-size:.82rem;color:var(--muted)" id="hero-opacity-val">0.3</span>
            </div>
          </div>

          <!-- Image upload -->
          <div class="form-group">
            <label>Hero Background Image</label>
            <div style="display:flex;gap:.8rem;align-items:center;flex-wrap:wrap">
              <img id="hero-bg-preview" src="" alt="" style="max-height:55px;max-width:120px;border-radius:6px;object-fit:cover;display:none;border:1px solid var(--border)">
              <div>
                <input type="file" id="hero-bg-file" accept="image/*"
                  onchange="uploadSectionImg(this,'hero')" style="font-size:.82rem">
                <div style="font-size:.75rem;color:var(--muted);margin-top:.2rem">JPG/PNG/WEBP — max 3MB — အကောင်းဆုံး 1400×400px</div>
              </div>
              <button class="btn btn-danger btn-sm" id="hero-bg-remove-btn"
                onclick="removeSectionImg('hero')" style="display:none">✕ Remove</button>
            </div>
          </div>

          <!-- Live Preview -->
          <div style="margin-top:.8rem">
            <div style="font-size:.75rem;font-weight:500;color:var(--muted);margin-bottom:.4rem">Preview</div>
            <div id="hero-preview-box" style="border-radius:8px;padding:1.5rem 1.4rem;position:relative;overflow:hidden;background:#1a1209;transition:background .3s">
              <div id="hbp-bg" style="position:absolute;inset:0;background-size:cover;background-position:center;opacity:.3;pointer-events:none;display:none"></div>
              <div style="position:relative;z-index:1">
                <div id="hbp-badge" style="display:inline-block;border:1px solid #f0a500;color:#f0a500;padding:.2rem .7rem;border-radius:50px;font-size:.72rem;font-weight:600;margin-bottom:.5rem">🔥 Live Kitchen — 20 min delivery</div>
                <div id="hbp-title" style="font-family:'Playfair Display',serif;font-size:1.3rem;font-weight:900;color:#fff;line-height:1.2;margin-bottom:.3rem">
                  Authentic Asian<br><em style="color:#f0a500;font-style:normal">Noodles & More</em>
                </div>
                <div id="hbp-sub" style="font-size:.8rem;color:#b8a48a">Freshly prepared, delivered hot.</div>
              </div>
              <div style="position:absolute;right:8%;top:50%;transform:translateY(-50%);font-size:3.5rem;opacity:.15;z-index:0" id="hbp-emoji">🍜</div>
            </div>
          </div>
        </div>

        <!-- ANNOUNCEMENT BANNER -->
        <div style="margin-bottom:1.5rem">
          <div style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:.8rem">📢 Announcement Banner</div>
          <div class="form-grid">
            <div class="form-group full-width">
              <label>Banner Text (ဗလာ ထားရင် မပြ)</label>
              <input type="text" id="st-announcement_text"
                placeholder="🎉 Today's Special: Free delivery on orders over $20!"
                oninput="updateAnnPreview()">
            </div>
            <div class="form-group">
              <label>Banner Color</label>
              <input type="color" id="st-announcement_color" value="#e84c2b"
                style="height:40px;padding:.3rem" oninput="updateAnnPreview()">
            </div>
            <div class="form-group">
              <label>Show Banner</label>
              <select id="st-announcement_on" onchange="updateAnnPreview()">
                <option value="0">Hidden</option>
                <option value="1">Visible</option>
              </select>
            </div>
          </div>
          <!-- Live preview -->
          <div style="margin-top:.6rem">
            <div style="font-size:.75rem;font-weight:500;color:var(--muted);margin-bottom:.3rem">Preview</div>
            <div id="ann-preview" style="display:none;padding:.6rem 1rem;border-radius:8px;font-size:.9rem;font-weight:500;color:#fff;text-align:center;transition:background .3s"></div>
            <div id="ann-preview-hidden" style="padding:.6rem 1rem;border-radius:8px;font-size:.82rem;color:var(--muted);text-align:center;background:var(--warm);border:1px dashed var(--border)">Banner မပြဘဲ ဖျောက်ထားသည်</div>
          </div>
        </div>

        <!-- FOOTER -->
        <div style="margin-bottom:1.5rem">
          <div style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:.8rem">📍 Footer Info</div>
          <div class="form-grid">
            <div class="form-group">
              <label>Phone</label>
              <input type="text" id="st-footer_phone" placeholder="+95 9xxxxxxxx">
            </div>
            <div class="form-group">
              <label>Address</label>
              <input type="text" id="st-footer_address" placeholder="Yangon, Myanmar">
            </div>
            <div class="form-group">
              <label>Facebook URL</label>
              <input type="url" id="st-footer_facebook" placeholder="https://facebook.com/...">
            </div>
            <div class="form-group">
              <label>Instagram URL</label>
              <input type="url" id="st-footer_instagram" placeholder="https://instagram.com/...">
            </div>
            <div class="form-group">
              <label>TikTok URL</label>
              <input type="url" id="st-footer_tiktok" placeholder="https://tiktok.com/@...">
            </div>
            <div class="form-group full-width">
              <label>Copyright Text</label>
              <input type="text" id="st-footer_copyright" placeholder="© 2025 NoodleHaus. All rights reserved.">
            </div>
          </div>
        </div>

        <!-- FOOTER APPEARANCE -->
    </div>
    </div>
    </div><!-- /page-settings fix -->
    <!-- ── ORDERS PAGE ── -->
    <div id="page-orders" style="display:none">
      <div class="page-head">
        <div style="display:flex;align-items:center;gap:.5rem">
          <button class="hamburger" onclick="openSidebar()" title="Menu">☰</button>
          <div class="page-title">All Orders</div>
        </div>
        <div style="display:flex;gap:.5rem">
          <button class="btn btn-ghost btn-sm" onclick="showDeletedLog()">📁 Archive</button>
          <button class="btn btn-ghost btn-sm" onclick="loadOrders()">↺</button>
          <button class="btn btn-ghost btn-sm" onclick="openCustHistory()">👤 Customer</button>
          <button class="btn btn-ghost btn-sm" onclick="openBulkDelete()" style="color:#dc3545">🗑 Bulk</button>
        </div>
      </div>
      <div class="content">
        <div class="table-wrap">
          <div style="overflow-x:auto">
            <table>
              <thead><tr>
                <th>Order</th><th>Customer</th><th class="hide-mobile">Phone</th>
                <th>Items</th><th>Total</th><th>Payment</th><th>Status</th><th>Date</th><th></th>
              </tr></thead>
              <tbody id="orders-body"><tr><td colspan="8" style="text-align:center;color:var(--muted);padding:2rem">Loading…</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── ADD / EDIT MODAL ── -->
<div class="modal-bg" id="item-modal">
  <div class="modal">
    <div class="modal-head">
      <h3 id="modal-title">Add Menu Item</h3>
      <button class="x-btn" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="f-id">
      <div class="form-grid">
        <div class="form-group">
          <label>Emoji</label>
          <input type="text" id="f-emoji" placeholder="🍜" maxlength="4" style="font-size:1.4rem;text-align:center">
        </div>
        <div class="form-group">
          <label>Category</label>
          <select id="f-cat">
            <option>Noodles</option><option>Rice</option><option>Starters</option>
            <option>Soups</option><option>Desserts</option><option>Drinks</option>
          </select>
        </div>
        <div class="form-group full-width">
          <label>Item Name *</label>
          <input type="text" id="f-name" placeholder="e.g. Mohinga">
        </div>
        <div class="form-group full-width">
          <label>Description</label>
          <textarea id="f-desc" placeholder="Short description…"></textarea>
        </div>
        <div class="form-group">
          <label>Price (USD $) *</label>
          <input type="number" id="f-price" placeholder="4500" min="0">
        </div>
        <div class="form-group">
          <label>Stock Qty *</label>
          <input type="number" id="f-stock" placeholder="20" min="0">
        </div>
        <div class="form-group full-width" id="active-row" style="display:none">
          <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer">
            <input type="checkbox" id="f-active" style="width:16px;height:16px">
            Show on menu (Active)
          </label>
        </div>
        <div class="form-group" id="station-row">
          <label>Kitchen Station</label>
          <select id="f-station">
            <option value="kitchen">🍳 Kitchen</option>
            <option value="counter">🥤 Counter</option>
            <option value="bar">🍹 Bar</option>
            <option value="all">📋 All Stations</option>
          </select>
        </div>
        <div class="form-group full-width" id="img-upload-row" style="display:none">
          <label>ဓာတ်ပုံ (optional)</label>
          <div style="display:flex;align-items:center;gap:.8rem;flex-wrap:wrap">
            <img id="img-current-preview" class="img-current" src="" alt="" style="display:none">
            <div style="flex:1">
              <div class="img-upload-area" onclick="document.getElementById('img-file-input').click()">
                <input type="file" id="img-file-input" accept="image/*" onchange="previewImg(this)">
                <div id="img-upload-label">📷 ဓာတ်ပုံရွေးချယ်ရန် နှိပ်ပါ<br><small style="color:var(--muted)">JPG/PNG/GIF/WEBP — Max 2MB</small></div>
                <img id="img-new-preview" class="img-preview" src="" alt="" style="display:none">
              </div>
              <div style="display:flex;gap:.5rem;margin-top:.5rem">
                <button type="button" class="btn btn-ghost btn-sm" onclick="uploadImg()" id="img-upload-btn" style="display:none">↑ Upload</button>
                <button type="button" class="btn btn-danger btn-sm" onclick="removeImg()" id="img-remove-btn" style="display:none">✕ Remove</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="modal-foot" style="justify-content:space-between">
      <div style="display:flex;gap:.5rem">
        <button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
        <button class="btn btn-ghost" id="modifier-btn" onclick="openModifierModal()" style="display:none">⚙️ Modifiers</button>
      </div>
      <button class="btn btn-primary" id="modal-save-btn" onclick="saveItem()">Save Item</button>
    </div>
  </div>
</div>

<!-- ── MODIFIER SECTION (shown below item modal when editing) ── -->
<div class="modal-bg" id="modifier-modal">
  <div class="modal" style="max-width:680px">
    <div class="modal-head">
      <h3>⚙️ Modifiers — <span id="mod-item-name"></span></h3>
      <button class="x-btn" onclick="closeModifierModal()">✕</button>
    </div>
    <div class="modal-body">
      <p style="font-size:.82rem;color:var(--muted);margin-bottom:1rem">
        Modifier group တွေ ထည့်ပြီး customer မှာတဲ့အချိန် ရွေးချယ်နိုင်အောင် လုပ်ပေးပါ။
      </p>
      <div id="modifier-groups-list"></div>
      <button class="btn btn-ghost" style="width:100%;margin-top:.8rem" onclick="openAddGroupForm()">
        + Add Modifier Group
      </button>

      <!-- Add/Edit Group Form -->
      <div id="group-form" style="display:none;margin-top:1rem;padding:1rem;background:var(--surface);border-radius:10px;border:1px solid var(--border)">
        <input type="hidden" id="gf-id">
        <div class="form-grid">
          <div class="form-group">
            <label>Group Name *</label>
            <input type="text" id="gf-name" placeholder="e.g. Size, Ice Level">
          </div>
          <div class="form-group">
            <label>Type</label>
            <select id="gf-type">
              <option value="single">Single Select (တစ်ခုပဲရွေး)</option>
              <option value="multi">Multi Select (တစ်ခုထက်ပိုရွေး)</option>
              <option value="text">Free Text (မှတ်ချက်)</option>
            </select>
          </div>
          <div class="form-group">
            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
              <input type="checkbox" id="gf-required" style="width:16px;height:16px">
              Required (မဖြစ်မနေ ရွေးရမည်)
            </label>
          </div>
        </div>
        <div style="display:flex;gap:.5rem;margin-top:.5rem">
          <button class="btn btn-primary btn-sm" onclick="saveModifierGroup()">Save Group</button>
          <button class="btn btn-ghost btn-sm" onclick="cancelGroupForm()">Cancel</button>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-primary" onclick="closeModifierModal()">Done</button>
    </div>
  </div>
</div>

<!-- ── OPTION FORM MODAL ── -->
<div class="modal-bg" id="option-modal">
  <div class="modal" style="max-width:420px">
    <div class="modal-head">
      <h3 id="opt-modal-title">Add Option</h3>
      <button class="x-btn" onclick="closeOptionModal()">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="of-id">
      <input type="hidden" id="of-group-id">
      <div class="form-group">
        <label>Option Label *</label>
        <input type="text" id="of-label" placeholder="e.g. Large, No Ice, Extra Egg">
      </div>
      <div class="form-group">
        <label>Extra Price (ကျပ်) — 0 = free</label>
        <input type="number" id="of-price" placeholder="0" min="0" value="0">
      </div>
      <div class="form-group">
        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
          <input type="checkbox" id="of-default" style="width:16px;height:16px">
          Default selection
        </label>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" onclick="closeOptionModal()">Cancel</button>
      <button class="btn btn-primary" onclick="saveModifierOption()">Save</button>
    </div>
  </div>
</div>
<div class="modal-bg" id="batch-modal">
  <div class="modal" style="max-width:720px">
    <div class="modal-head">
      <h3>📥 Batch Upload Menu Items</h3>
      <button class="x-btn" onclick="closeBatchModal()">✕</button>
    </div>
    <div class="modal-body" id="batch-modal-body">

      <!-- Step 1: Upload -->
      <div id="batch-step1">
        <div style="background:var(--warm);border-radius:10px;padding:1rem;margin-bottom:1rem;font-size:.85rem;line-height:1.8;border:1px solid var(--border)">
          <strong>CSV format (ပထမ row = header) —</strong><br>
          <code style="font-size:.8rem">name, category, price, stock, emoji, description</code><br>
          <span style="color:var(--muted)">Category (English): Noodles / Rice / Starters / Soups / Desserts / Drinks</span><br>
          <span style="color:var(--muted)">Myanmar aliases: ခေါက်ဆွဲ=Noodles · ထမင်း=Rice · ဟင်းချို=Soups · အချိုရည်=Drinks</span><br>
          <span style="color:var(--muted)">Price: dollar cents မဟုတ်ဘဲ display value (e.g. 4.50)</span>
        </div>

        <!-- Drop zone -->
        <div id="batch-dropzone"
          style="border:2px dashed var(--border);border-radius:12px;padding:2.5rem;text-align:center;cursor:pointer;transition:border-color .2s;background:var(--warm)"
          onclick="document.getElementById('batch-file').click()"
          ondragover="event.preventDefault();this.style.borderColor='var(--ink)'"
          ondragleave="this.style.borderColor='var(--border)'"
          ondrop="event.preventDefault();this.style.borderColor='var(--border)';handleBatchFile(event.dataTransfer.files[0])">
          <div style="font-size:2.5rem;margin-bottom:.5rem">📂</div>
          <div style="font-weight:600;margin-bottom:.3rem">CSV ဖိုင် ဒီနေရာတွင် ချထားပါ</div>
          <div style="font-size:.82rem;color:var(--muted)">သို့မဟုတ် နှိပ်ပြီး ရွေးပါ (.csv only)</div>
          <input type="file" id="batch-file" accept=".csv,.txt" style="display:none"
            onchange="handleBatchFile(this.files[0])">
        </div>

        <div style="margin-top:.8rem;text-align:center">
          <button class="btn btn-ghost btn-sm" onclick="downloadTemplate()">⬇ CSV Template ဒေါင်းလုဒ်</button>
        </div>
      </div>

      <!-- Step 2: Preview -->
      <div id="batch-step2" style="display:none">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem;flex-wrap:wrap;gap:.5rem">
          <div id="batch-summary" style="font-size:.88rem"></div>
          <button class="btn btn-ghost btn-sm" onclick="resetBatch()">↺ ပြန်ရွေး</button>
        </div>

        <!-- Errors -->
        <div id="batch-errors" style="display:none;background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:.8rem 1rem;margin-bottom:.8rem;font-size:.82rem;color:#991b1b;max-height:100px;overflow-y:auto"></div>

        <!-- Preview table -->
        <div style="overflow-x:auto;max-height:320px;overflow-y:auto;border-radius:8px;border:1px solid var(--border)">
          <table style="width:100%;border-collapse:collapse;font-size:.82rem">
            <thead style="position:sticky;top:0;background:var(--warm);z-index:1">
              <tr>
                <th style="padding:.5rem .8rem;text-align:left;font-size:.72rem;text-transform:uppercase;color:var(--muted)">Row</th>
                <th style="padding:.5rem .8rem;text-align:left">Name</th>
                <th style="padding:.5rem .8rem;text-align:left">Category</th>
                <th style="padding:.5rem .8rem;text-align:right">Price</th>
                <th style="padding:.5rem .8rem;text-align:right">Stock</th>
                <th style="padding:.5rem .8rem;text-align:center">Emoji</th>
                <th style="padding:.5rem .8rem;text-align:left">Description</th>
              </tr>
            </thead>
            <tbody id="batch-preview-body"></tbody>
          </table>
        </div>
      </div>

    </div>
    <div class="modal-foot" id="batch-modal-foot">
      <button class="btn btn-ghost" onclick="closeBatchModal()">Cancel</button>
    </div>
  </div>
</div>

<!-- ── RESTOCK MODAL ── -->
<div class="modal-bg" id="restock-modal">
  <div class="modal" style="max-width:360px">
    <div class="modal-head">
      <h3>↑ Restock</h3>
      <button class="x-btn" onclick="closeRestock()">✕</button>
    </div>
    <div class="modal-body">
      <div style="font-size:.9rem;color:var(--muted);margin-bottom:.8rem" id="restock-name"></div>
      <div style="font-size:.85rem;margin-bottom:.8rem">Current stock: <strong id="restock-current"></strong></div>
      <div class="form-group">
        <label>Add Qty</label>
        <input type="number" id="restock-qty" placeholder="e.g. 50" min="1" style="font-size:1rem">
      </div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button class="btn btn-ghost btn-sm" onclick="setRestock(10)">+10</button>
        <button class="btn btn-ghost btn-sm" onclick="setRestock(20)">+20</button>
        <button class="btn btn-ghost btn-sm" onclick="setRestock(50)">+50</button>
        <button class="btn btn-ghost btn-sm" onclick="setRestock(100)">+100</button>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" onclick="closeRestock()">Cancel</button>
      <button class="btn btn-success" onclick="doRestock()">✓ Add Stock</button>
    </div>
  </div>
</div>

<?php endif; ?>
<!-- DELETE ORDER MODAL -->
<div class="modal-bg" id="del-order-modal">
  <div class="modal" style="max-width:440px">
    <div class="modal-head" style="background:#991b1b">
      <h3>🗑 Delete Order</h3>
      <button class="x-btn" onclick="closeDelOrder()">✕</button>
    </div>
    <div class="modal-body">
      <div style="font-size:.9rem;margin-bottom:1rem">
        Order <strong id="del-order-ref"></strong> ကို ဖျက်မည်။
        <span style="color:var(--muted);font-size:.82rem">Record ကိုတော့ archive ထားမည်။</span>
      </div>
      <div style="font-size:.82rem;font-weight:600;margin-bottom:.5rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Reason ရွေးပါ</div>
      <div class="reason-grid" id="reason-grid"></div>
      <div class="form-group" style="margin-top:.5rem">
        <label>Remark (ထပ်ဖြည့်ရန်)</label>
        <textarea id="del-remark" placeholder="ပိုရှင်းလင်းသော အကြောင်းပြချက်…" style="min-height:60px"></textarea>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" onclick="closeDelOrder()">Cancel</button>
      <button class="btn btn-danger" onclick="confirmDelOrder()">🗑 Delete</button>
    </div>
  </div>
</div>

<!-- DELETED ORDERS LOG MODAL -->
<div class="modal-bg" id="deleted-log-modal">
  <div class="modal" style="max-width:700px">
    <div class="modal-head">
      <h3>📁 Deleted Orders Archive</h3>
      <button class="x-btn" onclick="document.getElementById('deleted-log-modal').classList.remove('open')">✕</button>
    </div>
    <div class="modal-body" style="padding:.8rem">
      <div style="overflow-x:auto">
        <table>
          <thead><tr>
            <th>Order</th><th>Customer</th><th>Total</th>
            <th>Reason</th><th>Deleted At</th>
          </tr></thead>
          <tbody id="deleted-log-body">
            <tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--muted)">Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- MOBILE BOTTOM NAV -->
<div class="mobile-nav" id="mobile-nav">
  <div class="mobile-nav-inner">
    <button class="mnav-btn active" id="mnav-dashboard" onclick="showPage('dashboard')">
      <span class="mnav-icon">📊</span>Dashboard
    </button>
    <button class="mnav-btn" id="mnav-menu" onclick="showPage('menu')">
      <span class="mnav-icon">🍜</span>Menu
    </button>
    <button class="mnav-btn" id="mnav-orders" onclick="showPage('orders')">
      <span class="mnav-icon">📋</span>Orders
    </button>
    <button class="mnav-btn" id="mnav-tables" onclick="showPage('tables')">
      <span class="mnav-icon">🍽️</span>Tables
    </button>
    <button class="mnav-btn" id="mnav-reserve" onclick="showPage('reserve')">
      <span class="mnav-icon">📅</span>Reserve
    </button>
    <button class="mnav-btn" id="mnav-stock" onclick="showPage('stock')">
      <span class="mnav-icon">📦</span>Stock
    </button>
    <button class="mnav-btn" id="mnav-crm" onclick="showPage('crm')">
      <span class="mnav-icon">👥</span>CRM
    </button>
    <button class="mnav-btn" id="mnav-shift" onclick="showPage('shift')">
      <span class="mnav-icon">🕐</span>Shifts
    </button>
    <button class="mnav-btn" id="mnav-delivery" onclick="showPage('delivery')">
      <span class="mnav-icon">🛵</span>Delivery
    </button>
    <button class="mnav-btn" id="mnav-branches" onclick="showPage('branches')">
      <span class="mnav-icon">🏢</span>Branches
    </button>
    <button class="mnav-btn" id="mnav-settings" onclick="showPage('settings')">
      <span class="mnav-icon">⚙️</span>Settings
    </button>
    <button class="mnav-btn" onclick="doLogout()">
      <span class="mnav-icon">↩</span>Logout
    </button>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>

// ── CSRF Token ──
const CSRF_TOKEN = '<?= $csrfToken ?>';

// ── Branch Selector ──
let currentBranchId = 0; // 0 = all branches
(function loadBranchSelector() {
  fetch('branch_api.php?action=list').then(r=>r.json()).then(d=>{
    if (!d.ok) return;
    const selectors = ['branch-select','branch-select-ops'].map(id=>document.getElementById(id)).filter(Boolean);
    if(!selectors.length) return;
    selectors.forEach(sel => {
      while(sel.options.length > 1) sel.remove(1);
      d.branches.forEach(b => {
        const opt = document.createElement('option');
        opt.value = b.id;
        opt.textContent = (b.is_active?'🏢 ':'🔴 ') + b.name;
        opt.dataset.tenant = b.tenant_id || 1;
        opt.style.background = '#2a1f14';
        opt.style.color = '#fff';
        sel.appendChild(opt);
      });
    });
  }).catch(()=>{});
})();

function toggleNavSection(id) {
  const body = document.getElementById('section-' + id);
  const chev = document.getElementById('chev-' + id);
  if (!body) return;
  const isOpen = body.style.display !== 'none';
  body.style.display = isOpen ? 'none' : '';
  if (chev) chev.textContent = isOpen ? '›' : '▾';
}

function switchBranch(id) {
  currentBranchId = parseInt(id) || 0;
  window._currentBranch = currentBranchId;
  // Get tenant_id from option data
  const sel = document.getElementById('branch-select');
  const opt = sel?.querySelector('option[value="'+id+'"]');
  window._currentTenant = parseInt(opt?.dataset?.tenant || 0);
  // Reload orders
  ['branch-select','branch-select-ops'].forEach(sid=>{const s=document.getElementById(sid);if(s)s.value=id;});
  if(typeof loadOrders === 'function') loadOrders();
  if(typeof loadStats === 'function') loadStats();
  toast('🏢 ' + (currentBranchId ? (sel?.selectedOptions[0]?.textContent||'Branch') : 'All Branches'));
}

// ── KPay QR Upload ──────────────────────────────
async function uploadKpayQr(input) {
  if (!input.files[0]) return;
  const fd = new FormData();
  fd.append('img', input.files[0]);
  const btn = document.getElementById('kpay-qr-remove-btn');
  const preview = document.getElementById('kpay-qr-preview');
  const noImg = document.getElementById('kpay-qr-no-img');
  const status = document.getElementById('kpay-qr-status');
  if (status) status.textContent = 'Uploading...';
  try {
    const r = await fetch('admin.php?api=upload_kpay_qr', {method:'POST', body:fd});
    const d = await r.json();
    if (d.ok) {
      document.getElementById('kpay-qr-img').src = '/' + d.path + '?t=' + Date.now();
      if (preview) preview.style.display = 'block';
      if (btn) btn.style.display = 'inline-block';
      if (noImg) noImg.style.display = 'none';
      if (status) status.textContent = 'QR image saved';
    } else { alert('Upload failed: ' + d.msg); }
  } catch(e) { alert('Upload error: ' + e); }
}

async function removeKpayQr() {
  if (!confirm('KPay QR image ဖျက်မှာ သေချာပါသလား?')) return;
  const r = await fetch('site_settings.php?action=save', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({kpay_qr_image: ''})
  });
  const d = await r.json();
  if (d.ok) {
    document.getElementById('kpay-qr-img').src = '';
    document.getElementById('kpay-qr-preview').style.display = 'none';
    document.getElementById('kpay-qr-remove-btn').style.display = 'none';
    document.getElementById('kpay-qr-no-img').style.display = 'block';
    document.getElementById('kpay-qr-input').value = '';
  }
}

// ── KPay QR loadSettings populate ───────────────
function populateKpayQrPreview(s) {
  const kpayUrl = s.kpay_qr_image || '';
  const kpayImg = document.getElementById('kpay-qr-img');
  const kpayPreview = document.getElementById('kpay-qr-preview');
  const kpayRemoveBtn = document.getElementById('kpay-qr-remove-btn');
  const kpayNoImg = document.getElementById('kpay-qr-no-img');
  if (kpayImg && kpayUrl) {
    kpayImg.src = '/' + kpayUrl + '?t=' + Date.now();
    if (kpayPreview) kpayPreview.style.display = 'block';
    if (kpayRemoveBtn) kpayRemoveBtn.style.display = 'inline-block';
    if (kpayNoImg) kpayNoImg.style.display = 'none';
  } else {
    if (kpayNoImg) kpayNoImg.style.display = 'block';
    if (kpayPreview) kpayPreview.style.display = 'none';
  }
}

/* ═══════════════════════════════════════
   STATE
═══════════════════════════════════════ */
let allItems   = [];
let activeCat  = 'All';
let restockId  = null;
let toastTmr;

/* ═══════════════════════════════════════
   API HELPER
═══════════════════════════════════════ */
async function api(action, body = null) {
  const opts = { method: body ? 'POST' : 'GET', headers: {} };
  if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
  try {
    // Append branch/tenant filter if selected
  let apiUrl = 'admin.php?api=' + action;
  if(window._currentBranch > 0) apiUrl += '&branch_id=' + window._currentBranch;
  if(window._currentTenant > 0) apiUrl += '&tenant_id=' + window._currentTenant;
  const r = await fetch(apiUrl, opts);
    if (r.status === 401) { location.href = 'admin.php'; return { ok: false, msg: 'Session expired' }; }
    const ct = r.headers.get('content-type') || '';
    if (!ct.includes('application/json')) {
      const txt = await r.text();
      console.error('Non-JSON:', txt.slice(0,200));
      return { ok: false, msg: 'Server error — check PHP logs' };
    }
    return r.json();
  } catch(e) {
    console.error('api() error:', e);
    return { ok: false, msg: e.message };
  }
}

const fmt = n => '$' + Number(n).toLocaleString('en-US', {minimumFractionDigits:0, maximumFractionDigits:2});

/* ═══════════════════════════════════════
   LOGIN / LOGOUT
═══════════════════════════════════════ */
async function doLogin() {
  const user = document.getElementById('l-user')?.value.trim();
  const pass = document.getElementById('l-pass')?.value.trim();
  const err  = document.getElementById('l-err');
  const d = await api('login', { user, pass });
  if (d.ok) { location.reload(); }
  else { err.textContent = d.msg; err.style.display = 'block'; }
}

async function doLogout() {
  try { await api('logout'); } catch(e) { /* ignore */ }
  location.href = 'admin.php';
}

/* ═══════════════════════════════════════
   PAGE NAV
═══════════════════════════════════════ */
function showPage(page) {
  ['dashboard','menu','orders','tables','settings','crm','shift','stock','reserve','branches','delivery','stocklog','staff','promos','expenses','schedule','storefront','saas'].forEach(p => {
    document.getElementById('page-'+p).style.display   = p===page ? '' : 'none';
    document.getElementById('nav-'+p).classList.toggle('active', p===page);
    // Mobile bottom nav sync
    const mb = document.getElementById('mnav-'+p);
    if (mb) mb.classList.toggle('active', p===page);
  });
  if (page==='dashboard') { loadStats(); loadOrders(); loadAnalytics(7); loadHealthCheck(); }
  if (page==='stocklog')  { loadStockLogs(); }
  if (page==='staff')      { loadStaff(); }
  if (page==='promos')     { promoLoad(); }
  if (page==='expenses')   { if(typeof expLoad==='function') expLoad(); }
  if (page==='schedule')   { if(typeof schedLoad==='function') schedLoad(); }
  if (page==='menu')      { loadMenuItems(); }
  if (page==='orders')    { loadOrders(); }
  if (page==='settings')  { loadSettings(); }
  if (page==='storefront') { loadSettings(); }
  if (page==='tables')    { loadTables(); }
  if (page==='crm')       { crmLoadCustomers(); }
  if (page==='shift')      { shiftLoad(); }
  if (page==='stock')      { stockLoad(); }
  if (page==='reserve')    { resLoad(); }
  if (page==='branches')   { branchLoad(); }
  if (page==='delivery')   { delLoad(); }
  if (page==='promos')     { promoLoad(); }
  if (page==='expenses')   { if(typeof expLoad==='function') expLoad(); }
  if (page==='schedule')   { if(typeof schedLoad==='function') schedLoad(); }
  if (page==='expenses')   { expLoad(); }
  if (page==='schedule')   { schedLoad(); }
  if (page==='saas')       { saasLoad(); }
  if (page==='saas')       { saasLoad(); }
  // Close sidebar on mobile after nav
  closeSidebar();
  // Scroll to top
  document.querySelector('.main')?.scrollTo(0,0);
}

/* ── Sidebar open/close (tablet/mobile) ── */
function openSidebar() {
  const sb  = document.getElementById('sidebar');
  const ov  = document.getElementById('sidebar-overlay');
  const cl  = document.getElementById('sidebar-close-btn');
  if (sb) sb.classList.add('open');
  if (ov) ov.classList.add('open');
  if (cl) cl.style.display = 'block';
  document.body.style.overflow = 'hidden';
}
function closeSidebar() {
  const sb  = document.getElementById('sidebar');
  const ov  = document.getElementById('sidebar-overlay');
  const cl  = document.getElementById('sidebar-close-btn');
  if (sb) sb.classList.remove('open');
  if (ov) ov.classList.remove('open');
  if (cl) cl.style.display = 'none';
  document.body.style.overflow = '';
}

/* ═══════════════════════════════════════
   DASHBOARD
═══════════════════════════════════════ */
async function filterPending() {
  showPage('orders');
  setTimeout(() => {
    document.querySelectorAll('#orders-table tr').forEach(row => {
      row.style.background = row.textContent.includes('pending') ? '#fff3cd' : '';
    });
  }, 600);
}


// ══ ANALYTICS ══
let _charts = {};
function fmtK(v){v=parseFloat(v);if(v>=1000000)return(v/1000000).toFixed(1)+'M';if(v>=1000)return(v/1000).toFixed(1)+'K';return v.toLocaleString();}
function destroyChart(id){if(_charts[id]){_charts[id].destroy();delete _charts[id];}}



// ══ LOYALTY CARD EDIT ══
function openLoyaltyEdit(phone, stamps, redeemed) {
  document.getElementById('loy-edit-phone-orig').value = phone;
  document.getElementById('loy-edit-phone').value = phone;
  document.getElementById('loy-edit-stamps').value = stamps;
  document.getElementById('loy-edit-redeemed').value = redeemed;
  document.getElementById('loy-edit-modal').style.display = 'flex';
}

async function saveLoyaltyEdit() {
  const origPhone = document.getElementById('loy-edit-phone-orig').value;
  const phone    = document.getElementById('loy-edit-phone').value.trim();
  const stamps   = parseInt(document.getElementById('loy-edit-stamps').value) || 0;
  const redeemed = parseInt(document.getElementById('loy-edit-redeemed').value) || 0;
  if (!phone) { toast('Phone ထည့်ပါ','err'); return; }
  const r = await fetch('loyalty_admin.php?action=update', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({orig_phone: origPhone, phone, stamps, total_redeemed: redeemed})
  });
  const d = await r.json();
  if (d.ok) {
    toast('✅ Saved');
    document.getElementById('loy-edit-modal').style.display = 'none';
    loadLoyaltyCards();
  } else { toast(d.msg || 'Error','err'); }
}

async function deleteLoyaltyCard() {
  const phone = document.getElementById('loy-edit-phone-orig').value;
  if (!confirm('Loyalty card (' + phone + ') ဖျက်မှာ သေချာပါသလား?')) return;
  const r = await fetch('loyalty_admin.php?action=delete', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({phone})
  });
  const d = await r.json();
  if (d.ok) {
    toast('🗑 Deleted');
    document.getElementById('loy-edit-modal').style.display = 'none';
    loadLoyaltyCards();
  } else { toast(d.msg || 'Error','err'); }
}

// ══ BULK DELETE ORDERS ══
function openBulkDelete() {
  document.getElementById('bulk-preview').innerHTML = '';
  document.getElementById('bulk-delete-modal').style.display = 'flex';
}

async function previewBulkDelete() {
  const phone  = document.getElementById('bulk-phone').value.trim();
  const from   = document.getElementById('bulk-date-from').value;
  const to     = document.getElementById('bulk-date-to').value;
  let url = 'loyalty_admin.php?action=preview_orders';
  if (phone) url += '&phone=' + encodeURIComponent(phone);
  if (from)  url += '&from=' + from;
  if (to)    url += '&to=' + to;
  const d = await fetch(url).then(r=>r.json());
  const el = document.getElementById('bulk-preview');
  if (d.ok) {
    el.innerHTML = `<div style="background:#fff3cd;border-radius:6px;padding:.5rem .75rem;color:#856404">⚠️ ${d.count} orders found — delete မည်</div>`;
  } else { el.innerHTML = '<div style="color:red">' + d.msg + '</div>'; }
}

async function confirmBulkDelete() {
  const phone  = document.getElementById('bulk-phone').value.trim();
  const from   = document.getElementById('bulk-date-from').value;
  const to     = document.getElementById('bulk-date-to').value;
  const reason = document.getElementById('bulk-reason').value.trim() || 'Bulk delete by admin';
  if (!confirm('Orders ဖျက်မှာ သေချာပါသလား? ပြန်မရနိုင်ပါ')) return;
  const r = await fetch('loyalty_admin.php?action=bulk_delete_orders', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({phone, from, to, reason})
  });
  const d = await r.json();
  if (d.ok) {
    toast('🗑 ' + d.deleted + ' orders deleted');
    document.getElementById('bulk-delete-modal').style.display = 'none';
    loadOrders(); loadStats();
  } else { toast(d.msg || 'Error','err'); }
}

// ══ KDS CLEAR ══
async function openKDSClear() {
  const d = await fetch('loyalty_admin.php?action=kds_pending').then(r=>r.json());
  document.getElementById('kds-pending-count').innerHTML =
    d.ok ? `<span style="color:#e84c2b">${d.count} pending tickets</span>` : 'Error';
  document.getElementById('kds-clear-modal').style.display = 'flex';
}

async function clearKDSQueue() {
  const r = await fetch('loyalty_admin.php?action=kds_clear', {method:'POST'});
  const d = await r.json();
  if (d.ok) {
    toast('🧹 KDS queue cleared (' + d.cleared + ' tickets)');
    document.getElementById('kds-clear-modal').style.display = 'none';
    loadStats();
  } else { toast(d.msg || 'Error','err'); }
}


// ══ SPLIT BILL ══
let _splitOrderId = null;
let _splitTotal = 0;

function openSplitBill(orderId, total) {
  _splitOrderId = orderId;
  _splitTotal = total;
  document.getElementById('split-order-info').textContent = 
    'Order #' + String(orderId).padStart(6,'0') + ' — Total: ' + parseInt(total).toLocaleString() + ' Ks';
  document.getElementById('split-amount-row').style.display = 'none';
  document.getElementById('split-secondary').value = '';
  document.getElementById('split-amount').value = '';
  document.getElementById('split-bill-modal').style.display = 'flex';
}

document.addEventListener('change', function(e) {
  if (e.target.id === 'split-secondary') {
    document.getElementById('split-amount-row').style.display = e.target.value ? 'block' : 'none';
    if (e.target.value) {
      document.getElementById('split-amount').placeholder = 
        'e.g. ' + Math.round(_splitTotal / 2).toLocaleString();
    }
  }
});

async function confirmSplitBill() {
  const primary  = document.getElementById('split-primary').value;
  const secondary = document.getElementById('split-secondary').value;
  const amount   = parseFloat(document.getElementById('split-amount').value) || 0;

  if (secondary && amount <= 0) {
    toast('Secondary payment amount ထည့်ပါ', 'err'); return;
  }
  if (secondary && amount > _splitTotal) {
    toast('Amount သည် total ထက် မကျော်ရ', 'err'); return;
  }

  const r = await fetch('table_api.php?action=close_table', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({
      order_id: _splitOrderId,
      payment_method: primary,
      split_method: secondary,
      split_amount: amount,
    })
  });
  const d = await r.json();
  if (d.ok) {
    toast('✅ Table closed · ' + (secondary ? primary+'+'+secondary : primary));
    document.getElementById('split-bill-modal').style.display = 'none';
    loadTables(); loadOrders(); loadStats();
  } else { toast(d.msg || 'Error', 'err'); }
}


// ══ STAFF MANAGEMENT ══
async function loadStaffList() {
  const d = await fetch('waiter_api.php?action=staff_list').then(r=>r.json());
  const el = document.getElementById('staff-list-admin');
  if (!el) return;
  if (!d.ok || !d.staff?.length) { el.innerHTML='<div style="color:var(--muted);font-size:.85rem">Staff မရှိသေး</div>'; return; }
  el.innerHTML = `<table style="width:100%;font-size:.82rem;border-collapse:collapse">
    <tr style="color:var(--muted);font-size:.75rem"><th style="text-align:left;padding:.25rem 0">Name</th><th>PIN</th><th>Role</th><th>Status</th><th></th></tr>
    ${d.staff.map(s=>`<tr style="border-top:.5px solid var(--border)">
      <td style="padding:.35rem 0">${s.name}</td>
      <td style="text-align:center">${s.pin}</td>
      <td style="text-align:center">${s.role}</td>
      <td style="text-align:center"><span style="color:${s.is_active?'#3b6d11':'#a32d2d'}">${s.is_active?'Active':'Inactive'}</span></td>
      <td style="text-align:right">
        <button onclick="toggleStaff(${s.id},${s.is_active})" style="font-size:.72rem;padding:2px 8px;border:1px solid var(--border);border-radius:4px;cursor:pointer;background:none">${s.is_active?'Deactivate':'Activate'}</button>
        <button onclick="deleteStaff(${s.id},'${s.name}')" style="font-size:.72rem;padding:2px 8px;border:1px solid #dc3545;border-radius:4px;cursor:pointer;background:none;color:#dc3545;margin-left:4px">Delete</button>
      </td>
    </tr>`).join('')}
  </table>`;
}

async function addStaff() {
  const name = document.getElementById('new-staff-name')?.value.trim();
  const pin  = document.getElementById('new-staff-pin')?.value.trim();
  if (!name || !pin) { toast('Name + PIN ထည့်ပါ','err'); return; }
  if (!/^\d{4,6}$/.test(pin)) { toast('PIN 4-6 digits ဖြစ်ရမည်','err'); return; }
  const r = await fetch('waiter_api.php?action=add_staff',{
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({name, pin, role:'waiter'})
  }).then(r=>r.json());
  if (r.ok) {
    toast('✅ '+name+' added');
    document.getElementById('new-staff-name').value='';
    document.getElementById('new-staff-pin').value='';
    loadStaffList();
  } else { toast(r.msg||'Error','err'); }
}

async function toggleStaff(id, isActive) {
  const r = await fetch('waiter_api.php?action=toggle_staff',{
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({id, is_active: isActive ? 0 : 1})
  }).then(r=>r.json());
  if (r.ok) { toast('✅ Staff updated'); loadStaffList(); }
  else { toast(r.msg||'Error','err'); }
}

async function deleteStaff(id, name) {
  if (!confirm(name+' ကို delete မှာ သေချာလား?')) return;
  const r = await fetch('waiter_api.php?action=delete_staff',{
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({id})
  }).then(r=>r.json());
  if (r.ok) { toast('🗑 Deleted'); loadStaffList(); }
  else { toast(r.msg||'Error','err'); }
}

async function loadLoyaltyCards() {
  const r = await fetch('loyalty.php?action=admin_list');
  const d = await r.json();
  const el = document.getElementById('loyalty-cards-list');
  if (!el) return;
  if (!d.ok || !d.cards.length) { el.innerHTML = '<div style="color:var(--muted)">Stamp cards မရှိသေး</div>'; return; }
  el.innerHTML = '<table style="width:100%;border-collapse:collapse;font-size:.8rem">' +
    '<thead><tr style="border-bottom:1px solid #eee"><th style="text-align:left;padding:.3rem">Phone</th><th>Stamps</th><th>Redeemed</th><th>Last order</th></tr></thead>' +
    '<tbody>' + d.cards.map(c => `<tr style="border-bottom:.5px solid #f0f0f0">
      <td style="padding:.35rem 0">${c.phone}</td>
      <td style="text-align:center">⭐ ${c.stamps}</td>
      <td style="text-align:center">🎁 ${c.total_redeemed}</td>
      <td style="text-align:center;color:#999">#${c.last_order_id||'—'}</td>
      <td><button onclick="openLoyaltyEdit('${c.phone}',${c.stamps},${c.total_redeemed})" style="padding:2px 8px;font-size:.75rem;background:none;border:1px solid #ddd;border-radius:4px;cursor:pointer">✏️</button></td>
    </tr>`).join('') + '</tbody></table>';
}


function printReceipt(orderId) {
  const w = window.open('receipt.php?id=' + orderId, '_blank', 'width=420,height=650,scrollbars=yes');
  if (!w) alert('Popup blocked — please allow popups for this site');
}


function openDailyReport() {
  const today = new Date().toISOString().split('T')[0];
  window.open('daily_report.php?date='+today, '_blank', 'width=900,height=700,scrollbars=yes');
}

function openCustHistory() {
  document.getElementById('cust-history-modal').style.display = 'flex';
  document.getElementById('cust-history-result').innerHTML = '';
  document.getElementById('cust-phone-input').focus();
}

async function loadCustHistory() {
  const phone = document.getElementById('cust-phone-input').value.trim();
  if (!phone) { toast('Phone number ထည့်ပါ','err'); return; }
  const el = document.getElementById('cust-history-result');
  el.innerHTML = '<div style="color:var(--muted);text-align:center;padding:1rem">Loading...</div>';
  const r = await fetch('customer_history.php?phone=' + encodeURIComponent(phone));
  const d = await r.json();
  if (!d.ok) { el.innerHTML = '<div style="color:red">Error: '+d.msg+'</div>'; return; }

  const s = d.stats;
  const loy = d.loyalty;
  el.innerHTML = `
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:1rem">
      <div style="background:#fdf6f0;border-radius:8px;padding:.6rem;text-align:center">
        <div style="font-size:1.2rem;font-weight:700;color:#e84c2b">${s.total_orders}</div>
        <div style="font-size:.75rem;color:#888">Orders</div>
      </div>
      <div style="background:#fdf6f0;border-radius:8px;padding:.6rem;text-align:center">
        <div style="font-size:1.1rem;font-weight:700;color:#e84c2b">K${parseInt(s.total_spent).toLocaleString()}</div>
        <div style="font-size:.75rem;color:#888">Total Spent</div>
      </div>
      <div style="background:#fdf6f0;border-radius:8px;padding:.6rem;text-align:center">
        <div style="font-size:1.1rem;font-weight:700;color:#f0a500">⭐ ${loy.stamps}</div>
        <div style="font-size:.75rem;color:#888">Stamps</div>
      </div>
    </div>
    ${d.orders.length === 0 ? '<div style="text-align:center;color:var(--muted);padding:1rem">Order မရှိသေး</div>' :
      '<div style="font-size:.82rem;font-weight:600;margin-bottom:.5rem">Order History</div>' +
      d.orders.map(o => `
        <div style="border:0.5px solid #eee;border-radius:8px;padding:.6rem .8rem;margin-bottom:.4rem">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <span style="font-weight:600;font-size:.85rem">#${String(o.id).padStart(6,'0')}</span>
            <span style="font-size:.78rem;color:#888">${o.created_at.substring(0,16)}</span>
          </div>
          <div style="font-size:.78rem;color:#555;margin:.2rem 0">${o.items_summary||'—'}</div>
          <div style="display:flex;justify-content:space-between">
            <span style="font-size:.8rem;color:#888">${o.payment_method?.toUpperCase()||''} · ${o.order_type==='dine_in'?'Dine-in':'Delivery'}</span>
            <span style="font-weight:600;font-size:.85rem;color:#e84c2b">K${parseInt(o.total_amount).toLocaleString()}</span>
          </div>
        </div>
      `).join('')
    }
  `;
}

async function loadAnalytics(days=7){
  // Empty state helper
  const setEmpty = (id, msg='No data yet') => {
    const el = document.getElementById(id);
    if(el) el.innerHTML = `<div style="text-align:center;padding:2rem;color:var(--muted);font-size:.85rem">📊 ${msg}</div>`;
  };
  [7,14,30].forEach(d=>{
    const b=document.getElementById('abtn-'+d);
    if(b){b.style.background=d===days?'var(--accent)':'';b.style.color=d===days?'#fff':'';}
  });
  let aUrl='analytics.php?days='+days;
  if(window._currentBranch>0) aUrl+='&branch_id='+window._currentBranch;
  if(window._currentTenant>0) aUrl+='&tenant_id='+window._currentTenant;
  const r=await fetch(aUrl);
  const d=await r.json();
  if(!d.ok)return;

  document.getElementById('an-total-orders').textContent=d.summary.total_orders;
  document.getElementById('an-total-rev').textContent='K '+fmtK(d.summary.total_revenue);
  document.getElementById('an-avg-order').textContent='K '+fmtK(d.summary.avg_order);

  const ac='#e84c2b', ac2='#f0a500';

  destroyChart('revenue');
  const rC=document.getElementById('chart-revenue')?.getContext('2d');
  if(rC) _charts['revenue']=new Chart(rC,{type:'bar',data:{labels:d.revenue.map(r=>r.date),datasets:[{label:'Revenue',data:d.revenue.map(r=>r.revenue),backgroundColor:ac+'99',borderColor:ac,borderWidth:1,borderRadius:4},{label:'Orders',data:d.revenue.map(r=>r.orders),type:'line',borderColor:ac2,backgroundColor:'transparent',pointBackgroundColor:ac2,pointRadius:3,tension:0.4,yAxisID:'y2'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{font:{size:10},color:'#888'}}},scales:{x:{ticks:{font:{size:9},color:'#888'},grid:{display:false}},y:{ticks:{font:{size:9},color:'#888',callback:v=>'K'+fmtK(v)},grid:{color:'#f0f0f0'}},y2:{position:'right',ticks:{font:{size:9},color:ac2},grid:{display:false}}}}});

  destroyChart('items');
  const iC=document.getElementById('chart-items')?.getContext('2d');
  if(iC&&d.items.length) _charts['items']=new Chart(iC,{type:'bar',data:{labels:d.items.map(i=>i.item_name.length>14?i.item_name.substring(0,14)+'…':i.item_name),datasets:[{label:'Qty',data:d.items.map(i=>i.qty),backgroundColor:['#e84c2bcc','#f0a500cc','#28a745cc','#17a2b8cc','#6f42c1cc','#fd7e14cc','#20c997cc','#e83e8ccc'],borderRadius:4}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{ticks:{font:{size:9},color:'#888'},grid:{color:'#f0f0f0'}},y:{ticks:{font:{size:9},color:'#555'},grid:{display:false}}}}});

  destroyChart('payments');
  const pC=document.getElementById('chart-payments')?.getContext('2d');
  if(pC&&d.payments.length){
    const pc={kpay:'#9b59b6',wave:'#3498db',cb:'#e74c3c',aya:'#2ecc71',cod:'#95a5a6',card:'#f39c12'};
    _charts['payments']=new Chart(pC,{type:'doughnut',data:{labels:d.payments.map(p=>p.payment_method.toUpperCase()),datasets:[{data:d.payments.map(p=>p.cnt),backgroundColor:d.payments.map(p=>pc[p.payment_method]||'#bbb'),borderWidth:2,borderColor:'#fff'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{font:{size:9},color:'#555',padding:8}},tooltip:{callbacks:{label:ctx=>` ${ctx.label}: ${ctx.raw} orders`}}}}});
  }

  destroyChart('hourly');
  const hC=document.getElementById('chart-hourly')?.getContext('2d');
  if(hC){
    const mx=Math.max(...d.hourly.map(h=>h.count),1);
    _charts['hourly']=new Chart(hC,{type:'bar',data:{labels:d.hourly.map(h=>h.hour%6===0?h.hour+'h':''),datasets:[{label:'Orders',data:d.hourly.map(h=>h.count),backgroundColor:d.hourly.map(h=>{const r=h.count/mx;return r>0.7?'#e84c2b':r>0.3?'#f0a500':'#ddd';}),borderRadius:2}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{ticks:{font:{size:8},color:'#888'},grid:{display:false}},y:{ticks:{font:{size:8},color:'#888',stepSize:1},grid:{color:'#f0f0f0'}}}}});
  }
}
// ══ END ANALYTICS ══


// ── Session timeout + CSRF handler ──
const _origFetch = window.fetch;
window.fetch = async function(...args) {
  // Inject CSRF token into POST requests
  if (args[1]?.method?.toUpperCase() === 'POST') {
    args[1].headers = args[1].headers || {};
    if (typeof args[1].headers === 'object' && !(args[1].headers instanceof Headers)) {
      args[1].headers['X-CSRF-Token'] = CSRF_TOKEN;
    }
  }
  const res = await _origFetch.apply(this, args);
  if (res.status === 401) {
    const clone = res.clone();
    try {
      const d = await clone.json();
      if (d.msg === 'Not logged in' || d.msg === 'Session expired' || d.msg === 'Unauthorized') {
        if (!document.getElementById('session-expired-banner')) {
          const banner = document.createElement('div');
          banner.id = 'session-expired-banner';
          banner.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;background:#dc3545;color:#fff;text-align:center;padding:.75rem;font-size:.9rem;font-weight:500';
          banner.innerHTML = '⚠️ Session expired — <a href="admin.php" style="color:#fff;text-decoration:underline">Click here to login again</a>';
          document.body.prepend(banner);
          setTimeout(()=>{ window.location.href = 'admin.php'; }, 3000);
        }
      }
    } catch(e) {}
  }
  return res;
};


// ══ LOAD MENU (admin reference) ══
async function loadMenu() {
  const d = await fetch('menu_api.php').then(r=>r.json());
  return d.items || [];
}

async function loadStats() {
  document.getElementById('dash-date').textContent =
    new Date().toLocaleDateString('en-GB', {weekday:'long',day:'numeric',month:'long'});
  const d = await api('stats');
  if (!d.ok) return;
  document.getElementById('s-orders').textContent  = d.today;
  document.getElementById('s-revenue').textContent = fmt(d.revenue);
  document.getElementById('s-low').textContent     = d.low;
  document.getElementById('s-pending').textContent = d.pending;
}

/* ═══════════════════════════════════════
   ORDERS
═══════════════════════════════════════ */
async function loadOrders() {
  const d = await api('orders');
  if (!d.ok) return;

  const orderRow = (o, isDash) => {
    const ref  = 'NH-' + String(o.id).padStart(6,'0');
    const time = new Date(o.created_at).toLocaleString('en-GB',{hour:'2-digit',minute:'2-digit',day:'2-digit',month:'short'});
    const branchBadge = (window._currentBranch > 0) ? '' : `<span style="display:inline-block;background:var(--surface2);color:var(--muted);font-size:.68rem;padding:.1rem .45rem;border-radius:10px;margin-left:.3rem;font-weight:600">${o.branch_code||''}</span>`;
    return `<tr>
      <td><strong style="font-family:'DM Mono',monospace">${ref}</strong>${branchBadge}</td>
      <td>${o.customer_name}</td>
      ${isDash ? '' : `<td class="hide-mobile" style="font-size:.8rem">${o.customer_phone}</td>`}
      <td style="font-size:.78rem;color:var(--muted);max-width:160px">${o.items}</td>
      <td class="price-cell">${fmt(o.total_amount)}</td>
      <td style="text-transform:uppercase;font-size:.78rem">${o.payment_method}</td>
      <td>
        <span class="order-status os-${o.status}">${o.status}</span>
        ${o.status==='cancelled' && o.delete_reason ? `<div style="font-size:.7rem;color:#991b1b;margin-top:.2rem">📝 ${o.delete_reason}</div>` : ''}
      </td>
      <td style="font-size:.78rem;color:var(--muted);white-space:nowrap">${time}</td>
      <td style="display:flex;gap:4px">
        <button class="btn btn-ghost btn-sm" onclick="printReceipt(${o.id})" title="Print Receipt">🖨️</button>
        <button class="btn btn-danger btn-sm" onclick="openDelOrder(${o.id},'${ref}')">🗑</button>
      </td>
    </tr>`;
  };

  const rows    = d.orders.map(o => orderRow(o, false)).join('');
  const dashRows= d.orders.slice(0,10).map(o => orderRow(o, true)).join('');
  const empty8  = '<tr><td colspan="9" style="text-align:center;color:var(--muted);padding:2rem">No orders yet</td></tr>';
  const empty9  = '<tr><td colspan="8" style="text-align:center;color:var(--muted);padding:2rem">No orders yet</td></tr>';

  const ob = document.getElementById('orders-body');
  const db = document.getElementById('dash-orders-body');
  if (ob) ob.innerHTML = rows || empty8;
  if (db) db.innerHTML = dashRows || empty9;
}

/* ── DELETE ORDER ── */
const DEL_REASONS = [
  { label:'🧪 Test order', val:'Test order — စစ်ဆေးမှုအတွက်' },
  { label:'❌ မှားယွင်းထည့်', val:'မှားယွင်းရိုက်ထည့်မိသောကြောင့်' },
  { label:'📦 ပစ္စည်းမရှိ', val:'မှာယူသောပစ္စည်း stock မရှိ' },
  { label:'📞 Customer ပယ်ဖျက်', val:'Customer မှ cancel လုပ်ကြောင်းဆက်သွယ်' },
  { label:'🔁 Order ထပ်ခါ', val:'Customer မှ order ထပ်တူပေးပို့' },
  { label:'📍 Address မမှန်', val:'Delivery address မှားယွင်း' },
  { label:'⏱ Expired', val:'Order ကုန်ဆုံးချိန်ကျော်' },
  { label:'✏️ အခြား', val:'' },
];
let delOrderId = null;
let pickedReason = '';

function openDelOrder(id, ref) {
  delOrderId = id;
  pickedReason = '';
  document.getElementById('del-order-ref').textContent = ref;
  document.getElementById('del-remark').value = '';
  document.getElementById('reason-grid').innerHTML = DEL_REASONS.map((r,i) =>
    `<button class="reason-btn" onclick="pickReason(${i},'${r.val.replace(/'/g,"\\'")}',this)">${r.label}</button>`
  ).join('');
  document.getElementById('del-order-modal').classList.add('open');
}

function pickReason(idx, val, btn) {
  document.querySelectorAll('.reason-btn').forEach(b => b.classList.remove('picked'));
  btn.classList.add('picked');
  pickedReason = val;
  if (idx === DEL_REASONS.length-1) {
    document.getElementById('del-remark').focus();
  } else {
    document.getElementById('del-remark').value = val;
  }
}

function closeDelOrder() {
  document.getElementById('del-order-modal').classList.remove('open');
  delOrderId = null;
}

async function confirmDelOrder() {
  const remark = document.getElementById('del-remark').value.trim();
  const reason = remark || pickedReason;
  if (!reason) { toast('Reason ရွေးပါ သို့မဟုတ် ရိုက်ထည့်ပါ','err'); return; }
  const d = await api('delete_order', { id: delOrderId, reason });
  if (d.ok) {
    toast('Order deleted & archived ✓','ok');
    closeDelOrder();
    loadOrders();
    loadStats();
  } else {
    toast(d.msg || 'Error','err');
  }
}

/* ── DELETED ORDERS LOG ── */
async function showDeletedLog() {
  document.getElementById('deleted-log-modal').classList.add('open');
  const d = await api('deleted_orders');
  if (!d.ok) { toast('Load failed','err'); return; }
  const rows = d.orders.map(o => `<tr>
    <td><strong style="font-family:'DM Mono',monospace">${o.order_ref}</strong>
      <div style="font-size:.72rem;color:var(--muted)">${o.customer_name} · ${o.customer_phone}</div></td>
    <td>${o.customer_name}</td>
    <td class="price-cell">${fmt(o.total_amount)}</td>
    <td style="font-size:.8rem;max-width:180px"><span class="del-badge">🗑</span> ${o.delete_reason}</td>
    <td style="font-size:.78rem;color:var(--muted);white-space:nowrap">
      ${new Date(o.deleted_at).toLocaleString('en-GB',{hour:'2-digit',minute:'2-digit',day:'2-digit',month:'short'})}
    </td>
  </tr>`).join('');
  document.getElementById('deleted-log-body').innerHTML =
    rows || '<tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--muted)">Deleted records မရှိသေးပါ</td></tr>';
}

/* ── IMAGE UPLOAD ── */
let currentEditId = null;

function previewImg(input) {
  if (!input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    const prev = document.getElementById('img-new-preview');
    prev.src = e.target.result;
    prev.style.display = 'block';
    document.getElementById('img-upload-label').style.display = 'none';
    document.getElementById('img-upload-btn').style.display = 'inline-flex';
  };
  reader.readAsDataURL(input.files[0]);
}

async function uploadImg() {
  const file = document.getElementById('img-file-input').files[0];
  if (!file || !currentEditId) { toast('File ရွေးပါ','err'); return; }
  const btn = document.getElementById('img-upload-btn');
  btn.disabled = true; btn.textContent = 'Uploading…';

  const fd = new FormData();
  fd.append('img', file);
  fd.append('item_id', currentEditId);

  try {
    const r = await fetch('admin.php?api=upload_image', { method:'POST', body: fd });
    const d = await r.json();
    if (d.ok) {
      toast('ဓာတ်ပုံ upload ပြီး ✓','ok');
      // Update preview
      document.getElementById('img-current-preview').src = d.path + '?t=' + Date.now();
      document.getElementById('img-current-preview').style.display = 'block';
      document.getElementById('img-remove-btn').style.display = 'inline-flex';
      loadMenuItems();
    } else {
      toast(d.msg || 'Upload failed','err');
    }
  } catch(e) {
    toast('Upload error: ' + e.message,'err');
  }
  btn.disabled = false; btn.textContent = '↑ Upload';
}

async function removeImg() {
  if (!currentEditId) return;
  if (!confirm('ဓာတ်ပုံ ဖျက်မည်သေချာပါသလား?')) return;
  const d = await api('remove_image', {id: currentEditId});
  if (d.ok) {
    toast('ဓာတ်ပုံ ဖျက်ပြီ','ok');
    document.getElementById('img-current-preview').style.display = 'none';
    document.getElementById('img-remove-btn').style.display = 'none';
    document.getElementById('img-new-preview').style.display = 'none';
    document.getElementById('img-upload-label').style.display = 'block';
    document.getElementById('img-file-input').value = '';
    loadMenuItems();
  }
}

/* ═══════════════════════════════════════
   MENU ITEMS
═══════════════════════════════════════ */
async function loadMenuItems() {
  const d = await api('items');
  if (!d.ok) return;
  allItems = d.items;
  buildCatTabs();
  renderMenuTable();
}

function buildCatTabs() {
  const cats = ['All', ...new Set(allItems.map(i => i.category))];
  document.getElementById('cat-tabs').innerHTML = cats.map(c =>
    `<div class="cat-tab${c===activeCat?' on':''}" onclick="setCat('${c}')">${c}</div>`
  ).join('');
}

function setCat(c) {
  activeCat = c; buildCatTabs(); renderMenuTable();
}

function renderMenuTable() {
  const q = document.getElementById('menu-search')?.value.toLowerCase() || '';
  const filtered = allItems.filter(i =>
    (activeCat==='All' || i.category===activeCat) &&
    (!q || i.name.toLowerCase().includes(q) || i.category.toLowerCase().includes(q))
  );
  document.getElementById('menu-count').textContent = filtered.length + ' items';

  const stockPill = s => {
    if (s==0)  return `<span class="stock-pill stock-out">Out</span>`;
    if (s<=5)  return `<span class="stock-pill stock-low">${s} low</span>`;
    return             `<span class="stock-pill stock-ok">${s}</span>`;
  };

  const rows = filtered.map(i => `
    <tr data-id="${i.id}" draggable="true"
        ondragstart="onDragStart(event)" ondragover="onDragOver(event)"
        ondragleave="onDragLeave(event)" ondrop="onDrop(event)" ondragend="onDragEnd(event)">
      <td class="drag-handle" title="Drag to reorder">⠿</td>
      <td class="emoji-cell">
        ${i.image_path
          ? `<img src="${i.image_path}" style="width:38px;height:38px;object-fit:cover;border-radius:6px;border:1px solid var(--border)">`
          : (i.emoji||'🍽️')}
      </td>
      <td><strong>${i.name}</strong><div style="font-size:.75rem;color:var(--muted);margin-top:1px">${(i.description||'').slice(0,45)}${(i.description||'').length>45?'…':''}</div></td>
      <td><span style="font-size:.78rem;background:var(--warm);padding:.2rem .6rem;border-radius:50px">${i.category}</span></td>
      <td class="price-cell">${fmt(i.price)}</td>
      <td>${stockPill(i.stock_qty)}</td>
      <td><span class="active-dot ${i.is_active==1?'dot-on':'dot-off'}"></span> ${i.is_active==1?'Active':'Hidden'}</td>
      <td style="white-space:nowrap">
        <button class="btn btn-warn btn-sm" onclick="openRestock(${i.id},'${i.name.replace(/'/g,"\\'")}',${i.stock_qty})">↑ Stock</button>
        <button class="btn btn-ghost btn-sm" onclick="openEditModal(${i.id})" style="margin:0 3px">✎ Edit</button>
        <button class="btn btn-danger btn-sm" onclick="deleteItem(${i.id},'${i.name.replace(/'/g,"\\'")}')">✕</button>
      </td>
    </tr>`).join('');
  document.getElementById('menu-body').innerHTML = rows ||
    '<tr><td colspan="8" style="text-align:center;color:var(--muted);padding:2rem">No items found</td></tr>';
}

/* ═══════════════════════════════════════
   ADD / EDIT MODAL
═══════════════════════════════════════ */
function openAddModal() {
  document.getElementById('modal-title').textContent = 'Add Menu Item';
  document.getElementById('modal-save-btn').textContent = 'Add Item';
  document.getElementById('f-id').value    = '';
  document.getElementById('f-emoji').value = '';
  document.getElementById('f-name').value  = '';
  document.getElementById('f-cat').value   = 'Noodles';
  document.getElementById('f-desc').value  = '';
  document.getElementById('f-price').value = '';
  document.getElementById('f-stock').value = '';
  document.getElementById('active-row').style.display = 'none';
  document.getElementById('item-modal').classList.add('open');
}

function openEditModal(id) {
  const item = allItems.find(i => i.id == id);
  if (!item) return;
  currentEditId = id;
  document.getElementById('modal-title').textContent = 'Edit: ' + item.name;
  document.getElementById('modal-save-btn').textContent = 'Save Changes';
  document.getElementById('f-id').value       = item.id;
  document.getElementById('f-emoji').value    = item.emoji || '';
  document.getElementById('f-name').value     = item.name;
  document.getElementById('f-cat').value      = item.category;
  document.getElementById('f-desc').value     = item.description || '';
  document.getElementById('f-price').value    = item.price;
  document.getElementById('f-stock').value    = item.stock_qty;
  document.getElementById('f-active').checked = item.is_active == 1;
  document.getElementById('f-station').value  = item.station || 'kitchen';
  document.getElementById('active-row').style.display    = '';
  document.getElementById('img-upload-row').style.display = '';
  document.getElementById('modifier-btn').style.display  = '';

  // ဓာတ်ပုံ preview reset
  const cur  = document.getElementById('img-current-preview');
  const newP = document.getElementById('img-new-preview');
  const rmBtn= document.getElementById('img-remove-btn');
  const upBtn= document.getElementById('img-upload-btn');
  const lbl  = document.getElementById('img-upload-label');
  document.getElementById('img-file-input').value = '';
  newP.style.display = 'none';
  upBtn.style.display = 'none';
  lbl.style.display = 'block';
  if (item.image_path) {
    cur.src = item.image_path + '?t=' + Date.now();
    cur.style.display = 'block';
    rmBtn.style.display = 'inline-flex';
  } else {
    cur.style.display = 'none';
    rmBtn.style.display = 'none';
  }
  document.getElementById('item-modal').classList.add('open');
}

function closeModal() {
  document.getElementById('item-modal').classList.remove('open');
  document.getElementById('modifier-btn').style.display = 'none';
}

/* ══════════════════════════════════════
   MODIFIER MODAL JS
══════════════════════════════════════ */
let currentModItemId   = null;
let currentModItemName = '';
let currentGroupId     = null;

async function openModifierModal() {
  const id = document.getElementById('f-id').value;
  const name = document.getElementById('f-name').value;
  if (!id) return;
  currentModItemId   = id;
  currentModItemName = name;
  document.getElementById('mod-item-name').textContent = name;
  document.getElementById('modifier-modal').classList.add('open');
  document.getElementById('group-form').style.display = 'none';
  await loadModifierGroups();
}

function closeModifierModal() {
  document.getElementById('modifier-modal').classList.remove('open');
}

async function loadModifierGroups() {
  const r = await fetch(`admin.php?api=get_modifiers&item_id=${currentModItemId}`);
  const d = await r.json();
  const el = document.getElementById('modifier-groups-list');
  if (!d.ok || !d.groups.length) {
    el.innerHTML = '<p style="color:var(--muted);font-size:.82rem;text-align:center;padding:1rem">Modifier မရှိသေးပါ</p>';
    return;
  }
  el.innerHTML = d.groups.map(g => `
    <div class="modifier-group-card" style="border:1px solid var(--border);border-radius:10px;padding:.8rem;margin-bottom:.8rem;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem">
        <div>
          <strong style="font-size:.9rem">${g.name}</strong>
          <span style="font-size:.72rem;color:var(--muted);margin-left:.4rem">
            ${g.type === 'single' ? 'Single' : g.type === 'multi' ? 'Multi' : 'Text'}
            ${g.required ? ' · <span style="color:var(--danger)">Required</span>' : ''}
          </span>
        </div>
        <div style="display:flex;gap:.3rem">
          <button class="btn btn-ghost btn-sm" onclick="editGroupForm(${g.id},'${g.name.replace(/'/g,"\\'")}','${g.type}',${g.required})">Edit</button>
          <button class="btn btn-danger btn-sm" onclick="deleteModifierGroup(${g.id})">✕</button>
        </div>
      </div>
      ${g.type !== 'text' ? `
        <div style="margin-left:.5rem">
          ${g.options.map(o => `
            <div style="display:flex;align-items:center;justify-content:space-between;padding:.25rem .4rem;border-radius:6px;background:var(--surface);margin-bottom:.25rem">
              <span style="font-size:.82rem">
                ${o.is_default ? '✓ ' : ''}<strong>${o.label}</strong>
                ${o.price_add > 0 ? `<span style="color:var(--accent2);font-size:.75rem">+${o.price_add.toLocaleString()}ks</span>` : ''}
              </span>
              <div style="display:flex;gap:.25rem">
                <button class="btn btn-ghost btn-sm" style="padding:.15rem .4rem;font-size:.7rem"
                  onclick="openOptionModal(${g.id},'edit',${o.id},'${o.label.replace(/'/g,"\\'")}',${o.price_add},${o.is_default})">Edit</button>
                <button class="btn btn-danger btn-sm" style="padding:.15rem .4rem;font-size:.7rem"
                  onclick="deleteModifierOption(${o.id})">✕</button>
              </div>
            </div>
          `).join('')}
          <button class="btn btn-ghost btn-sm" style="width:100%;margin-top:.3rem"
            onclick="openOptionModal(${g.id},'add')">+ Add Option</button>
        </div>
      ` : `<p style="font-size:.78rem;color:var(--muted);margin-left:.5rem">Customer မှာ free text ရေးနိုင်မည်</p>`}
    </div>
  `).join('');
}

function openAddGroupForm() {
  currentGroupId = null;
  document.getElementById('gf-id').value      = '';
  document.getElementById('gf-name').value    = '';
  document.getElementById('gf-type').value    = 'single';
  document.getElementById('gf-required').checked = false;
  document.getElementById('group-form').style.display = 'block';
  document.getElementById('gf-name').focus();
}

function editGroupForm(id, name, type, required) {
  currentGroupId = id;
  document.getElementById('gf-id').value         = id;
  document.getElementById('gf-name').value       = name;
  document.getElementById('gf-type').value       = type;
  document.getElementById('gf-required').checked = !!required;
  document.getElementById('group-form').style.display = 'block';
  document.getElementById('gf-name').focus();
}

function cancelGroupForm() {
  document.getElementById('group-form').style.display = 'none';
}

async function saveModifierGroup() {
  const name     = document.getElementById('gf-name').value.trim();
  const type     = document.getElementById('gf-type').value;
  const required = document.getElementById('gf-required').checked ? 1 : 0;
  const id       = document.getElementById('gf-id').value;
  if (!name) { toast('Group name ထည့်ပါ', 'err'); return; }
  const body = { menu_item_id: parseInt(currentModItemId), name, type, required, sort_order: 0 };
  if (id) body.id = parseInt(id);
  const r = await fetch('admin.php?api=save_modifier_group', {
    method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)
  });
  const d = await r.json();
  if (d.ok) { toast('Group saved!', 'ok'); cancelGroupForm(); await loadModifierGroups(); }
  else { toast(d.msg || 'Error', 'err'); }
}

async function deleteModifierGroup(gid) {
  if (!confirm('Modifier group ကို ဖျက်မည်။ Options အကုန်ပါ ဖျက်မည်။ သေချာလား?')) return;
  const d = await (await fetch('admin.php?api=delete_modifier_group', {
    method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id: gid})
  })).json();
  if (d.ok) { toast('Deleted', 'ok'); await loadModifierGroups(); }
  else { toast(d.msg || 'Error', 'err'); }
}

function openOptionModal(groupId, mode, id='', label='', priceAdd=0, isDefault=0) {
  currentGroupId = groupId;
  document.getElementById('of-group-id').value = groupId;
  document.getElementById('of-id').value       = id;
  document.getElementById('of-label').value    = label;
  document.getElementById('of-price').value    = priceAdd;
  document.getElementById('of-default').checked= !!isDefault;
  document.getElementById('opt-modal-title').textContent = mode === 'add' ? 'Add Option' : 'Edit Option';
  document.getElementById('option-modal').classList.add('open');
  setTimeout(() => document.getElementById('of-label').focus(), 100);
}

function closeOptionModal() {
  document.getElementById('option-modal').classList.remove('open');
}

async function saveModifierOption() {
  const label     = document.getElementById('of-label').value.trim();
  const priceAdd  = parseInt(document.getElementById('of-price').value) || 0;
  const isDefault = document.getElementById('of-default').checked ? 1 : 0;
  const groupId   = parseInt(document.getElementById('of-group-id').value);
  const id        = document.getElementById('of-id').value;
  if (!label) { toast('Label ထည့်ပါ', 'err'); return; }
  const body = { group_id: groupId, label, price_add: priceAdd, is_default: isDefault, sort_order: 0 };
  if (id) body.id = parseInt(id);
  const d = await (await fetch('admin.php?api=save_modifier_option', {
    method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)
  })).json();
  if (d.ok) { toast('Saved!', 'ok'); closeOptionModal(); await loadModifierGroups(); }
  else { toast(d.msg || 'Error', 'err'); }
}

async function deleteModifierOption(oid) {
  const d = await (await fetch('admin.php?api=delete_modifier_option', {
    method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id: oid})
  })).json();
  if (d.ok) { toast('Deleted', 'ok'); await loadModifierGroups(); }
  else { toast(d.msg || 'Error', 'err'); }
}

async function saveItem() {
  const id    = document.getElementById('f-id').value;
  const name  = document.getElementById('f-name').value.trim();
  const price = document.getElementById('f-price').value;
  const stock = document.getElementById('f-stock').value;
  if (!name)  { toast('Name ထည့်ပါ', 'err'); return; }
  if (!price) { toast('Price ထည့်ပါ', 'err'); return; }
  if (stock==='') { toast('Stock ထည့်ပါ', 'err'); return; }

  const body = {
    name, price, stock,
    emoji:    document.getElementById('f-emoji').value.trim() || '🍽️',
    category: document.getElementById('f-cat').value,
    desc:     document.getElementById('f-desc').value.trim(),
    active:   document.getElementById('f-active').checked ? 1 : 0,
    station:  document.getElementById('f-station').value || 'kitchen',
  };

  const btn = document.getElementById('modal-save-btn');
  btn.disabled = true; btn.textContent = 'Saving…';

  let d;
  if (id) { body.id = id; d = await api('update', body); }
  else    { d = await api('add', body); }

  btn.disabled = false; btn.textContent = id ? 'Save Changes' : 'Add Item';
  if (d.ok) { toast(id ? 'Updated!' : 'Item added!', 'ok'); closeModal(); loadMenuItems(); }
  else       { toast(d.msg || 'Error', 'err'); }
}

async function deleteItem(id, name) {
  if (!confirm(`"${name}" ကိုဖျက်မည်။ သေချာပါသလား?`)) return;
  const d = await api('delete', {id});
  if (d.ok) { toast('Deleted', 'ok'); loadMenuItems(); }
  else       { toast(d.msg||'Error','err'); }
}

/* ═══════════════════════════════════════
   RESTOCK
═══════════════════════════════════════ */
function openRestock(id, name, current) {
  restockId = id;
  document.getElementById('restock-name').textContent    = name;
  document.getElementById('restock-current').textContent = current;
  document.getElementById('restock-qty').value           = '';
  document.getElementById('restock-modal').classList.add('open');
  setTimeout(() => document.getElementById('restock-qty').focus(), 200);
}
function closeRestock() {
  document.getElementById('restock-modal').classList.remove('open');
  restockId = null;
}
function setRestock(n) { document.getElementById('restock-qty').value = n; }
async function doRestock() {
  const qty = parseInt(document.getElementById('restock-qty').value);
  if (!qty || qty < 1) { toast('Qty ထည့်ပါ','err'); return; }
  const d = await api('restock', {id: restockId, qty});
  if (d.ok) { toast(`+${qty} added!`, 'ok'); closeRestock(); loadMenuItems(); }
  else       { toast(d.msg||'Error','err'); }
}

/* ═══════════════════════════════════════
   TOAST
═══════════════════════════════════════ */
function toast(msg, type='') {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.className = 'toast show ' + type;
  clearTimeout(toastTmr);
  toastTmr = setTimeout(() => el.classList.remove('show'), 2800);
}

/* ═══════════════════════════════════════
   BATCH UPLOAD
═══════════════════════════════════════ */
const CSV_TEMPLATE = `name,category,price,stock,emoji,description
မုန့်ဟင်းခါး,Noodles,4500,20,🍲,ငါးဆောက်ဟင်းချို မုန့်ဟင်းခါး
Shan Noodles,Noodles,4000,15,🍜,Light pork-broth rice noodles
Ramen Bowl,Noodles,6000,8,🍥,Japanese-style ramen with chashu pork
ကြက်သားကင်,Starters,4000,30,🍡,Grilled skewers with peanut sauce
Tom Yum Soup,Soups,4500,14,🫕,Hot and sour Thai soup with prawns
Taro Bubble Tea,Drinks,2500,40,🧋,Creamy taro milk tea with tapioca`;

// Category valid values: Noodles, Rice, Starters, Soups, Desserts, Drinks
// Myanmar aliases also accepted: ခေါက်ဆွဲ=Noodles, ထမင်း=Rice, ဟင်းချို=Soups, အချိုရည်=Drinks

let batchPreviewRows = [];

function downloadTemplate() {
  const blob = new Blob([CSV_TEMPLATE], {type:'text/csv;charset=utf-8;'});
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href = url; a.download = 'menu_template.csv';
  a.click(); URL.revokeObjectURL(url);
}

function openBatchModal() {
  resetBatch();
  document.getElementById('batch-modal').classList.add('open');
}

function closeBatchModal() {
  document.getElementById('batch-modal').classList.remove('open');
}

function resetBatch() {
  batchPreviewRows = [];
  document.getElementById('batch-step1').style.display = '';
  document.getElementById('batch-step2').style.display = 'none';
  document.getElementById('batch-file').value = '';
  document.getElementById('batch-modal-foot').innerHTML =
    '<button class="btn btn-ghost" onclick="closeBatchModal()">Cancel</button>';
}

async function handleBatchFile(file) {
  if (!file) return;
  if (!file.name.match(/\.(csv|txt)$/i)) {
    toast('CSV ဖိုင်သာ လက်ခံသည်','err'); return;
  }

  const fd = new FormData();
  fd.append('csv', file);
  fd.append('preview', '1');

  try {
    const r = await fetch('admin.php?api=batch_upload', {method:'POST', body:fd});
    const d = await r.json();

    if (!d.ok) { toast(d.msg||'Parse failed','err'); return; }

    batchPreviewRows = d.rows || [];
    renderBatchPreview(d.rows, d.errors);
  } catch(e) {
    toast('Error: '+e.message,'err');
  }
}

function renderBatchPreview(rows, errors) {
  // Switch to step 2
  document.getElementById('batch-step1').style.display = 'none';
  document.getElementById('batch-step2').style.display = '';

  // Summary
  const sumEl = document.getElementById('batch-summary');
  sumEl.innerHTML =
    `<span style="color:var(--green);font-weight:600">✓ ${rows.length} items ready</span>` +
    (errors?.length ? ` &nbsp; <span style="color:var(--accent)">⚠ ${errors.length} rows skipped</span>` : '');

  // Errors
  const errEl = document.getElementById('batch-errors');
  if (errors?.length) {
    errEl.style.display = 'block';
    errEl.innerHTML = errors.map(e =>
      `Row ${e.row}: ${e.msg}`).join('<br>');
  } else {
    errEl.style.display = 'none';
  }

  // Preview table
  const CATS = {Noodles:'#e8f4fd',Rice:'#f0faf0',Starters:'#fff9e6',
                Soups:'#fef0f0',Desserts:'#fdf0ff',Drinks:'#f0fff4'};
  document.getElementById('batch-preview-body').innerHTML = rows.map((r,i) => `
    <tr style="border-bottom:1px solid var(--border)">
      <td style="padding:.4rem .8rem;color:var(--muted)">${i+1}</td>
      <td style="padding:.4rem .8rem;font-weight:500">${r.name}</td>
      <td style="padding:.4rem .8rem">
        <span style="background:${CATS[r.cat]||'var(--warm)'};padding:.15rem .5rem;border-radius:50px;font-size:.75rem">${r.cat}</span>
      </td>
      <td style="padding:.4rem .8rem;text-align:right;font-family:'DM Mono',monospace">${fmt(r.price)}</td>
      <td style="padding:.4rem .8rem;text-align:right">${r.stock}</td>
      <td style="padding:.4rem .8rem;text-align:center;font-size:1.2rem">${r.emoji}</td>
      <td style="padding:.4rem .8rem;color:var(--muted);font-size:.78rem">${(r.desc||'').slice(0,40)}${(r.desc||'').length>40?'…':''}</td>
    </tr>`).join('');

  // Footer buttons
  document.getElementById('batch-modal-foot').innerHTML = `
    <button class="btn btn-ghost" onclick="resetBatch()">↺ ပြန်ရွေး</button>
    <button class="btn btn-ghost" onclick="closeBatchModal()">Cancel</button>
    <button class="btn btn-primary" onclick="confirmBatchUpload()" id="batch-confirm-btn">
      ✓ ${rows.length} items ထည့်မည်
    </button>`;
}

async function confirmBatchUpload() {
  const btn = document.getElementById('batch-confirm-btn');
  if (!btn) return;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Uploading…';

  const file = document.getElementById('batch-file').files[0];
  if (!file) { toast('File မရှိတော့ပါ — ပြန်ရွေးပါ','err'); resetBatch(); return; }

  const fd = new FormData();
  fd.append('csv', file);
  // preview မပါ = real insert

  try {
    const r = await fetch('admin.php?api=batch_upload', {method:'POST', body:fd});
    const d = await r.json();

    if (!d.ok) { toast(d.msg||'Upload failed','err'); btn.disabled=false; btn.textContent='Retry'; return; }

    toast(`✓ ${d.inserted} items ထည့်ပြီ${d.skipped?` (${d.skipped} ကျော်)`:''}`,'ok');
    closeBatchModal();
    loadMenuItems();
  } catch(e) {
    toast('Error: '+e.message,'err');
    btn.disabled=false; btn.textContent='Retry';
  }
}

/* ═══════════════════════════════════════
   DRAG & DROP SORT
═══════════════════════════════════════ */
let dragSrcRow = null;
let reorderTimer = null;

function onDragStart(e) {
  dragSrcRow = e.currentTarget;
  dragSrcRow.classList.add('dragging');
  e.dataTransfer.effectAllowed = 'move';
  e.dataTransfer.setData('text/plain', dragSrcRow.dataset.id);
}

function onDragOver(e) {
  e.preventDefault();
  e.dataTransfer.dropEffect = 'move';
  const row = e.currentTarget;
  if (row === dragSrcRow) return;
  document.querySelectorAll('#menu-body tr').forEach(r =>
    r.classList.remove('drop-above','drop-below'));
  const rect  = row.getBoundingClientRect();
  const midY  = rect.top + rect.height / 2;
  if (e.clientY < midY) row.classList.add('drop-above');
  else                   row.classList.add('drop-below');
}

function onDragLeave(e) {
  e.currentTarget.classList.remove('drop-above','drop-below');
}

function onDrop(e) {
  e.preventDefault();
  const target = e.currentTarget;
  if (!dragSrcRow || target === dragSrcRow) return;
  target.classList.remove('drop-above','drop-below');

  const tbody  = document.getElementById('menu-body');
  const isAbove = target.getBoundingClientRect().top +
                  target.getBoundingClientRect().height / 2 > e.clientY;
  if (isAbove) tbody.insertBefore(dragSrcRow, target);
  else         tbody.insertBefore(dragSrcRow, target.nextSibling);

  // Update allItems order to match DOM
  const newOrder = [...tbody.querySelectorAll('tr[data-id]')].map(r => parseInt(r.dataset.id));
  allItems.sort((a,b) => newOrder.indexOf(a.id) - newOrder.indexOf(b.id));

  // Debounce save
  clearTimeout(reorderTimer);
  reorderTimer = setTimeout(() => saveOrder(newOrder), 600);
}

function onDragEnd(e) {
  e.currentTarget.classList.remove('dragging');
  document.querySelectorAll('#menu-body tr').forEach(r =>
    r.classList.remove('drop-above','drop-below','drag-over'));
  dragSrcRow = null;
}

async function saveOrder(ids) {
  try {
    const d = await api('reorder', { ids });
    if (d.ok) toast('Order saved ✓','ok');
    else      toast(d.msg||'Save failed','err');
  } catch(e) { toast('Error: '+e.message,'err'); }
}

/* ═══════════════════════════════════════
   TABLES & QR CODES
═══════════════════════════════════════ */
const BASE_URL = window.location.origin + window.location.pathname.replace('admin.php','');

async function loadTables() {
  const r = await fetch('table_api.php?action=list');
  const d = await r.json();
  if (!d.ok) { toast('Tables load failed','err'); return; }
  renderTablesGrid(d.tables);
  renderQRGrid(d.tables);
}

function renderTablesGrid(tables) {
  const STATUS_COLOR = { open:'#d1fae5', billed:'#fef3c7', empty:'#f0f0f0' };
  const STATUS_LABEL = { open:'🟢 Open', billed:'🧾 Bill Requested', empty:'⬜ Empty' };
  document.getElementById('tables-grid').innerHTML = tables.map(t => {
    const hasOrder = !!t.order_id;
    const status = t.table_status || 'empty';
    const bg = STATUS_COLOR[status] || '#f0f0f0';
    return `<div style="background:${bg};border-radius:10px;padding:1rem;border:1px solid rgba(0,0,0,.08)">
      <div style="font-weight:700;font-size:1rem;margin-bottom:.3rem">${t.table_code}
        <span style="font-size:.75rem;font-weight:400;color:#666"> ${t.label}</span></div>
      <div style="font-size:.82rem;margin-bottom:.5rem">${STATUS_LABEL[status]||status}</div>
      ${hasOrder ? `
        <div style="font-size:.78rem;color:#555;margin-bottom:.6rem">
          ${t.item_count||0} items · ${fmt(t.subtotal||0)}
        </div>
        ${status!=='empty'?`
        <button class="btn btn-primary btn-sm" style="width:100%;margin-bottom:.3rem"
          onclick="openSplitBill(${t.order_id},${t.total_amount||0})">💳 Split & Close</button>
        `:''}
      ` : ''}
      <button class="btn btn-ghost btn-sm" style="width:100%;font-size:.72rem"
        onclick="resetTable('${t.table_code}')">↺ Reset Table</button>
    </div>`;
  }).join('');
}

function renderQRGrid(tables) {
  const SITE = BASE_URL + 'index.html';
  document.getElementById('qr-grid').innerHTML = tables.map(t => {
    const url = `${SITE}?table=${t.table_code}`;
    // Use QR API (Google Charts or local)
    const qrSrc = `https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=${encodeURIComponent(url)}`;
    return `<div style="text-align:center;background:var(--warm);border-radius:10px;padding:1rem;border:1px solid var(--border)">
      <img src="${qrSrc}" alt="${t.table_code}" style="width:120px;height:120px;border-radius:6px">
      <div style="font-weight:700;margin-top:.5rem;font-size:.9rem">${t.table_code}</div>
      <div style="font-size:.72rem;color:var(--muted);margin-bottom:.5rem">${t.label||''} · ${t.seats} seats</div>
      <a href="${url}" target="_blank" style="font-size:.72rem;color:var(--ink);text-decoration:none;word-break:break-all">${url}</a>
    </div>`;
  }).join('');
}

function printAllQR() {
  const SITE = BASE_URL + 'index.html';
  const tables = document.querySelectorAll('#qr-grid > div');
  const win = window.open('','_blank');
  win.document.write(`
    <html><head><title>NoodleHaus QR Codes</title>
    <style>
      body{font-family:sans-serif;margin:0;}
      .page{width:9cm;height:9cm;border:1px solid #ddd;border-radius:12px;
            display:inline-flex;flex-direction:column;align-items:center;justify-content:center;
            margin:8px;padding:12px;text-align:center;page-break-inside:avoid;}
      img{width:120px;height:120px;}
      h2{margin:6px 0 2px;font-size:1.1rem;}
      p{margin:0;font-size:.65rem;color:#666;word-break:break-all;}
      @media print{body{margin:0;} .no-print{display:none;}}
    </style></head><body>
    <div class="no-print" style="padding:12px">
      <button onclick="window.print()">🖨️ Print</button>
    </div>
    ${Array.from(tables).map(el => {
      const code = el.querySelector('div[style*="font-weight:700"]')?.textContent?.trim();
      if (!code) return '';
      const url  = `${SITE}?table=${code}`;
      const qr   = `https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(url)}`;
      return `<div class="page"><img src="${qr}"><h2>${code}</h2><p>${url}</p></div>`;
    }).join('')}
</body></html>`);
  win.document.close();
}

async function closeTable(orderId, code) {
  if (!confirm(`Table ${code} ကို Close & Paid မှတ်မည်သေချာသလား?`)) return;
  const r = await fetch('table_api.php?action=close_table', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({order_id: orderId})
  });
  const d = await r.json();
  if (d.ok) { toast(`Table ${code} closed ✓`,'ok'); loadTables(); }
  else toast(d.msg||'Error','err');
}

async function resetTable(code) {
  const r = await fetch('table_api.php?action=open_table', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({table_code: code})
  });
  const d = await r.json();
  if (d.ok) { toast(`Table ${code} reset ✓`,'ok'); loadTables(); }
  else toast(d.msg||'Error','err');
}

function openAddTableModal() {
  document.getElementById('add-table-modal').classList.add('open');
}

async function saveNewTable() {
  const code  = document.getElementById('new-table-code').value.trim().toUpperCase();
  const label = document.getElementById('new-table-label').value.trim();
  const seats = parseInt(document.getElementById('new-table-seats').value) || 4;
  if (!code) { toast('Table code လိုသည်','err'); return; }
  const r = await fetch('table_api.php?action=add_table', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({code, label, seats})
  });
  const d = await r.json();
  if (d.ok) {
    toast(`Table ${code} saved ✓`,'ok');
    document.getElementById('add-table-modal').classList.remove('open');
    loadTables();
  } else toast(d.msg||'Error','err');
}

/* ═══════════════════════════════════════
   CMS SETTINGS
═══════════════════════════════════════ */
async function loadSettings() {
  try {
    const r = await fetch('site_settings.php?action=get');
    const d = await r.json();
    if (!d.ok) { toast('Settings load failed','err'); return; }
    const s = d.settings || {};
    const keys = [
      'store_name','store_emoji','open_hours','delivery_fee','township_fees','promo_codes',
      'hero_badge','delivery_label','hero_title_line1','hero_title_line2','hero_subtitle',
      'announcement_text','announcement_color','announcement_on',
      'header_bg_color','header_logo_text_color','header_text_color','header_bg_img_opacity',
    'hero_bg_color','hero_bg_img_opacity','hero_title_color','hero_subtitle_color',
    'hero_badge_color','hero_emoji',
    'hero_bg_color','hero_bg_img_opacity','hero_title_color','hero_subtitle_color',
    'hero_badge_color','hero_emoji',
      'footer_phone','footer_address','footer_facebook','footer_instagram','footer_tiktok',
      'footer_copyright','footer_bg_color','footer_bg_opacity'
    ];
    keys.forEach(k => {
      const el = document.getElementById('st-'+k);
      if (!el) return;
      if (s[k] !== undefined && s[k] !== null) el.value = s[k];
    });

    // Header opacity label
    const hOpEl  = document.getElementById('st-header_bg_img_opacity');
    const hOpLbl = document.getElementById('hdr-opacity-val');
    if (hOpEl && hOpLbl) hOpLbl.textContent = hOpEl.value;

    // Hero opacity label
    const heroOpEl  = document.getElementById('st-hero_bg_img_opacity');
    const heroOpLbl = document.getElementById('hero-opacity-val');
    if (heroOpEl && heroOpLbl) heroOpLbl.textContent = heroOpEl.value;

    // Hero image preview
    if (s.hero_bg_image) {
      const prev = document.getElementById('hero-bg-preview');
      const rBtn = document.getElementById('hero-bg-remove-btn');
      const hBg  = document.getElementById('hbp-bg');
      if (prev) { prev.src = s.hero_bg_image; prev.style.display='block'; }
      if (rBtn)   rBtn.style.display = 'inline-flex';
      if (hBg)  { hBg.style.backgroundImage=`url('${s.hero_bg_image}')`; hBg.style.display='block'; }
    }

    // Header image preview
    if (s.header_bg_image) {
      const prev = document.getElementById('header-bg-preview');
      const rBtn = document.getElementById('header-bg-remove-btn');
      const hpBg = document.getElementById('hp-bg-img');
      if (prev) { prev.src = s.header_bg_image; prev.style.display='block'; }
      if (rBtn)   rBtn.style.display = 'inline-flex';
      if (hpBg) { hpBg.style.backgroundImage=`url('${s.header_bg_image}')`; hpBg.style.display='block'; }
    }

    // Footer opacity label
    const opEl = document.getElementById('st-footer_bg_opacity');
    const opLbl = document.getElementById('footer-opacity-val');
    if (opEl && opLbl) opLbl.textContent = opEl.value;

    // Footer bg image preview
    if (s.footer_bg_image) {
      const prev = document.getElementById('footer-bg-preview');
      const rBtn = document.getElementById('footer-bg-remove-btn');
      if (prev) { prev.src = s.footer_bg_image; prev.style.display = 'block'; }
      if (rBtn)   rBtn.style.display = 'inline-flex';
      const fpBg = document.getElementById('fp-bg');
      if (fpBg) { fpBg.style.backgroundImage = `url('${s.footer_bg_image}')`; fpBg.style.display='block'; }
    }
    // Footer logo image preview
    if (s.footer_logo_image) {
      const prev = document.getElementById('footer-logo-preview');
      const rBtn = document.getElementById('footer-logo-remove-btn');
      if (prev) { prev.src = s.footer_logo_image; prev.style.display = 'block'; }
      if (rBtn)   rBtn.style.display = 'inline-flex';
    }
    updateAnnPreview();
    updateHeroPreview();
    updateFooterPreview();
    updateHeaderPreview();
    populateKpayQrPreview(s);
    if(typeof initTownshipEditors==='function') initTownshipEditors(s);
  } catch(e) {
    toast('Settings load error: '+e.message,'err');
  }
}

/* ── Generic section image upload/remove ── */
async function uploadSectionImg(input, section) {
  if (!input.files[0]) return;
  const file = input.files[0];
  if (file.size > 3*1024*1024) { toast('Max 3MB','err'); return; }
  const fd = new FormData();
  fd.append('img', file);
  fd.append('type', section);
  try {
    const r = await fetch('admin.php?api=upload_footer_img', {method:'POST',body:fd});
    const d = await r.json();
    if (!d.ok) { toast(d.msg||'Upload failed','err'); return; }
    const key  = section + '_bg_image';
    const prev = document.getElementById(section+'-bg-preview');
    const rBtn = document.getElementById(section+'-bg-remove-btn');
    const bgEl = document.getElementById(section === 'hero' ? 'hbp-bg' : section+'-bg-img');
    if (prev) { prev.src = d.path; prev.style.display='block'; }
    if (rBtn)   rBtn.style.display = 'inline-flex';
    if (bgEl) { bgEl.style.backgroundImage=`url('${d.path}')`; bgEl.style.display='block'; }
    await fetch('site_settings.php?action=save',{
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({[key]: d.path})
    });
    toast(section+' image uploaded ✓','ok');
    if (section==='hero') updateHeroPreview();
  } catch(e) { toast('Error: '+e.message,'err'); }
}

async function removeSectionImg(section) {
  const key  = section + '_bg_image';
  const prev = document.getElementById(section+'-bg-preview');
  const rBtn = document.getElementById(section+'-bg-remove-btn');
  const inp  = document.getElementById(section+'-bg-file');
  const bgEl = document.getElementById(section === 'hero' ? 'hbp-bg' : section+'-bg-img');
  if (prev) { prev.src=''; prev.style.display='none'; }
  if (rBtn)   rBtn.style.display='none';
  if (inp)    inp.value='';
  if (bgEl) { bgEl.style.backgroundImage=''; bgEl.style.display='none'; }
  await fetch('site_settings.php?action=save',{
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({[key]:''})
  });
  toast(section+' image removed','ok');
}

/* ── Hero live preview ── */
function updateHeroPreview() {
  const bg      = document.getElementById('st-hero_bg_color')?.value     || '#1a1209';
  const tColor  = document.getElementById('st-hero_title_color')?.value  || '#ffffff';
  const sColor  = document.getElementById('st-hero_subtitle_color')?.value || '#b8a48a';
  const bColor  = document.getElementById('st-hero_badge_color')?.value  || '#f0a500';
  const opacity = document.getElementById('st-hero_bg_img_opacity')?.value || '0.3';
  const badge   = document.getElementById('st-hero_badge')?.value        || '🔥 Live Kitchen';
  const line1   = document.getElementById('st-hero_title_line1')?.value  || 'Authentic Asian';
  const line2   = document.getElementById('st-hero_title_line2')?.value  || 'Noodles & More';
  const sub     = document.getElementById('st-hero_subtitle')?.value     || 'Freshly prepared, delivered hot.';
  const emoji   = document.getElementById('st-hero_emoji')?.value        || '🍜';

  const box     = document.getElementById('hero-preview-box');
  const hBadge  = document.getElementById('hbp-badge');
  const hTitle  = document.getElementById('hbp-title');
  const hSub    = document.getElementById('hbp-sub');
  const hEmoji  = document.getElementById('hbp-emoji');
  const hBg     = document.getElementById('hbp-bg');

  if (box)    box.style.background   = bg;
  if (hBadge) { hBadge.textContent = badge; hBadge.style.color=bColor; hBadge.style.borderColor=bColor; }
  if (hTitle) {
    hTitle.style.color = tColor;
    hTitle.innerHTML   = line1 + '<br><em style="color:'+bColor+';font-style:normal">'+line2+'</em>';
  }
  if (hSub)   { hSub.textContent = sub;   hSub.style.color   = sColor; }
  if (hEmoji)   hEmoji.textContent = emoji;
  if (hBg)      hBg.style.opacity  = opacity;
}

async function uploadHeaderImg(input) {
  if (!input.files[0]) return;
  const file = input.files[0];
  if (file.size > 3*1024*1024) { toast('Max 3MB','err'); return; }
  const fd = new FormData();
  fd.append('img', file);
  fd.append('type', 'header');
  try {
    const r = await fetch('admin.php?api=upload_footer_img', { method:'POST', body:fd });
    const d = await r.json();
    if (!d.ok) { toast(d.msg||'Upload failed','err'); return; }
    const prev = document.getElementById('header-bg-preview');
    const rBtn = document.getElementById('header-bg-remove-btn');
    if (prev) { prev.src = d.path; prev.style.display = 'block'; }
    if (rBtn)   rBtn.style.display = 'inline-flex';
    // Update preview
    const hpBg = document.getElementById('hp-bg-img');
    if (hpBg) { hpBg.style.backgroundImage=`url('${d.path}')`; hpBg.style.display='block'; }
    // Save to DB
    await fetch('site_settings.php?action=save',{
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({header_bg_image: d.path})
    });
    toast('Header image uploaded ✓','ok');
  } catch(e) { toast('Error: '+e.message,'err'); }
}

async function removeHeaderImg() {
  const prev = document.getElementById('header-bg-preview');
  const rBtn = document.getElementById('header-bg-remove-btn');
  const inp  = document.getElementById('header-bg-file');
  const hpBg = document.getElementById('hp-bg-img');
  if (prev) { prev.src=''; prev.style.display='none'; }
  if (rBtn)   rBtn.style.display = 'none';
  if (inp)    inp.value = '';
  if (hpBg) { hpBg.style.backgroundImage=''; hpBg.style.display='none'; }
  await fetch('site_settings.php?action=save',{
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({header_bg_image:''})
  });
  toast('Header image removed','ok');
}

function updateHeaderPreview() {
  const color     = document.getElementById('st-header_bg_color')?.value     || '#1a1209';
  const accent    = document.getElementById('st-header_logo_text_color')?.value || '#f0a500';
  const textColor = document.getElementById('st-header_text_color')?.value    || '#b8a48a';
  const opacity   = document.getElementById('st-header_bg_img_opacity')?.value || '0.2';
  const emoji     = document.getElementById('st-store_emoji')?.value           || '🍜';

  const box     = document.getElementById('header-preview-box');
  const hpAcc   = document.getElementById('hp-accent');
  const hpStat  = document.getElementById('hp-status');
  const hpEmoji = document.getElementById('hp-emoji');
  const hpBg    = document.getElementById('hp-bg-img');

  if (box)     box.style.background   = color;
  if (hpAcc)   hpAcc.style.color      = accent;
  if (hpStat)  hpStat.style.color     = textColor;
  if (hpEmoji) hpEmoji.textContent    = emoji;
  if (hpBg)    hpBg.style.opacity     = opacity;
}

async function uploadFooterImg(input, type) {
  if (!input.files[0]) return;
  const file = input.files[0];
  if (file.size > 3 * 1024 * 1024) { toast('Max 3MB','err'); return; }

  const fd = new FormData();
  fd.append('img', file);
  fd.append('type', type); // 'bg' or 'logo'

  try {
    const r = await fetch('admin.php?api=upload_footer_img', { method:'POST', body: fd });
    const d = await r.json();
    if (!d.ok) { toast(d.msg || 'Upload failed','err'); return; }

    const key  = type === 'bg' ? 'footer_bg_image' : 'footer_logo_image';
    const prev = document.getElementById('footer-'+type+'-preview');
    const rBtn = document.getElementById('footer-'+type+'-remove-btn');
    if (prev) { prev.src = d.path; prev.style.display = 'block'; }
    if (rBtn)   rBtn.style.display = 'inline-flex';

    // Save path to DB
    await fetch('site_settings.php?action=save', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({[key]: d.path})
    });
    toast((type==='bg'?'Background':'Logo') + ' uploaded ✓','ok');
    updateFooterPreview();
  } catch(e) { toast('Upload error: '+e.message,'err'); }
}

async function removeFooterImg(type) {
  const key  = type === 'bg' ? 'footer_bg_image' : 'footer_logo_image';
  const prev = document.getElementById('footer-'+type+'-preview');
  const rBtn = document.getElementById('footer-'+type+'-remove-btn');
  const inp  = document.getElementById('footer-'+type+'-file');
  if (prev) { prev.src=''; prev.style.display='none'; }
  if (rBtn)   rBtn.style.display = 'none';
  if (inp)    inp.value = '';
  await fetch('site_settings.php?action=save', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({[key]: ''})
  });
  if (type === 'bg') {
    const fpBg = document.getElementById('fp-bg');
    if (fpBg) fpBg.style.display = 'none';
  }
  toast('Image removed','ok');
}

function updateFooterPreview() {
  const color   = document.getElementById('st-footer_bg_color')?.value || '#1a1209';
  const opacity = document.getElementById('st-footer_bg_opacity')?.value || '1';
  const store   = document.getElementById('st-store_name')?.value || 'NoodleHaus';
  const copy    = document.getElementById('st-footer_copyright')?.value || '';
  const overlay = document.getElementById('fp-overlay');
  const fpStore = document.getElementById('fp-store');
  const fpCopy  = document.getElementById('fp-copy');
  if (overlay) { overlay.style.background = color; overlay.style.opacity = opacity; }
  if (fpStore)  fpStore.textContent = store;
  if (fpCopy)   fpCopy.textContent  = copy;
}

// Wire up live preview inputs
document.addEventListener('DOMContentLoaded', () => {
  ['st-footer_bg_color','st-footer_bg_opacity','st-store_name','st-footer_copyright'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', updateFooterPreview);
  });
  ['st-header_bg_color','st-header_logo_text_color','st-header_text_color',
   'st-header_bg_img_opacity','st-store_emoji'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', updateHeaderPreview);
  });
  ['st-hero_bg_color','st-hero_title_color','st-hero_subtitle_color',
   'st-hero_badge_color','st-hero_emoji','st-hero_bg_img_opacity',
   'st-hero_badge','st-hero_title_line1','st-hero_title_line2','st-hero_subtitle'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', updateHeroPreview);
  });
});

function updateAnnPreview() {
  const text  = document.getElementById('st-announcement_text')?.value || '';
  const color = document.getElementById('st-announcement_color')?.value || '#e84c2b';
  const on    = document.getElementById('st-announcement_on')?.value === '1';
  const prev  = document.getElementById('ann-preview');
  const hiddenNote = document.getElementById('ann-preview-hidden');
  if (!prev) return;
  if (on && text) {
    prev.textContent      = text;
    prev.style.background = color;
    prev.style.display    = 'block';
    if (hiddenNote) hiddenNote.style.display = 'none';
  } else {
    prev.style.display = 'none';
    if (hiddenNote) hiddenNote.style.display = 'block';
  }
}

/* Live preview listeners */
['st-announcement_text','st-announcement_color','st-announcement_on'].forEach(id => {
  document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', updateAnnPreview);
  });
});

async function saveSettings() {
  const keys = [
    'store_name','store_emoji','open_hours','delivery_fee','township_fees','promo_codes',
    'hero_badge','delivery_label','hero_title_line1','hero_title_line2','hero_subtitle',
    'announcement_text','announcement_color','announcement_on',
    'header_bg_color','header_logo_text_color','header_text_color','header_bg_img_opacity',
    'hero_bg_color','hero_bg_img_opacity','hero_title_color','hero_subtitle_color',
    'hero_badge_color','hero_emoji',
    'hero_bg_color','hero_bg_img_opacity','hero_title_color','hero_subtitle_color',
    'hero_badge_color','hero_emoji',
    'footer_phone','footer_address','footer_facebook','footer_instagram','footer_tiktok',
    'footer_copyright','footer_bg_color','footer_bg_opacity'
  ];
  const payload = {};
  keys.forEach(k => {
    const el = document.getElementById('st-'+k);
    if (el) payload[k] = el.value;
  });
  try {
    const r = await fetch('site_settings.php?action=save', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    });
    const d = await r.json();
    if (d.ok) { toast('Settings saved ✓','ok'); updateAnnPreview(); }
    else       { toast(d.msg || 'Save failed','err'); }
  } catch(e) {
    toast('Save error: '+e.message,'err');
  }
}

/* ═══════════════════════════════════════
   INIT
═══════════════════════════════════════ */
<?php if ($loggedIn): ?>


function showToast(msg, isErr=false) {
  if (typeof toast === 'function') { toast(msg, isErr ? 'err' : 'ok'); }
}

function escHtml(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

showPage('dashboard');
<?php endif; ?>
</script>
<script>
function twRow(n,f){
  var d=document.createElement('div');
  d.style.cssText='display:flex;gap:6px;margin-bottom:4px';
  d.innerHTML='<input class="tw-n" placeholder="မြို့နယ်" value="'+n+'" style="flex:2;padding:.3rem .6rem;border:1px solid #ccc;border-radius:6px;font-size:.82rem">'
    +'<input type="number" class="tw-f" placeholder="Ks" value="'+f+'" style="flex:1;padding:.3rem .6rem;border:1px solid #ccc;border-radius:6px;font-size:.82rem">'
    +'<button type="button" style="padding:.3rem .6rem;background:#dc2626;color:#fff;border:none;border-radius:6px;cursor:pointer" onclick="this.parentElement.remove()">✕</button>';
  return d;
}
function prRow(c,t,v,l){
  var d=document.createElement('div');
  d.style.cssText='display:flex;gap:6px;margin-bottom:4px;flex-wrap:wrap';
  d.innerHTML='<input class="pr-c" placeholder="CODE" value="'+c+'" style="width:90px;padding:.3rem .6rem;border:1px solid #ccc;border-radius:6px;font-size:.82rem;text-transform:uppercase">'
    +'<select class="pr-t" style="padding:.3rem .6rem;border:1px solid #ccc;border-radius:6px;font-size:.82rem">'
    +'<option value="fixed"'+(t==='fixed'?' selected':'')+'>Fixed Ks</option>'
    +'<option value="percent"'+(t==='percent'?' selected':'')+'>Percent %</option>'
    +'<option value="free_ship"'+(t==='free_ship'?' selected':'')+'>Free ship</option></select>'
    +'<input type="number" class="pr-v" placeholder="value" value="'+v+'" style="width:80px;padding:.3rem .6rem;border:1px solid #ccc;border-radius:6px;font-size:.82rem">'
    +'<input class="pr-l" placeholder="label" value="'+l+'" style="flex:1;min-width:100px;padding:.3rem .6rem;border:1px solid #ccc;border-radius:6px;font-size:.82rem">'
    +'<button type="button" style="padding:.3rem .6rem;background:#dc2626;color:#fff;border:none;border-radius:6px;cursor:pointer" onclick="this.parentElement.remove()">✕</button>';
  return d;
}
function addTownshipRow(){document.getElementById('township-fee-editor').appendChild(twRow('',''));}
function addPromoRow(){document.getElementById('promo-code-editor').appendChild(prRow('','fixed','',''));}
function initTownshipEditors(s){
  var tw={};try{tw=JSON.parse(s.township_fees||'{}');}catch(e){}
  var wrap=document.getElementById('township-fee-editor');
  if(wrap){wrap.innerHTML='';Object.entries(tw).forEach(function(e){wrap.appendChild(twRow(e[0],e[1]));});}
  var pr=[];try{pr=JSON.parse(s.promo_codes||'[]');}catch(e){}
  var pw=document.getElementById('promo-code-editor');
  if(pw){pw.innerHTML='';pr.forEach(function(p){pw.appendChild(prRow(p.code||'',p.type||'fixed',p.value||'',p.label||''));});}
}
function collectTownshipPromo(){
  var tw={};
  document.querySelectorAll('#township-fee-editor>div').forEach(function(r){
    var n=r.querySelector('.tw-n').value.trim();
    var f=parseInt(r.querySelector('.tw-f').value)||0;
    if(n)tw[n]=f;
  });
  document.getElementById('st-township_fees').value=JSON.stringify(tw);
  var pr=[];
  document.querySelectorAll('#promo-code-editor>div').forEach(function(r){
    var c=r.querySelector('.pr-c').value.trim().toUpperCase();
    var t=r.querySelector('.pr-t').value;
    var v=parseInt(r.querySelector('.pr-v').value)||0;
    var l=r.querySelector('.pr-l').value.trim();
    if(c)pr.push({code:c,type:t,value:v,label:l});
  });
  document.getElementById('st-promo_codes').value=JSON.stringify(pr);
}
</script>
<script src="admin_modules.js?v=1781166732"></script>
<script>
(function(){
var slOff=0,slTotal=0,SL_LIMIT=50;
var AC={add:{bg:"#dcfce7",c:"#166534",l:"ထည့်သွင်း"},remove:{bg:"#fee2e2",c:"#991b1b",l:"ထုတ်ယူ"},adjust:{bg:"#dbeafe",c:"#1e40af",l:"ပြင်ဆင်"},waste:{bg:"#fef9c3",c:"#854d0e",l:"ဖျက်ဆီး"},order_deduct:{bg:"#f3e8ff",c:"#6b21a8",l:"Order နုတ်"},initial:{bg:"#f0f9ff",c:"#0c4a6e",l:"စတင်"}};
function esc(s){return String(s||"").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;");}
window.loadStockLogs=function(r){
  if(r!==false)slOff=0;
  var today=new Date(),fd=new Date(today.getFullYear(),today.getMonth(),1);
  document.getElementById("sl-date-from").value=fd.toISOString().split("T")[0];
  document.getElementById("sl-date-to").value=today.toISOString().split("T")[0];
  var p=new URLSearchParams({action:"list",limit:SL_LIMIT,offset:slOff,search:document.getElementById("sl-search").value,action_type:document.getElementById("sl-action").value,date_from:document.getElementById("sl-date-from").value,date_to:document.getElementById("sl-date-to").value});
  document.getElementById("sl-tbody").innerHTML="<tr><td colspan=9 style=text-align:center;padding:30px;color:#aaa>Loading...</td></tr>";
  fetch("stock_log_api.php?"+p).then(function(r){return r.json();}).then(function(d){
    slTotal=d.total||0;
    var rows=d.logs||[];
    if(!rows.length){document.getElementById("sl-tbody").innerHTML="<tr><td colspan=9 style=text-align:center;padding:30px;color:#aaa>မှတ်တမ်း မရှိသေး</td></tr>";}
    else{document.getElementById("sl-tbody").innerHTML=rows.map(function(r,i){
      var chg=parseFloat(r.change_qty),cc=chg>0?"#16a34a":chg<0?"#dc2626":"#888",cs=chg>0?"+":"";
      var rL={restock:"📥 Restock",manual_adjust:"✏️ Adjust",order_deduct:"🛒 Order",waste:"🗑 Waste",correction:"🔧 Fix",returned:"↩ Return"};
      var rBg=chg>0?"#dcfce7":chg<0?"#fee2e2":"#f3f4f6",rC=chg>0?"#166534":chg<0?"#991b1b":"#555";
      return "<tr style=border-bottom:1px solid #eee;"+(i%2?"background:#fafafa":"")+">"
        +"<td style=padding:8px 12px;color:#aaa>"+r.id+"</td>"
        +"<td style=padding:8px 12px;font-weight:500>"+esc(r.item_name)+"</td>"
        +"<td style=padding:8px 12px;text-align:center><span style=background:"+rBg+";color:"+rC+";padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600>"+(rL[r.reason]||r.reason||"—")+"</span></td>"
        +"<td style=padding:8px 12px;text-align:center;color:#888>—</td>"
        +"<td style=padding:8px 12px;text-align:center;font-weight:600>"+r.new_qty+"</td>"
        +"<td style=padding:8px 12px;text-align:center;font-weight:600;color:"+cc+">"+cs+chg+"</td>"
        +"<td style=padding:8px 12px;color:#666;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap>"+esc(r.note||"—")+"</td>"
        +"<td style=padding:8px 12px;color:#555>"+esc(r.staff_name||"—")+"</td>"
        +"<td style=padding:8px 12px;color:#888;font-size:12px;white-space:nowrap>"+r.created_fmt+"</td></tr>";
    }).join("");}
    document.getElementById("sl-count").textContent=(slOff+1)+"–"+Math.min(slOff+SL_LIMIT,slTotal)+" / "+slTotal+" records";
    document.getElementById("sl-prev").disabled=slOff===0;
    document.getElementById("sl-next").disabled=slOff+SL_LIMIT>=slTotal;
  }).catch(function(){document.getElementById("sl-tbody").innerHTML="<tr><td colspan=9 style=text-align:center;padding:30px;color:red>API error</td></tr>";});
  fetch("stock_log_api.php?action=summary&date_from="+document.getElementById("sl-date-from").value+"&date_to="+document.getElementById("sl-date-to").value).then(function(r){return r.json();}).then(function(d){
    var el=document.getElementById("sl-summary");
    if(!d.summary||!d.summary.length){el.innerHTML="";return;}
    el.innerHTML=d.summary.map(function(s){var st=AC[s.action]||{bg:"#f5f5f5",c:"#333",l:s.action};return "<div style=background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:12px;text-align:center><div style=font-size:10px;font-weight:600;color:"+st.c+";background:"+st.bg+";padding:2px 8px;border-radius:10px;display:inline-block;margin-bottom:6px>"+st.l+"</div><div style=font-size:22px;font-weight:700>"+s.total_entries+"</div><div style=font-size:11px;color:#888>entries</div></div>";}).join("");
  });
};
window.slPage=function(d){slOff=Math.max(0,slOff+d*SL_LIMIT);loadStockLogs(false);};
window.exportSL=function(){window.open("stock_log_api.php?action=export_csv&date_from="+document.getElementById("sl-date-from").value+"&date_to="+document.getElementById("sl-date-to").value,"_blank");};
})();
</script>

<script>
/* ══ STAFF MANAGEMENT ══ */
const ALL_PAGES = [
  {k:'dashboard',l:'📊 Dashboard'},{k:'orders',l:'📋 Orders'},
  {k:'menu',l:'🍜 Menu'},{k:'tables',l:'🍽️ Tables'},
  {k:'stock',l:'📦 Stock'},{k:'stocklog',l:'📋 Stock Log'},
  {k:'crm',l:'👥 CRM'},{k:'shift',l:'🕐 Shifts'},
  {k:'reserve',l:'📅 Reservations'},{k:'delivery',l:'🛵 Delivery'},
  {k:'branches',l:'🏢 Branches'},{k:'analytics',l:'📈 Analytics'},
  {k:'settings',l:'⚙️ Settings'}
];
async function loadStaff(){
  const el=document.getElementById('staff-list');
  el.innerHTML='<div style="padding:2rem;text-align:center;color:var(--text-muted)">Loading...</div>';
  try{
    const d=await(await fetch('staff_api.php?action=list')).json();
    if(!d.ok)throw new Error(d.msg);
    if(!d.staff.length){el.innerHTML='<div style="padding:2rem;text-align:center;color:var(--text-muted)">No staff yet</div>';return;}
    el.innerHTML=d.staff.map(s=>{
      const perms=(s.permissions||[]).map(p=>{const pg=ALL_PAGES.find(x=>x.k===p);return pg?`<span style="font-size:.72rem;background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:.15rem .5rem">${pg.l}</span>`:''}).join(' ');
      const roleBadge=s.role==='manager'?'<span style="background:#dbeafe;color:#1e40af;padding:.2rem .6rem;border-radius:10px;font-size:.75rem;font-weight:600">👔 Manager</span>':'<span style="background:#f3f4f6;color:#555;padding:.2rem .6rem;border-radius:10px;font-size:.75rem;font-weight:600">🧑‍🍳 Waiter</span>';
      const activeBadge=s.is_active?'<span style="color:#27ae60;font-size:.75rem">● Active</span>':'<span style="color:#e74c3c;font-size:.75rem">● Inactive</span>';
      const sData=encodeURIComponent(JSON.stringify(s));
      return `<div class="card" style="padding:1rem 1.2rem;${!s.is_active?'opacity:.55':''}">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:.6rem">
          <div style="display:flex;align-items:center;gap:.75rem">
            <div style="width:40px;height:40px;border-radius:50%;background:var(--surface2);display:flex;align-items:center;justify-content:center;font-size:1.2rem">${s.role==='manager'?'👔':'🧑‍🍳'}</div>
            <div><div style="font-weight:700">${escHtml(s.name)}</div><div style="font-size:.78rem;color:var(--text-muted);margin-top:.1rem">${roleBadge} ${activeBadge}</div></div>
          </div>
          <div style="display:flex;gap:.5rem">
            <button onclick="staffOpenEdit('${sData}')" style="padding:.4rem .8rem;background:var(--surface2);border:1px solid var(--border);border-radius:7px;font-size:.8rem;cursor:pointer;color:var(--text)">✏️ Edit</button>
            <button onclick="staffToggle(${s.id},${s.is_active})" style="padding:.4rem .8rem;background:var(--surface2);border:1px solid var(--border);border-radius:7px;font-size:.8rem;cursor:pointer;color:${s.is_active?'#e74c3c':'#27ae60'}">${s.is_active?'🚫 Disable':'✅ Enable'}</button>
            <button onclick="staffDelete(${s.id},'${escHtml(s.name)}')" style="padding:.4rem .8rem;background:#fee2e2;border:1px solid #fca5a5;border-radius:7px;font-size:.8rem;cursor:pointer;color:#991b1b">🗑</button>
          </div>
        </div>
        ${perms?`<div style="display:flex;flex-wrap:wrap;gap:.3rem">${perms}</div>`:'<div style="font-size:.75rem;color:var(--text-muted)">No permissions set</div>'}
        ${s.notes?`<div style="font-size:.78rem;color:var(--text-muted);margin-top:.4rem">📝 ${escHtml(s.notes)}</div>`:''}
      </div>`;
    }).join('');
  }catch(e){el.innerHTML=`<div style="color:#e74c3c;padding:2rem;text-align:center">${e.message}</div>`;}
}
function staffRenderPerms(selected=[]){
  document.getElementById('sf-perms').innerHTML=ALL_PAGES.map(p=>
    `<label style="display:flex;align-items:center;gap:.4rem;font-size:.82rem;cursor:pointer;padding:.3rem .5rem;border:1px solid var(--border);border-radius:7px;background:${selected.includes(p.k)?'rgba(232,160,48,.1)':'var(--surface2)'}">
      <input type="checkbox" value="${p.k}" ${selected.includes(p.k)?'checked':''} style="accent-color:var(--accent)">${p.l}
    </label>`
  ).join('');
}
function staffOpenAdd(){
  document.getElementById('sf-id').value='';
  document.getElementById('sf-name').value='';
  document.getElementById('sf-pin').value='';
  document.getElementById('sf-role').value='waiter';
  document.getElementById('sf-notes').value='';
  document.getElementById('staff-modal-title').textContent='+ Add Staff';
  staffRenderPerms(['orders','tables']);
  document.getElementById('staff-modal').style.display='flex';
}
function staffOpenEdit(encoded){
  const s=JSON.parse(decodeURIComponent(encoded));
  document.getElementById('sf-id').value=s.id;
  document.getElementById('sf-name').value=s.name;
  document.getElementById('sf-pin').value='';
  document.getElementById('sf-role').value=s.role;
  document.getElementById('sf-notes').value=s.notes||'';
  document.getElementById('staff-modal-title').textContent='✏️ Edit — '+s.name;
  staffRenderPerms(s.permissions||[]);
  document.getElementById('staff-modal').style.display='flex';
}
async function staffSave(){
  const id=document.getElementById('sf-id').value;
  const perms=Array.from(document.querySelectorAll('#sf-perms input:checked')).map(i=>i.value);
  const payload={name:document.getElementById('sf-name').value.trim(),pin:document.getElementById('sf-pin').value.trim(),role:document.getElementById('sf-role').value,permissions:perms,notes:document.getElementById('sf-notes').value.trim()};
  if(id)payload.id=parseInt(id);
  const action=id?'update':'add';
  try{
    const d=await(await fetch('staff_api.php?action='+action,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})).json();
    if(!d.ok){alert('Error: '+d.msg);return;}
    document.getElementById('staff-modal').style.display='none';
    loadStaff();
  }catch(e){alert('Error: '+e.message);}
}
async function staffToggle(id,current){
  const d=await(await fetch('staff_api.php?action=update',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,is_active:current?0:1})})).json();
  if(d.ok)loadStaff(); else alert(d.msg);
}
async function staffDelete(id,name){
  if(!confirm(name+' ကို ဖျက်မည် — သေချာပါသလား?'))return;
  const d=await(await fetch('staff_api.php?action=delete',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})})).json();
  if(d.ok)loadStaff(); else alert(d.msg);
}
/* ══ END STAFF ══ */
</script>

<?php if (!empty($_SESSION['demo_mode'])): ?>
<script>
// READ ONLY MODE - disable all save/delete/add buttons
document.addEventListener('DOMContentLoaded', function() {
  // Intercept all fetch POST requests
  var origFetch = window.fetch;
  window.fetch = function(url, opts) {
    if (opts && opts.method && opts.method.toUpperCase() === 'POST') {
      var u = String(url);
      // Allow login/logout only
      if (!u.includes('api=login') && !u.includes('api=logout')) {
        showToast('🔒 Read-only demo — Data မပြောင်းနိုင်ပါ', true);
        return Promise.resolve(new Response(JSON.stringify({ok:false,msg:'Demo mode - read only'}), {status:200, headers:{'Content-Type':'application/json'}}));
      }
    }
    return origFetch.apply(this, arguments);
  };
  // Visual: grey out all save/action buttons
  setTimeout(function() {
    document.querySelectorAll('.btn-primary, .btn-danger, [onclick*="save"], [onclick*="delete"], [onclick*="Delete"]').forEach(function(el) {
      el.style.opacity = '0.4';
      el.style.cursor = 'not-allowed';
      el.title = '🔒 Read-only demo';
    });
  }, 2000);
});
</script>
<?php endif; ?>
</body>
</html>

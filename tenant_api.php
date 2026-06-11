<?php
/**
 * NoodleHaus SaaS — Tenant API (Phase 8)
 * Actions: signup, list, detail, update, toggle, plans, billing, stats
 */
declare(strict_types=1);
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$pdo = getPDO();
$action = trim($_GET['action'] ?? '');
function ok(mixed $d=[]): never { echo json_encode(array_merge(['ok'=>true],(array)$d),JSON_UNESCAPED_UNICODE); exit; }
function fail(string $m, int $c=400): never { http_response_code($c); echo json_encode(['ok'=>false,'msg'=>$m]); exit; }
function requireSuperAdmin(): void {
    if(session_status()===PHP_SESSION_NONE)session_start();
    if(empty($_SESSION['admin']))fail('Unauthorized',401);
}

/* ── PLANS (public) ── */
if ($action === 'plans') {
    ok(['plans' => $pdo->query("SELECT * FROM saas_plans WHERE is_active=1 ORDER BY price_mmk")->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ── SIGNUP (public) ── */
if ($action === 'signup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $name  = trim($d['name'] ?? '');
    $slug  = strtolower(preg_replace('/[^a-z0-9]/', '', trim($d['slug'] ?? '')));
    $owner = trim($d['owner_name'] ?? '');
    $email = trim($d['owner_email'] ?? '');
    $phone = trim($d['owner_phone'] ?? '');
    $plan  = trim($d['plan'] ?? 'free');
    $pass  = trim($d['password'] ?? '');
    $bizType = in_array(trim($d['business_type'] ?? ''), ['noodle_shop','drinks','bakery','myanmar_food','cafe','fast_food','fine_dining','other','restaurant','demo']) ? trim($d['business_type']) : 'restaurant';

    if (!$name || !$slug || !$owner || !$email || !$phone) fail('All fields required');
    if (strlen($slug) < 3) fail('Slug must be 3+ characters');
    if (!$pass || strlen($pass) < 6) fail('Password must be 6+ characters');

    // Check uniqueness
    $chk = $pdo->prepare("SELECT id FROM tenants WHERE slug=? OR owner_email=?");
    $chk->execute([$slug, $email]);
    if ($chk->fetchColumn()) fail('Slug or email already taken');

    // Get plan limits
    $planRow = $pdo->prepare("SELECT * FROM saas_plans WHERE code=?");
    $planRow->execute([$plan]);
    $p = $planRow->fetch(PDO::FETCH_ASSOC);
    if (!$p) $p = ['max_branches'=>1,'max_staff'=>3,'max_menu_items'=>20];

    // Create tenant
    $pdo->prepare("INSERT INTO tenants (name,slug,owner_name,owner_email,owner_phone,plan,max_branches,max_staff,max_menu_items,business_type) VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$name,$slug,$owner,$email,$phone,$plan,(int)$p['max_branches'],(int)$p['max_staff'],(int)$p['max_menu_items'],$bizType]);
    $tenantId = (int)$pdo->lastInsertId();

    // Auto-provision: create branch + admin staff
    $pdo->prepare("INSERT INTO branches (tenant_id,name,code) VALUES (?,?,?)")
        ->execute([$tenantId, $name, strtoupper($slug)]);

    // Create admin staff with PIN (first 4 digits of phone as default PIN)
    $pin = substr(preg_replace('/[^0-9]/', '', $phone), -4) ?: '0000';
    $pdo->prepare("INSERT INTO staff (name,role,pin,is_active,branch_id) VALUES (?,'manager',?,1,?)")
        ->execute([$owner, $pin, 1]);

    ok(['tenant_id'=>$tenantId, 'slug'=>$slug, 'message'=>'Account created! Login at admin.php']);
}

/* ── LIST (super admin) ── */
if ($action === 'list') {
    if (session_status()===PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['admin']) && empty($_SESSION['super_admin'])) fail('Unauthorized', 401);
    requireSuperAdmin();
    $rows = $pdo->query("
        SELECT t.*,
            (SELECT COUNT(*) FROM orders o WHERE o.tenant_id=t.id AND o.deleted_at IS NULL) AS total_orders,
            (SELECT COALESCE(SUM(total_amount),0) FROM orders o WHERE o.tenant_id=t.id AND o.deleted_at IS NULL) AS total_revenue
        FROM tenants t ORDER BY t.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    ok(['tenants' => $rows]);
}

/* ── DETAIL ── */
if ($action === 'detail') {
    requireSuperAdmin();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('id required');
    $t = $pdo->prepare("SELECT * FROM tenants WHERE id=?"); $t->execute([$id]);
    $row = $t->fetch(PDO::FETCH_ASSOC);
    if (!$row) fail('Not found');

    $stats = $pdo->prepare("SELECT COUNT(*) AS orders, COALESCE(SUM(total_amount),0) AS revenue FROM orders WHERE tenant_id=? AND deleted_at IS NULL");
    $stats->execute([$id]);

    $bills = $pdo->prepare("SELECT * FROM billing WHERE tenant_id=? ORDER BY created_at DESC LIMIT 10");
    $bills->execute([$id]);

    ok(['tenant'=>$row, 'stats'=>$stats->fetch(PDO::FETCH_ASSOC), 'billing'=>$bills->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ── UPDATE ── */
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireSuperAdmin();
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['id'] ?? 0);
    if (!$id) fail('id required');
    $fields=[]; $params=[];
    foreach (['name','plan','max_branches','max_staff','max_menu_items','plan_expires'] as $f) {
        if (isset($d[$f])) { $fields[]="$f=?"; $params[]=$d[$f]===''?null:$d[$f]; }
    }
    if (empty($fields)) fail('Nothing to update');
    $params[] = $id;
    $pdo->prepare("UPDATE tenants SET ".implode(',',$fields)." WHERE id=?")->execute($params);
    ok();
}

/* ── TOGGLE ── */
if ($action === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireSuperAdmin();
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['id'] ?? 0);
    if (!$id || $id===1) fail('Cannot toggle');
    $pdo->prepare("UPDATE tenants SET is_active=NOT is_active WHERE id=?")->execute([$id]);
    ok();
}

/* ── RECORD BILLING ── */
if ($action === 'bill' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireSuperAdmin();
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $pdo->prepare("INSERT INTO billing (tenant_id,plan_code,amount,currency,payment_method,payment_ref,status,period_start,period_end) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([(int)$d['tenant_id'],$d['plan_code'],(int)$d['amount'],$d['currency']??'MMK',$d['payment_method']??null,$d['payment_ref']??null,$d['status']??'paid',$d['period_start'],$d['period_end']]);
    ok(['billing_id'=>(int)$pdo->lastInsertId()]);
}

/* ── SAAS STATS ── */
if ($action === 'stats') {
    // Allow both super admin and regular admin
    if (session_status()===PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['admin']) && empty($_SESSION['super_admin'])) fail('Unauthorized', 401);
    requireSuperAdmin();
    $s = $pdo->query("SELECT
        (SELECT COUNT(*) FROM tenants) AS total_tenants,
        (SELECT COUNT(*) FROM tenants WHERE is_active=1) AS active_tenants,
        (SELECT COUNT(*) FROM tenants WHERE plan='free') AS free_tenants,
        (SELECT COUNT(*) FROM tenants WHERE plan IN ('basic','pro','enterprise')) AS paid_tenants,
        (SELECT COALESCE(SUM(amount),0) FROM billing WHERE status='paid') AS total_revenue,
        (SELECT COALESCE(SUM(amount),0) FROM billing WHERE status='paid' AND DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')) AS month_revenue
    ")->fetch(PDO::FETCH_ASSOC);
    ok(['stats' => $s]);
}


// ── Resolve slug to tenant_id ──────────────────────────────────
if ($action === 'resolve' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $slug = trim($_GET['slug'] ?? '');
    if (!$slug) fail('Slug required');
    $row = $pdo->prepare("SELECT t.id, t.name, t.plan, t.is_active, t.business_type, b.id as branch_id, b.code as branch_code FROM tenants t LEFT JOIN branches b ON b.tenant_id = t.id WHERE t.slug=? LIMIT 1");
    $row->execute([$slug]);
    $t = $row->fetch(PDO::FETCH_ASSOC);
    if (!$t) fail('Tenant not found', 404);
    if (!$t['is_active']) fail('Account suspended', 403);
    ok(['tenant_id'=>(int)$t['id'], 'name'=>$t['name'], 'plan'=>$t['plan'], 'business_type'=>$t['business_type'] ?? 'restaurant', 'branch_id'=>(int)($t['branch_id'] ?? 1), 'branch_code'=>$t['branch_code'] ?? 'MAIN']);
}

fail('Unknown action');

// ── Manual KBZPay Payment Confirmation ──────────────────────────
if ($action === 'confirm_payment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireSuperAdmin();
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $tenantId  = (int)($d['tenant_id'] ?? 0);
    $planCode  = trim($d['plan'] ?? '');
    $payRef    = trim($d['payment_ref'] ?? '');
    $months    = max(1, (int)($d['months'] ?? 1));
    if (!$tenantId || !$planCode || !$payRef) fail('Missing fields');

    $plan = $pdo->prepare("SELECT * FROM saas_plans WHERE code=?");
    $plan->execute([$planCode]);
    $p = $plan->fetch(PDO::FETCH_ASSOC);
    if (!$p) fail('Invalid plan');

    $amount     = (int)$p['price_mmk'] * $months;
    $periodStart = date('Y-m-d');
    $periodEnd   = date('Y-m-d', strtotime("+{$months} months"));

    // Record billing
    $pdo->prepare("INSERT INTO billing (tenant_id,plan_code,amount,payment_method,payment_ref,status,period_start,period_end) VALUES (?,?,?,'kbzpay',?,?,?,?)")
        ->execute([$tenantId, $planCode, $amount, $payRef, 'paid', $periodStart, $periodEnd]);

    // Upgrade tenant plan + extend expiry
    $pdo->prepare("UPDATE tenants SET plan=?, plan_expires=?, max_branches=?, max_staff=?, max_menu_items=? WHERE id=?")
        ->execute([$planCode, $periodEnd, $p['max_branches'], $p['max_staff'], $p['max_menu_items'], $tenantId]);

    ok(['message'=>"Payment confirmed. Plan upgraded to {$planCode} until {$periodEnd}"]);
}

// ── Delete Tenant ──────────────────────────────────────────────
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['id'] ?? 0);
    if (!$id || $id === 1) fail('Cannot delete this tenant');
    // Soft delete — keep data but mark deleted
    $pdo->prepare("UPDATE tenants SET is_active=0, slug=CONCAT('deleted_',id,'_',slug), name=CONCAT('[Deleted] ',name) WHERE id=? AND id!=1")
        ->execute([$id]);
    // Clean up menu items + orders optional
    ok(['message' => 'Tenant deleted']);
}

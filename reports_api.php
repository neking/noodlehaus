
// ── Branch Analytics ────────────────────────────────────────────
if ($action === 'branches') {
    $tid = tenantId();
    $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $to   = $_GET['to']   ?? date('Y-m-d');
    $rows = $pdo->prepare("
        SELECT
            b.id, b.name, b.code,
            COUNT(o.id)                as total_orders,
            COALESCE(SUM(o.total_amount),0) as revenue,
            COALESCE(AVG(o.total_amount),0) as avg_order,
            COUNT(CASE WHEN o.status='cancelled' THEN 1 END) as cancelled,
            MAX(o.created_at)          as last_order
        FROM branches b
        LEFT JOIN orders o ON o.branch_id = b.id
            AND o.tenant_id = ?
            AND o.deleted_at IS NULL
            AND DATE(o.created_at) BETWEEN ? AND ?
        WHERE b.tenant_id = ?
        GROUP BY b.id, b.name, b.code
        ORDER BY revenue DESC
    ");
    $rows->execute([$tid, $from, $to, $tid]);
    ok(['branches' => $rows->fetchAll(PDO::FETCH_ASSOC), 'from'=>$from, 'to'=>$to]);
}

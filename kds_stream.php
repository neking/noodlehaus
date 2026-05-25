<?php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); 

// Database Connection
require_once __DIR__ . '/config.php';

$station_filter = isset($_GET['station']) ? trim($_GET['station']) : 'all';

echo "event: meta\n";
echo 'data: ' . json_encode(['filtered_station' => $station_filter]) . "\n\n";
ob_flush();
flush();

while (true) {
    try {
        $sql = "SELECT kq.*, o.order_type, o.table_id 
                FROM kds_queue kq
                JOIN orders o ON kq.order_id = o.id
                WHERE kq.status != 'served'";
        
        if ($station_filter !== 'all' && $station_filter !== '') {
            $sql .= " AND kq.station = " . $pdo->quote($station_filter);
        }
        
        $sql .= " ORDER BY kq.created_at ASC";
        
        $stmt = $pdo->query($sql);
        $active_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $batch = [];
        foreach ($active_orders as $row) {
            $item_stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
            $item_stmt->execute([$row['order_id']]);
            $items = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $batch[] = buildOrderBatch($row, $items);
        }
        
        echo "data: " . json_encode($batch) . "\n\n";
        ob_flush();
        flush();
        
    } catch (PDOException $e) {
        // Error ဖြစ်လျှင်လည်း Loop မရပ်ဘဲ ကျော်သွားရန်
    }
    
    sleep(3);
}

function buildOrderBatch($row, $items) {
    return [
        'id'            => $row['id'],
        'order_id'      => $row['order_id'],
        'status'        => $row['status'],
        'kds_status'    => $row['kds_status'],
        'created_at'    => $row['created_at'],
        'customer_name' => isset($row['customer_name']) ? $row['customer_name'] : 'Guest',
        'phone'         => isset($row['phone']) ? $row['phone'] : '',
        'station'       => $row['station'],
        'order_type'    => $row['order_type'],
        'table_id'      => $row['table_id'],
        'items'         => $items
    ];
}
?>
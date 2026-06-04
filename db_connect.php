<?php
define('DB_HOST','localhost'); define('DB_PORT','3306');
define('DB_NAME','noodlehaus'); define('DB_USER','root'); define('DB_PASS','');
define('DB_CHARSET','utf8mb4');
function getPDO(): PDO {
    static $pdo = null;
    if ($pdo !== null) { try { $pdo->query('SELECT 1'); } catch(PDOException $e) { $pdo=null; } }
    if ($pdo === null) {
        try {
            $pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',DB_HOST,DB_PORT,DB_NAME,DB_CHARSET),DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        } catch(PDOException $e) {
            file_put_contents(__DIR__.'/logs/errors.log','['.date('Y-m-d H:i:s').'] DB Connect failed: '.$e->getMessage().PHP_EOL,FILE_APPEND);
            http_response_code(503); header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Database unavailable']); exit;
        }
    }
    return $pdo;
}

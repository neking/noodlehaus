<?php
// GitHub Webhook Receiver for NoodleHaus auto-deploy
$secret = 'nh_webhook_2026';
$sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$body = file_get_contents('php://input');
$expected = 'sha256=' . hash_hmac('sha256', $body, $secret);
if (!hash_equals($expected, $sig)) {
    http_response_code(403);
    die('invalid signature');
}
$payload = json_decode($body, true);
if (($payload['ref'] ?? '') !== 'refs/heads/main') {
    echo 'not main branch, skipping';
    exit;
}
chdir('/var/www/html');
$out = shell_exec('git config --global --add safe.directory /var/www/html 2>&1 && git pull origin main 2>&1');
file_put_contents('/tmp/webhook_deploy.log', date('Y-m-d H:i:s') . "\n" . $out . "\n---\n", FILE_APPEND);
http_response_code(200);
echo 'deployed: ' . substr($out, 0, 100);

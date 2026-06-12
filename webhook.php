<?php
// NoodleHaus GitHub Webhook Auto-Deploy v2 (tested 02:31)
$secret = 'nh_webhook_2026';
$sig    = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$body   = file_get_contents('php://input');
if ($sig && !hash_equals('sha256='.hash_hmac('sha256',$body,$secret), $sig)) {
    http_response_code(403); die('invalid signature');
}
$payload = json_decode($body, true);
if (isset($payload['ref']) && $payload['ref'] !== 'refs/heads/main') {
    echo 'skipped: not main'; exit;
}
chdir('/var/www/html');
// Write safe.directory config for www-data without needing git config cmd
$home = posix_getpwuid(posix_getuid())['dir'] ?? '/var/www';
@file_put_contents($home.'/.gitconfig', "[safe]
	directory = /var/www/html
");
$out = shell_exec('HOME='.$home.' git pull origin main 2>&1');
$log = date('Y-m-d H:i:s')."\n".$out."\n---\n";
@file_put_contents('/tmp/webhook.log', $log, FILE_APPEND);
http_response_code(200);
echo 'ok: '.substr(trim($out),0,80);

<?php
$key = $_GET['k'] ?? '';
if ($key !== 'nh2026deploy') { http_response_code(403); die('forbidden'); }
chdir('/var/www/html');
// Fix safe.directory and pull
$out = shell_exec('git config --global --add safe.directory /var/www/html 2>&1 && git pull origin main 2>&1');
echo '<pre>' . htmlspecialchars($out) . '</pre>';

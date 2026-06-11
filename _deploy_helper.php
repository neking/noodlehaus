<?php
// Temporary deploy helper - delete after use
$key = $_GET['k'] ?? '';
if ($key !== 'nh2026deploy') { http_response_code(403); die('forbidden'); }
chdir('/var/www/html');
$out = shell_exec('git pull origin main 2>&1');
echo '<pre>' . htmlspecialchars($out) . '</pre>';
echo '<p>Done! You can delete this file now.</p>';

<?php
$key = $_GET['k'] ?? '';
if ($key !== 'nh2026deploy') { http_response_code(403); die('forbidden'); }

// Step 1: write git safe.directory config for www-data
$gitcfg = "[safe]
	directory = /var/www/html
";
$cfgWritten = file_put_contents('/var/www/.gitconfig', $gitcfg);

// Step 2: run git pull with explicit HOME
putenv('HOME=/var/www');
putenv('GIT_DIR=/var/www/html/.git');
putenv('GIT_WORK_TREE=/var/www/html');
$out = shell_exec('cd /var/www/html && HOME=/var/www git pull origin main 2>&1');
echo '<pre>' . htmlspecialchars($out) . '</pre>';
echo '<p>gitconfig written: ' . ($cfgWritten !== false ? 'yes' : 'failed') . '</p>';

<?php
declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'drawdream.org';
$_SERVER['HTTPS'] = 'on';

require __DIR__ . '/../includes/google_oauth.php';

$cfg = drawdream_google_oauth_config();
echo 'redirect_uri=' . $cfg['redirect_uri'] . PHP_EOL;
echo 'ready=' . (drawdream_google_oauth_is_ready() ? 'yes' : 'no') . PHP_EOL;

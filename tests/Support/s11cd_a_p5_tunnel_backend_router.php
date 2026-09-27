<?php

declare(strict_types=1);

$storagePath = getenv('LARAVEL_STORAGE_PATH');
if (is_string($storagePath) && $storagePath !== '') {
    $_ENV['LARAVEL_STORAGE_PATH'] = $storagePath;
    $_SERVER['LARAVEL_STORAGE_PATH'] = $storagePath;
}

$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_SCHEME'] = 'https';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
$_SERVER['HTTP_X_FORWARDED_PORT'] = '443';

return require __DIR__.'/s11cd_a_p5_browser_router.php';

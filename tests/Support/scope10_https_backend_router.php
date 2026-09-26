<?php

declare(strict_types=1);

$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = getenv('S10_HTTPS_PUBLIC_PORT') ?: '8443';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

return require dirname(__DIR__, 2).'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';

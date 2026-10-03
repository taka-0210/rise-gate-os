<?php

declare(strict_types=1);

$source = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'images'.DIRECTORY_SEPARATOR.'company-os-brand-symbol.svg';

if (! is_file($source) || ! is_readable($source)) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/svg+xml; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

readfile($source);

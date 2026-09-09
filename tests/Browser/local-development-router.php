<?php
$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$allowed = [
    '/tests/Browser/local-development.html' => ['tests/Browser/local-development.html', 'text/html; charset=utf-8'],
    '/public/js/local-development.js' => ['public/js/local-development.js', 'text/javascript; charset=utf-8'],
];
if (!isset($allowed[$path])) { http_response_code(404); exit; }
header('Content-Type: '.$allowed[$path][1]);
readfile($root.'/'.$allowed[$path][0]);

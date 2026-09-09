<?php

$root = dirname(__DIR__, 2);
$files = [
    '/tests/Browser/project-app-runtime.html' => ['tests/Browser/project-app-runtime.html', 'text/html'],
    '/public/js/project-app-runtime.js' => ['public/js/project-app-runtime.js', 'text/javascript'],
    '/resources/project-apps/todo.html' => ['resources/project-apps/todo.html', 'text/html'],
];
$entry = $files[parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)] ?? null;
if (! $entry) {
    http_response_code(404);
    exit;
}
header('Content-Type: '.$entry[1].'; charset=utf-8');
readfile($root.'/'.$entry[0]);

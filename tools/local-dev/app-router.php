<?php
// Development only. The working directory is the selected public document root.
$host = $_SERVER['HTTP_HOST'] ?? '';
if (!preg_match('/^127\.0\.0\.1:\d+$/', $host)) { http_response_code(403); exit; }
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if (str_contains($path, "\0") || str_contains($path, '\\') || preg_match('~(^|/)(\.|data(?:/|$)|private(?:/|$)|vendor(?:/|$))|\.(db|sqlite3?|log|ini|key|pem)(?:/|$)~i', $path)) {
    http_response_code(404); exit;
}
$file = realpath(getcwd().$path);
$root = realpath(getcwd());
if ($file && !str_starts_with(strtolower($file.DIRECTORY_SEPARATOR), strtolower($root.DIRECTORY_SEPARATOR))) { http_response_code(404); exit; }
header('Referrer-Policy: no-referrer');
// Development previews are embedded in the OS, which is a different site.
// Partition local cookies so iframe login works without allowing third-party tracking.
// This router is not exported; production keeps the application's own cookie policy.
header_register_callback(function (): void {
    $cookies = [];
    foreach (headers_list() as $header) {
        if (stripos($header, 'Set-Cookie:') === 0) {
            $cookie = preg_replace('/;\s*(SameSite=[^;]*|Secure|Partitioned)(?=;|$)/i', '', $header);
            $cookies[] = $cookie.'; SameSite=None; Secure; Partitioned';
        }
    }
    if ($cookies) {
        header_remove('Set-Cookie');
        foreach ($cookies as $cookie) header($cookie, false);
    }
});
if ($file && is_file($file)) return false;
if (is_file($root.'/index.php')) { require $root.'/index.php'; return true; }
if ($path === '/' && is_file($root.'/index.html')) { readfile($root.'/index.html'); return true; }
http_response_code(404);
echo 'ファイルがありません。AIに public/index.php または public/index.html の作成を依頼してください。';

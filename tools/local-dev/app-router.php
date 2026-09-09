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
// Only the trusted setup origins may embed a development app; never trust request headers.
$previewSettings = json_decode((string) @file_get_contents(dirname(__DIR__).'/config.json'), true);
$previewOrigins = $previewSettings['origins']['value'] ?? $previewSettings['origins'] ?? ['https://os.rise-gate.com'];
$previewOrigins = is_array($previewOrigins) ? array_values(array_filter($previewOrigins, static function ($origin): bool {
    if (!is_string($origin) || !preg_match('~^https?://[a-z0-9.-]+(?::[0-9]{1,5})?$~iD', $origin)) return false;
    $parts = parse_url($origin);
    return ($parts['scheme'] ?? '') === 'https' || in_array($parts['host'] ?? '', ['localhost','127.0.0.1'], true);
})) : [];
$frameAncestors = 'frame-ancestors '.($previewOrigins ? implode(' ', $previewOrigins) : "'none'");
header_register_callback(function () use ($frameAncestors): void {
    $policies = [];
    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Security-Policy:') !== 0) continue;
        foreach (explode(',', substr($header, strlen('Content-Security-Policy:'))) as $policy) {
            $directives = array_filter(array_map('trim', explode(';', $policy)), static fn ($directive) => $directive !== '' && !preg_match('/^frame-ancestors(?:\s|$)/i', $directive));
            $policies[] = implode('; ', [...$directives, $frameAncestors]);
        }
    }
    // CSP frame-ancestors replaces DENY/SAMEORIGIN only in this non-exported development router.
    header_remove('X-Frame-Options');
    header_remove('Content-Security-Policy');
    foreach ($policies ?: [$frameAncestors] as $policy) header('Content-Security-Policy: '.$policy, false);
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

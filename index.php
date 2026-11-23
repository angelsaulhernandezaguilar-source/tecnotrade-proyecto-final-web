<?php
declare(strict_types=1);
require_once __DIR__ . '/server/common.php';
securityHeaders();
$base = basePath();
$requested = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$relative = substr($requested, 0, strlen($base)) === $base ? substr($requested, strlen($base)) : '';
if (str_starts_with($relative, 'api/') || str_starts_with($relative, 'media/')) {
    $_GET['path'] = '/' . $relative . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING']);
    require __DIR__ . '/api.php';
    exit;
}
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) { http_response_code(405); exit; }
$file = $_GET['file'] ?? ($relative === '' || $relative === 'index.php' ? 'index.html' : $relative);
if (!is_string($file) || str_contains($file, "\0") || str_contains($file, '\\') || preg_match('~(^|/)\.~', $file)) {
    http_response_code(404); exit;
}
$allowed = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'];
$root = realpath(__DIR__ . '/public');
$real = realpath(__DIR__ . '/public/' . $file);
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if (!$root || !$real || !is_file($real) || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || !isset($allowed[$ext])) {
    http_response_code(404); exit;
}
header('Content-Type: ' . $allowed[$ext]);
// La presentación se descarga como archivo en lugar de abrirse en el navegador.
if ($ext === 'pptx') header('Content-Disposition: attachment; filename="' . basename($real) . '"');
header($ext === 'html' ? 'Cache-Control: no-store' : 'Cache-Control: no-cache');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    if ($ext === 'html') {
        $html = file_get_contents($real);
        echo str_replace('{{TT_BASE_PATH}}', htmlspecialchars($base, ENT_QUOTES, 'UTF-8'), $html);
    } else readfile($real);
}

<?php
// Enrutador del servidor integrado de PHP (lo usa iniciar.bat).
//
// Apache protege las carpetas privadas con .htaccess, pero el servidor
// integrado de PHP no lee .htaccess. Este archivo aplica las mismas reglas:
// niega el acceso a código, base de datos, pruebas y documentos, y deja pasar
// todo lo demás (index.php, api.php, instalar.php y sus recursos).
declare(strict_types=1);

$ruta = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$ruta = str_replace('\\', '/', rawurldecode(is_string($ruta) ? $ruta : '/'));

$privada = preg_match('~^/+(?:server|database|tests|docs|php|frontend|local)(?:/|$)~i', $ruta)
    || preg_match('~(?:^|/)\.~', $ruta)
    || preg_match('~\.(?:md|txt|sql|mmd|sqlite|bak|zip|log|bat|ini|json|py|lock)$~i', $ruta);

if ($privada) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Acceso denegado.';
    return true;
}

return false; // PHP atiende la solicitud de forma normal

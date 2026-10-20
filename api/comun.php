<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function responder(array $datos, int $codigo = 200): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function error_api(string $mensaje, int $codigo): never
{
    responder(['estado' => 'error', 'mensaje' => $mensaje], $codigo);
}

function iniciar_sesion_api(): string
{
    abrir_sesion_api();
    $token = $_SESSION['aa23_csrf'];
    session_write_close();
    return $token;
}

function abrir_sesion_api(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    ini_set('session.use_strict_mode', '1');
    // Cookie propia de AA23 para separar las sesiones de otros proyectos.
    session_name('aa23_login');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
    if (empty($_SESSION['aa23_csrf'])) {
        $_SESSION['aa23_csrf'] = bin2hex(random_bytes(32));
    }
}

function exigir_metodo(string $metodo): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $metodo) {
        header('Allow: ' . $metodo);
        error_api('Este endpoint acepta únicamente ' . $metodo . '.', 405);
    }
}

function requerir_usuario(): int
{
    abrir_sesion_api();
    if (!isset($_SESSION['usuario_id'])) {
        responder(['estado' => 'error', 'mensaje' => 'Iniciá sesión para continuar.',
            'csrf_token' => $_SESSION['aa23_csrf']], 401);
    }
    $id = (int) $_SESSION['usuario_id'];
    session_write_close();
    return $id;
}

function leer_json_post(): object
{
    exigir_metodo('POST');
    $tipo = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($tipo !== 'application/json') {
        error_api('Enviá los datos como application/json.', 415);
    }
    $token = iniciar_sesion_api();
    $recibido = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($recibido) || !hash_equals($token, $recibido)) {
        error_api('El formulario venció. Volvé a intentarlo.', 403);
    }
    $cuerpo = file_get_contents('php://input', false, null, 0, 8193);
    if ($cuerpo === false || strlen($cuerpo) > 8192) {
        error_api('El cuerpo de la petición es demasiado grande.', 413);
    }
    try {
        $datos = json_decode($cuerpo, false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        error_api('El JSON no es válido.', 400);
    }
    if (!is_object($datos)) { error_api('Enviá un objeto JSON.', 400); }
    return $datos;
}

function leer_email(object $datos): string
{
    if (!isset($datos->email) || !is_string($datos->email)) {
        error_api('Ingresá un correo electrónico válido.', 422);
    }
    $email = trim($datos->email);
    if (strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        error_api('Ingresá un correo electrónico válido de hasta 100 caracteres.', 422);
    }
    return strtolower($email);
}

function destruir_sesion_api(): void
{
    abrir_sesion_api();
    $_SESSION = [];
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => $cookie['path'],
        'domain' => $cookie['domain'],
        'secure' => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => $cookie['samesite'],
    ]);
    session_destroy();
}

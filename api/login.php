<?php
require_once __DIR__ . '/comun.php';
$datos = leer_json_post();
$email = leer_email($datos);
$passwordUsuario = $datos->password ?? null;
if (!is_string($passwordUsuario) || $passwordUsuario === '' || strlen($passwordUsuario) > 72 || str_contains($passwordUsuario, "\0")) {
    error_api('Correo o contraseña incorrectos.', 401);
}

try {
    require __DIR__ . '/../config/conexion.php';
    $statement = $pdo->prepare('SELECT id, password FROM usuarios WHERE email = :email LIMIT 1');
    $statement->execute(['email' => $email]);
    $usuario = $statement->fetch();
    // Hash ficticio con el mismo coste para correos que no están registrados.
    $ficticio = '$2y$12$9TJWvSy.grcK.VYT05SyWughAZC2lImcfX2pxmpVh1yD9CmY3zNbC';
    $hash = $usuario && password_get_info($usuario['password'])['algoName'] === 'bcrypt'
        ? $usuario['password'] : $ficticio;
    $valida = password_verify($passwordUsuario, $hash);
    if (!$usuario || $hash === $ficticio || !$valida) {
        error_api('Correo o contraseña incorrectos.', 401);
    }

    abrir_sesion_api();
    session_regenerate_id(true);
    $_SESSION['usuario_id'] = (int) $usuario['id'];
    $_SESSION['aa23_csrf'] = bin2hex(random_bytes(32));
    session_write_close();
    responder(['estado' => 'exito', 'mensaje' => 'Sesión iniciada.']);
} catch (PDOException $e) {
    error_log($e->getMessage());
    error_api('No se pudo iniciar sesión.', 503);
}

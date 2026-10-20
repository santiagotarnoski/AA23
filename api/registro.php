<?php
require_once __DIR__ . '/comun.php';
$datos = leer_json_post();
$email = leer_email($datos);
$nombre = $datos->nombre ?? null;
$passwordUsuario = $datos->password ?? null;

if (!is_string($nombre) || !mb_check_encoding($nombre, 'UTF-8')
    || trim($nombre) === '' || mb_strlen(trim($nombre), 'UTF-8') > 100) {
    error_api('El nombre es obligatorio y admite hasta 100 caracteres.', 422);
}
$nombre = trim($nombre);
if (!is_string($passwordUsuario) || !mb_check_encoding($passwordUsuario, 'UTF-8')
    || mb_strlen($passwordUsuario, 'UTF-8') < 8 || strlen($passwordUsuario) > 72 || str_contains($passwordUsuario, "\0")) {
    error_api('La contraseña debe tener al menos 8 caracteres y no superar 72 bytes.', 422);
}

try {
    require __DIR__ . '/../config/conexion.php';
    $hash = password_hash($passwordUsuario, PASSWORD_BCRYPT, ['cost' => 12]);
    $statement = $pdo->prepare('INSERT INTO usuarios (nombre, email, password) VALUES (:nombre, :email, :password)');
    $statement->execute(['nombre' => $nombre, 'email' => $email, 'password' => $hash]);
    responder(['estado' => 'exito', 'mensaje' => 'Cuenta creada. Iniciá sesión con tu correo y contraseña.'], 201);
} catch (PDOException $e) {
    if ($e->getCode() === '23000' && ($e->errorInfo[1] ?? null) === 1062) {
        error_api('El correo ya está registrado.', 409);
    }
    error_log($e->getMessage());
    error_api('No se pudo registrar la cuenta.', 503);
}

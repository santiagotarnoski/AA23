<?php
require_once __DIR__ . '/comun.php';
$usuarioId = requerir_usuario();
exigir_metodo('GET');

try {
    require __DIR__ . '/../config/conexion.php';
    $statement = $pdo->prepare('SELECT id, nombre, email, rol FROM usuarios WHERE id = :id LIMIT 1');
    $statement->bindValue(':id', $usuarioId, PDO::PARAM_INT);
    $statement->execute();
    $usuario = $statement->fetch();
    if (!$usuario) {
        destruir_sesion_api();
        responder(['estado' => 'error', 'mensaje' => 'Iniciá sesión para continuar.',
            'csrf_token' => iniciar_sesion_api()], 401);
    }
    responder(['estado' => 'exito', 'data' => $usuario, 'csrf_token' => iniciar_sesion_api()]);
} catch (PDOException $e) {
    error_log($e->getMessage());
    error_api('No se pudo consultar el perfil.', 503);
}

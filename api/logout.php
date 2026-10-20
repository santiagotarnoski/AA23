<?php
require_once __DIR__ . '/comun.php';
requerir_usuario();
leer_json_post();
destruir_sesion_api();
responder(['estado' => 'exito', 'mensaje' => 'Sesión cerrada.']);

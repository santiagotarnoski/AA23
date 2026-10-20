<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo consola.'); }

$proyecto = dirname(__DIR__);
$config = require $proyecto . '/config/config.example.php';
if (is_file($proyecto . '/config/local.php')) { $config = array_replace($config, require $proyecto . '/config/local.php'); }
$admin = new PDO("mysql:host={$config['host']};port={$config['port']};charset=utf8mb4", $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$base = 'aa23_test_' . bin2hex(random_bytes(6));
$temporal = rtrim(getenv('AA23_TEST_DIR') ?: sys_get_temp_dir(), '/\\') . '/' . $base;
$creada = false;
$servidor = null;
$cliente = null;
$checks = 0;

function comprobar(bool $correcto, string $mensaje): void
{
    global $checks;
    if (!$correcto) { throw new RuntimeException('Falló: ' . $mensaje); }
    $checks++;
    echo "OK: $mensaje\n";
}

function copiar_carpeta(string $origen, string $destino): void
{
    if (!is_dir($destino)) { mkdir($destino, 0777, true); }
    foreach (new DirectoryIterator($origen) as $archivo) {
        if ($archivo->isDot() || $archivo->getFilename() === 'local.php') continue;
        $ruta = $destino . '/' . $archivo->getFilename();
        if ($archivo->isDir()) copiar_carpeta($archivo->getPathname(), $ruta);
        else copy($archivo->getPathname(), $ruta);
    }
}

function cliente_nuevo(): CurlHandle
{
    $cliente = curl_init();
    curl_setopt_array($cliente, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 10]);
    return $cliente;
}

function pedir(string $ruta, string $metodo = 'GET', ?string $cuerpo = null, array $headers = []): array
{
    global $cliente, $url;
    curl_setopt($cliente, CURLOPT_URL, $url . $ruta);
    curl_setopt($cliente, CURLOPT_HTTPHEADER, array_merge(['Accept: application/json'], $headers));
    if ($metodo === 'GET') curl_setopt($cliente, CURLOPT_HTTPGET, true);
    else curl_setopt($cliente, CURLOPT_POSTFIELDS, $cuerpo ?? '');
    curl_setopt($cliente, CURLOPT_CUSTOMREQUEST, $metodo);
    $texto = curl_exec($cliente);
    if ($texto === false) throw new RuntimeException(curl_error($cliente));
    return [curl_getinfo($cliente, CURLINFO_HTTP_CODE), json_decode($texto, true), $texto];
}

function cookie_sesion(): string
{
    global $cliente;
    foreach (curl_getinfo($cliente, CURLINFO_COOKIELIST) as $cookie) {
        $partes = explode("\t", $cookie);
        if (($partes[5] ?? '') === 'aa23_login') return $partes[6];
    }
    return '';
}

function probar_cookie(string $cookie): int
{
    global $cliente;
    $principal = $cliente;
    $cliente = cliente_nuevo();
    try { return pedir('/api/perfil.php', 'GET', null, ['Cookie: ' . $cookie])[0]; }
    finally { curl_close($cliente); $cliente = $principal; }
}

try {
    $admin->exec("CREATE DATABASE `$base` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $creada = true;
    $admin->exec("USE `$base`");
    $sql = file_get_contents($proyecto . '/sql/usuarios.sql');
    $inicioTabla = strpos($sql, 'CREATE TABLE IF NOT EXISTS usuarios');
    if ($inicioTabla === false) throw new RuntimeException('No se encontró el esquema de usuarios.');
    $admin->exec(substr($sql, $inicioTabla));
    comprobar($admin->query("SHOW COLUMNS FROM usuarios LIKE 'password'")->fetch(PDO::FETCH_ASSOC)['Type'] === 'varchar(255)', 'password es VARCHAR(255)');

    mkdir($temporal . '/sesiones', 0777, true);
    foreach (['api', 'config', 'css', 'js'] as $carpeta) copiar_carpeta($proyecto . '/' . $carpeta, $temporal . '/app/' . $carpeta);
    copy($proyecto . '/index.html', $temporal . '/app/index.html');
    $config['db'] = $base;
    file_put_contents($temporal . '/app/config/local.php', "<?php\nreturn " . var_export($config, true) . ";\n");
    $socket = stream_socket_server('tcp://127.0.0.1:0', $codigo, $mensaje);
    if (!$socket) throw new RuntimeException('No se pudo reservar un puerto.');
    $direccion = stream_socket_get_name($socket, false);
    fclose($socket);
    $url = 'http://' . $direccion;
    $servidor = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'session.save_path=' . $temporal . '/sesiones', '-S', $direccion, '-t', $temporal . '/app'],
        [0 => ['pipe', 'r'], 1 => ['file', $temporal . '/servidor.log', 'a'], 2 => ['file', $temporal . '/servidor.log', 'a']], $pipes, $temporal);
    if (!is_resource($servidor)) throw new RuntimeException('No se pudo iniciar la prueba.');
    fclose($pipes[0]);
    $listo = false;
    for ($intento = 0; $intento < 50; $intento++) {
        $socket = @stream_socket_client('tcp://' . $direccion, $codigo, $mensaje, .1);
        if ($socket) { fclose($socket); $listo = true; break; }
        usleep(100000);
    }
    if (!$listo) throw new RuntimeException('El servidor de prueba no respondió.');
    $cliente = cliente_nuevo();
    [$estado, $datos] = pedir('/api/perfil.php');
    comprobar($estado === 401 && !isset($datos['data']) && strlen($datos['csrf_token']) === 64, 'perfil sin sesión devuelve 401');
    $tokenInvitado = $datos['csrf_token'];
    $cookieInvitado = cookie_sesion();
    comprobar($cookieInvitado !== '', 'AA23 tiene su propia cookie de sesión');
    $headers = ['Content-Type: application/json', 'X-CSRF-Token: ' . $tokenInvitado];
    [$estado] = pedir('/api/registro.php');
    comprobar($estado === 405, 'registro solo permite POST');
    [$estado] = pedir('/api/login.php');
    comprobar($estado === 405, 'login solo permite POST');
    [$estado] = pedir('/api/registro.php', 'POST', '{}', ['Content-Type: application/json']);
    comprobar($estado === 403, 'registro exige CSRF');
    [$estado] = pedir('/api/registro.php', 'POST', '{}', ['Content-Type: text/plain']);
    comprobar($estado === 415, 'registro exige JSON');
    foreach (['{', '[]'] as $jsonInvalido) {
        [$estado] = pedir('/api/registro.php', 'POST', $jsonInvalido, $headers);
        comprobar($estado === 400, 'registro rechaza JSON inválido o sin objeto');
    }
    [$estado] = pedir('/api/registro.php', 'POST', str_repeat('a', 8193), $headers);
    comprobar($estado === 413, 'cuerpo demasiado grande rechazado');

    $cuenta = ['nombre' => "Ana O'Reilly <img src=x onerror=alert(1)>", 'email' => 'ana@example.test', 'password' => 'Clave segura 2026', 'rol' => 'admin'];
    foreach ([['nombre' => ''], ['nombre' => str_repeat('ñ', 101)], ['email' => 'no-es-correo'], ['email' => []], ['password' => 'corta'], ['password' => str_repeat('a', 73)], ['password' => "clave\0incorrecta"]] as $cambio) {
        [$estado] = pedir('/api/registro.php', 'POST', json_encode(array_replace($cuenta, $cambio)), $headers);
        comprobar($estado === 422, 'validación del campo ' . array_key_first($cambio));
    }
    comprobar((int) $admin->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() === 0, 'las peticiones inválidas no crearon cuentas');
    [$estado] = pedir('/api/registro.php', 'POST', json_encode($cuenta), $headers);
    comprobar($estado === 201, 'registro JSON correcto');
    $consulta = $admin->prepare('SELECT id, nombre, password, rol FROM usuarios WHERE email = ?');
    $consulta->execute([$cuenta['email']]);
    $usuario = $consulta->fetch(PDO::FETCH_ASSOC);
    comprobar(password_get_info($usuario['password'])['algoName'] === 'bcrypt' && password_verify($cuenta['password'], $usuario['password']), 'la contraseña se almacena como BCRYPT verificable');
    comprobar($usuario['nombre'] === $cuenta['nombre'], 'nombre con comillas y HTML conservado sin doble codificación');
    comprobar($usuario['rol'] === 'cliente', 'el cliente no puede asignarse el rol admin');
    [$estado] = pedir('/api/registro.php', 'POST', json_encode($cuenta), $headers);
    comprobar($estado === 409, 'correo duplicado rechazado');
    [$estado] = pedir('/api/perfil.php');
    comprobar($estado === 401, 'el registro no sustituye al login');
    [$estado, $fallo] = pedir('/api/login.php', 'POST', json_encode(['email' => $cuenta['email'], 'password' => 'incorrecta']), $headers);
    comprobar($estado === 401, 'contraseña incorrecta rechazada');
    [$estado, $desconocido] = pedir('/api/login.php', 'POST', json_encode(['email' => 'nadie@example.test', 'password' => 'incorrecta']), $headers);
    comprobar($estado === 401 && $fallo['mensaje'] === $desconocido['mensaje'], 'login no revela si el correo existe');
    [$estado] = pedir('/api/login.php', 'POST', json_encode(['email' => $cuenta['email'], 'password' => "' OR 1=1 --"]), $headers);
    comprobar($estado === 401, 'una inyección no permite iniciar sesión');
    [$estado] = pedir('/api/login.php', 'POST', json_encode(['email' => $cuenta['email'], 'password' => $usuario['password']]), $headers);
    comprobar($estado === 401, 'el hash no puede usarse como contraseña');
    [$estado] = pedir('/api/login.php', 'POST', json_encode($cuenta), $headers);
    comprobar($estado === 200, 'login con credenciales válidas');
    $cookieAutenticada = cookie_sesion();
    comprobar($cookieAutenticada !== '' && $cookieAutenticada !== $cookieInvitado, 'se regenera el identificador al ingresar');
    comprobar(str_contains(file_get_contents($temporal . '/sesiones/sess_' . $cookieAutenticada), 'usuario_id|i:' . $usuario['id'] . ';'), 'usuario_id se guarda en la sesión del servidor');
    [$estado, $perfil] = pedir('/api/perfil.php');
    comprobar($estado === 200 && $perfil['data']['nombre'] === $cuenta['nombre'] && $perfil['data']['email'] === $cuenta['email'] && $perfil['data']['rol'] === 'cliente', 'perfil devuelve nombre, correo y rol después del login');
    comprobar(!isset($perfil['data']['password']) && !str_contains(json_encode($perfil), $usuario['password']), 'el perfil no expone contraseñas ni hashes');
    comprobar($perfil['csrf_token'] !== $tokenInvitado, 'CSRF renovado junto con la sesión');
    comprobar(probar_cookie('aa23_login=' . $cookieInvitado) === 401, 'la cookie anterior al login no da acceso');
    comprobar(probar_cookie('buscador_seguro=' . $cookieAutenticada) === 401, 'las cookies de AA21 no autentican en AA23');
    $headers = ['Content-Type: application/json', 'X-CSRF-Token: ' . $perfil['csrf_token']];
    [$estado] = pedir('/api/perfil.php', 'POST', '{}', $headers);
    comprobar($estado === 405, 'perfil autenticado solo acepta GET');
    [$estado] = pedir('/api/logout.php');
    comprobar($estado === 405, 'logout no se ejecuta mediante GET');
    [$estado] = pedir('/api/logout.php', 'POST', '{}', ['Content-Type: application/json', 'X-CSRF-Token: ' . $tokenInvitado]);
    comprobar($estado === 403, 'token anterior no permite cerrar la sesión');
    [$estado] = pedir('/api/logout.php', 'POST', '{}', $headers);
    comprobar($estado === 200, 'logout correcto');
    [$estado] = pedir('/api/perfil.php');
    comprobar($estado === 401, 'perfil protegido después del logout');
    comprobar(probar_cookie('aa23_login=' . $cookieAutenticada) === 401, 'la cookie autenticada deja de servir al cerrar sesión');
    echo "\n$checks verificaciones correctas. Base y puerto de prueba independientes.\n";
} finally {
    if ($cliente) curl_close($cliente);
    if (is_resource($servidor)) { proc_terminate($servidor); proc_close($servidor); }
    if ($creada && preg_match('/\Aaa23_test_[a-f0-9]{12}\z/', $base)) $admin->exec("DROP DATABASE `$base`");
    if (is_file($temporal . '/app/config/local.php')) unlink($temporal . '/app/config/local.php');
}

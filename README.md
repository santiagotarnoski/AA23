# AA23 - Autenticación con PHP y sesiones

Se trabajó sobre el proyecto php-login-rest entregado en clase para implementar registro, inicio de sesión y un perfil privado. La base de datos se llama `aa23_login` y la tabla `usuarios` guarda nombre, correo, contraseña y rol. El campo `password` es `VARCHAR(255)`.

El registro recibe los datos en JSON, valida los campos y guarda la contraseña con `password_hash()` y `PASSWORD_BCRYPT`. Las consultas e inserciones se realizan con PDO y parámetros preparados. El login verifica la contraseña con `password_verify()`, inicia la sesión, guarda el identificador en `$_SESSION['usuario_id']` y renueva la cookie con `session_regenerate_id(true)`.

El endpoint `api/perfil.php` comprueba la sesión antes de devolver los datos. Si el usuario no ingresó, responde con HTTP 401. El cierre de sesión elimina los datos guardados en el servidor y la cookie. Los formularios usan un token CSRF.

La interfaz usa JavaScript Vanilla y `fetch`. Al abrir la página consulta el perfil y muestra el formulario de ingreso o el panel según la respuesta. El nombre, correo, rol y mensajes se colocan con `textContent`, de modo que un texto con etiquetas HTML no se ejecute como código.

Se probaron el registro, los correos duplicados, las contraseñas incorrectas, los hashes BCRYPT, la renovación de sesión, los accesos sin autorización y el logout. También se corrigieron la carga del correo al recuperar el perfil y la navegación para registrar otra cuenta desde el panel.

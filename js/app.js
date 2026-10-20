'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const navPublico = document.querySelector('#nav-publico');
    const vistaLogin = document.querySelector('#vista-login');
    const vistaRegistro = document.querySelector('#vista-registro');
    const vistaPanel = document.querySelector('#vista-panel');
    const formLogin = document.querySelector('#form-login');
    const formRegistro = document.querySelector('#form-registro');
    const msgLogin = document.querySelector('#msg-login');
    const msgRegistro = document.querySelector('#msg-registro');
    const estadoSesion = document.querySelector('#estado-sesion');
    const tabLogin = document.querySelector('#tab-login');
    const tabRegistro = document.querySelector('#tab-registro');
    const btnLogout = document.querySelector('#btn-logout');
    const btnVolver = document.querySelector('#btn-volver-panel');
    const btnNuevo = document.querySelector('#btn-abrir-registro-panel');
    let usuarioActual = null;
    let csrfToken = '';
    let ocupado = true;

    function bloquear() {
        for (const boton of document.querySelectorAll('button')) {
            boton.disabled = ocupado || !csrfToken;
        }
    }

    function mostrarMensaje(elemento, texto, tipo) {
        elemento.textContent = texto;
        elemento.className = 'mensaje-box ' + tipo;
    }

    function mostrarVista(vista) {
        if (usuarioActual && vista === 'login') vista = 'panel';
        if (!usuarioActual && vista === 'panel') vista = 'login';
        navPublico.classList.toggle('oculto', Boolean(usuarioActual));
        vistaLogin.classList.toggle('oculto', vista !== 'login');
        vistaRegistro.classList.toggle('oculto', vista !== 'registro');
        vistaPanel.classList.toggle('oculto', vista !== 'panel');
        btnVolver.classList.toggle('oculto', !usuarioActual || vista !== 'registro');
        tabLogin.classList.toggle('active', vista === 'login');
        tabRegistro.classList.toggle('active', vista === 'registro');
        // El contenido de MySQL se coloca como texto, nunca como HTML.
        document.querySelector('#user-nombre').textContent = usuarioActual?.nombre ?? '';
        document.querySelector('#user-email').textContent = usuarioActual?.email ?? '';
        document.querySelector('#user-rol').textContent = usuarioActual?.rol ?? '';
    }

    async function leerRespuesta(respuesta) {
        const datos = await respuesta.json();
        if (!respuesta.ok || datos.estado !== 'exito') {
            const error = new Error(datos.mensaje || 'No se pudo completar la operación.');
            error.codigo = respuesta.status;
            throw error;
        }
        return datos;
    }

    async function comprobarSesion(vistaPreferida = 'login') {
        try {
            const respuesta = await fetch('api/perfil.php', {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' },
            });
            if (respuesta.status === 401) {
                const datos = await respuesta.json();
                usuarioActual = null;
                csrfToken = datos.csrf_token || '';
                mostrarVista(vistaPreferida === 'registro' ? 'registro' : 'login');
                estadoSesion.textContent = 'Ingresá o registrá una cuenta para acceder al perfil.';
            } else {
                const datos = await leerRespuesta(respuesta);
                usuarioActual = datos.data;
                csrfToken = datos.csrf_token;
                mostrarVista(vistaPreferida === 'registro' ? 'registro' : 'panel');
                estadoSesion.textContent = 'Sesión activa.';
            }
            return true;
        } catch (error) {
            usuarioActual = null;
            csrfToken = '';
            mostrarVista('login');
            estadoSesion.textContent = 'No se pudo comprobar la sesión. Actualizá la página para intentar otra vez.';
            return false;
        } finally {
            bloquear();
        }
    }

    async function enviarJson(endpoint, datos) {
        const respuesta = await fetch('api/' + endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                Accept: 'application/json',
            },
            body: JSON.stringify(datos),
        });
        return leerRespuesta(respuesta);
    }

    tabLogin.addEventListener('click', () => mostrarVista('login'));
    tabRegistro.addEventListener('click', () => mostrarVista('registro'));
    btnNuevo.addEventListener('click', () => mostrarVista('registro'));
    btnVolver.addEventListener('click', () => mostrarVista('panel'));

    formLogin.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        if (ocupado || !csrfToken) return;
        ocupado = true;
        bloquear();
        mostrarMensaje(msgLogin, 'Iniciando sesión…', 'info');
        try {
            await enviarJson('login.php', {
                email: document.querySelector('#login-email').value.trim(),
                password: document.querySelector('#login-pass').value,
            });
            formLogin.reset();
            msgLogin.textContent = '';
            msgLogin.className = 'mensaje-box';
            await comprobarSesion();
        } catch (error) {
            if (error.codigo === 403) await comprobarSesion();
            mostrarMensaje(msgLogin, error instanceof TypeError ? 'No se pudo conectar con el servidor.' : error.message, 'error');
        } finally {
            ocupado = false;
            bloquear();
        }
    });

    formRegistro.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        if (ocupado || !csrfToken) return;
        ocupado = true;
        bloquear();
        mostrarMensaje(msgRegistro, 'Creando cuenta…', 'info');
        const datos = {
            nombre: document.querySelector('#reg-nombre').value.trim(),
            email: document.querySelector('#reg-email').value.trim(),
            password: document.querySelector('#reg-pass').value,
        };
        try {
            await enviarJson('registro.php', datos);
            formRegistro.reset();
            if (!usuarioActual) document.querySelector('#login-email').value = datos.email;
            mostrarMensaje(msgRegistro, usuarioActual
                ? 'Cuenta creada. Tu sesión actual sigue abierta.'
                : 'Cuenta creada. Ya podés iniciar sesión con tu correo y contraseña.', 'exito');
        } catch (error) {
            if (error.codigo === 403) await comprobarSesion('registro');
            mostrarMensaje(msgRegistro, error instanceof TypeError ? 'No se pudo conectar con el servidor.' : error.message, 'error');
        } finally {
            ocupado = false;
            bloquear();
        }
    });

    btnLogout.addEventListener('click', async () => {
        if (ocupado || !usuarioActual) return;
        ocupado = true;
        bloquear();
        try {
            await enviarJson('logout.php', {});
            formLogin.reset();
            formRegistro.reset();
            msgRegistro.textContent = '';
            msgRegistro.className = 'mensaje-box';
            await comprobarSesion();
            mostrarMensaje(msgLogin, 'Sesión cerrada correctamente.', 'info');
        } catch (error) {
            if (error.codigo === 401 || error.codigo === 403) await comprobarSesion();
            mostrarMensaje(msgLogin, error instanceof TypeError ? 'No se pudo conectar con el servidor.' : error.message, 'error');
        } finally {
            ocupado = false;
            bloquear();
        }
    });

    bloquear();
    comprobarSesion().finally(() => {
        ocupado = false;
        bloquear();
    });
});

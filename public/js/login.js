// Lógica interactiva para la pantalla de inicio de sesión (login.php)

/**
 * Cierra y desvanece una alerta con animación.
 *
 * @param {HTMLElement|null} el Elemento contenedor de alerta.
 */
function cerrarAlerta(el) {
    if (!el) return;
    el.classList.add('oculta');
    setTimeout(() => {
        el.style.display = 'none';
        el.classList.remove('oculta');
    }, 450);
}

/**
 * Programa el cierre automático de una alerta tras N milisegundos.
 *
 * @param {HTMLElement|null} el Elemento contenedor de alerta.
 * @param {number} [delayMs=3500] Tiempo en milisegundos.
 * @returns {number|null}
 */
function autoDesaparecerAlerta(el, delayMs = 3500) {
    if (!el) return null;
    return setTimeout(() => {
        cerrarAlerta(el);
    }, delayMs);
}

window.cerrarAlerta = cerrarAlerta;

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('form-login');
    const inputUsuario = document.getElementById('usuario');
    const inputClave = document.getElementById('clave');
    const btnToggleClave = document.getElementById('btn-toggle-clave');
    const btnSubmit = document.getElementById('btn-submit');
    const btnTexto = document.getElementById('btn-texto');
    const divError = document.getElementById('mensaje-error');
    const divErrorTexto = document.getElementById('mensaje-error-texto');
    let timerError = null;

    // Si vino error renderizado desde PHP, hacerlo desaparecer a los 1.5s
    if (divError && divError.style.display !== 'none' && divErrorTexto && divErrorTexto.textContent.trim() !== '') {
        timerError = autoDesaparecerAlerta(divError, 1500);
    }

    // Al escribir en usuario o clave, ocultar el error inmediatamente
    [inputUsuario, inputClave].filter(Boolean).forEach(input => {
        input.addEventListener('input', () => {
            if (divError && divError.style.display !== 'none') {
                if (timerError) clearTimeout(timerError);
                cerrarAlerta(divError);
            }
        });
    });

    // Alternar visibilidad de contraseña
    if (btnToggleClave && inputClave) {
        btnToggleClave.addEventListener('click', function() {
            const tipoActual = inputClave.getAttribute('type');
            if (tipoActual === 'password') {
                inputClave.setAttribute('type', 'text');
                btnToggleClave.textContent = '🔒';
            } else {
                inputClave.setAttribute('type', 'password');
                btnToggleClave.textContent = '👁️‍🗨️';
            }
        });
    }

    // Envío asíncrono para almacenamiento seguro de sesión y cookie en el servidor
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const usuario = inputUsuario ? inputUsuario.value.trim().toUpperCase() : '';
            const clave = inputClave ? inputClave.value : '';

            if (!usuario || !clave) {
                mostrarError('Por favor complete todos los campos.');
                return;
            }

            if (btnSubmit) btnSubmit.disabled = true;
            if (btnTexto) btnTexto.textContent = 'Validando credenciales...';
            if (timerError) clearTimeout(timerError);
            if (divError) divError.style.display = 'none';

            const formData = new FormData(form);
            formData.append('formato', 'json');

            fetch('login.php', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(response => {
                return response.json().then(data => ({
                    status: response.status,
                    data: data
                })).catch(() => ({
                    status: response.status,
                    data: { exito: false, error: 'Respuesta inválida del servidor.' }
                }));
            })
            .then(({ data }) => {
                if (data && data.exito) {
                    // Limpiar cualquier residuo de credenciales o tokens en el cliente
                    try {
                        localStorage.removeItem('auth_token');
                        sessionStorage.removeItem('auth_token');
                        localStorage.removeItem('auth_user');
                    } catch (_) {}

                    if (btnTexto) btnTexto.textContent = '¡Acceso concedido!';
                    window.location.href = data.redirigir || 'index.php';
                } else {
                    mostrarError((data && data.error) ? data.error : 'Usuario o contraseña incorrectos.');
                    if (btnSubmit) btnSubmit.disabled = false;
                    if (btnTexto) btnTexto.textContent = 'Ingresar';
                    if (inputClave) {
                        inputClave.value = '';
                        inputClave.focus();
                    }
                }
            })
            .catch(() => {
                mostrarError('Error de conexión al servidor. Inténtalo nuevamente.');
                if (btnSubmit) btnSubmit.disabled = false;
                if (btnTexto) btnTexto.textContent = 'Ingresar';
            });
        });
    }

    function mostrarError(mensaje) {
        if (!divError || !divErrorTexto) return;
        if (timerError) clearTimeout(timerError);
        divErrorTexto.textContent = mensaje;
        divError.classList.remove('oculta');
        divError.style.display = 'flex';

        // Desaparece automáticamente después de 3.0 segundos
        timerError = autoDesaparecerAlerta(divError, 3000);
    }
});


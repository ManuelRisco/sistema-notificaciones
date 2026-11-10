<?php

declare(strict_types=1);

const PAGINA_PUBLICA = true;

require_once __DIR__ . '/seguridad.php';

// Si el usuario ya está autenticado, redirigir directamente al panel principal
if (estaAutenticado()) {
    header('Location: index.php');
    exit;
}

// Detectar si hay una cookie de modo oscuro activa (compartida con index, crear, editar)
$darkMode = isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'true';

// Alternar modo oscuro si se recibe por GET
if (isset($_GET['toggle_dark_mode'])) {
    $darkMode = !$darkMode;
    $valorCookie = $darkMode ? 'true' : 'false';
    $tiempoExpira = time() + (86400 * 30);

    // Guardar en la raíz "/" para compartir con todas las vistas
    setcookie('dark_mode', $valorCookie, $tiempoExpira, "/");
    setcookie('dark_mode', '', time() - 3600, dirname($_SERVER['SCRIPT_NAME']));
    setcookie('dark_mode', '', time() - 3600);

    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

$error = null;
$usuarioInput = '';
$esLogout = isset($_GET['logout']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $esAjax = (
        (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_POST['formato']) && $_POST['formato'] === 'json')
    );

    try {
        validarTokenCsrf();

        $usuarioInput = trim((string) ($_POST['usuario'] ?? ''));
        $claveInput = (string) ($_POST['clave'] ?? '');

        if ($usuarioInput === '' || $claveInput === '') {
            throw new RuntimeException('Debe ingresar su usuario y contraseña.');
        }

        $usuario = autenticarUsuario($usuarioInput, $claveInput);

        if ($usuario === null) {
            throw new RuntimeException('Usuario o contraseña incorrectos.');
        }

        $token = iniciarSesionUsuario($usuario, true);

        if ($esAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'exito' => true,
                'redirigir' => 'index.php'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Location: index.php');
        exit;

    } catch (Throwable $e) {
        $error = $e->getMessage();

        if ($esAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode([
                'exito' => false,
                'error' => $error
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es" class="<?php echo $darkMode ? 'dark-mode' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Sistema de Alertas de Stock</title>
    <script>
        // Sincronizar modo oscuro antes de renderizar para evitar parpadeos
        (function() {
            try {
                const modo = localStorage.getItem('dark_mode');
                if (modo === 'true') {
                    document.documentElement.classList.add('dark-mode');
                } else if (modo === 'false') {
                    document.documentElement.classList.remove('dark-mode');
                }
            } catch (_) {}
        })();
    </script>
    <link rel="stylesheet" href="css/estilos.css?v=<?= file_exists(__DIR__ . '/css/estilos.css') ? filemtime(__DIR__ . '/css/estilos.css') : time() ?>">
    <link rel="stylesheet" href="css/login.css?v=<?= file_exists(__DIR__ . '/css/login.css') ? filemtime(__DIR__ . '/css/login.css') : time() ?>">
    <script src="js/sweetalert2.all.min.js"></script>
</head>
<body>
<div class="login-wrapper">
    <div class="login-card">
        <div class="login-top-bar">
            <!-- Botón para alternar el modo oscuro/claro -->
            <a href="?toggle_dark_mode=1"
               id="btn-toggle-dark"
               onclick="toggleDarkMode(event)"
               style="display: grid; place-items: center; width: 32px; height: 32px; border-radius: 50%; font-size: 28px; text-decoration: none; line-height: 1; filter: invert(1) hue-rotate(180deg);"
               title="Alternar modo claro / oscuro">
                <?php echo $darkMode ? '🌞' : '🌚'; ?>
            </a>
        </div>

        <div class="login-header">
            <img src="img/tecnofilm.png?v=<?= file_exists(__DIR__ . '/img/tecnofilm.png') ? filemtime(__DIR__ . '/img/tecnofilm.png') : time() ?>" alt="tecnofilm" class="login-logo logo-claro">
            <img src="img/tecnofilm_dark.png?v=<?= file_exists(__DIR__ . '/img/tecnofilm_dark.png') ? filemtime(__DIR__ . '/img/tecnofilm_dark.png') : time() ?>" alt="tecnofilm" class="login-logo logo-oscuro">
            <h1>Sistema de Notificaciones</h1>
            <p>Login de Acceso</p>
        </div>

        <div id="mensaje-error" class="alerta-login alerta-login-error" style="<?= $error !== null ? '' : 'display: none;' ?>">
            <span id="mensaje-error-texto"><?= htmlspecialchars((string) $error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            <button type="button" class="alerta-login-cerrar" onclick="cerrarAlerta(this.parentElement)" title="Cerrar">&times;</button>
        </div>

        <form id="form-login" method="POST" action="login.php" class="login-form" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generarTokenCsrf(), ENT_QUOTES, 'UTF-8') ?>">

            <div class="campo">
                <label for="usuario">Usuario</label>
                <input
                    type="text"
                    id="usuario"
                    name="usuario"
                    value="<?= htmlspecialchars($usuarioInput, ENT_QUOTES, 'UTF-8') ?>"
                    required
                    autofocus
                    placeholder="ADMINISTRA"
                    style="text-transform: uppercase;"
                >
            </div>

            <div class="campo">
                <label for="clave">Contraseña</label>
                <div class="password-container">
                    <input
                        type="password"
                        id="clave"
                        name="clave"
                        required
                        placeholder="••••••••"
                    >
                    <button
                        type="button"
                        id="btn-toggle-clave"
                        class="btn-toggle-pass"
                        title="Mostrar u ocultar contraseña"
                        tabindex="-1"
                    >
                        👁️‍🗨️
                    </button>
                </div>
            </div>

            <button
                type="submit"
                id="btn-submit"
                class="btn btn-primario btn-login-submit"
            >
                <span id="btn-texto">Ingresar</span>
            </button>
        </form>

    </div>
</div>

<script>
// Función para alternar modo claro / oscuro sincronizado con cookie y localStorage
function toggleDarkMode(e) {
    if (e) e.preventDefault();
    const html = document.documentElement;
    const esOscuro = html.classList.toggle('dark-mode');
    const valor = esOscuro ? 'true' : 'false';

    // 1. Limpiar cualquier cookie residual con ruta específica
    document.cookie = "dark_mode=; path=" + window.location.pathname + "; expires=Thu, 01 Jan 1970 00:00:00 UTC;";
    const subpath = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
    if (subpath) {
        document.cookie = "dark_mode=; path=" + subpath + "; expires=Thu, 01 Jan 1970 00:00:00 UTC;";
        document.cookie = "dark_mode=; path=" + subpath + "/; expires=Thu, 01 Jan 1970 00:00:00 UTC;";
    }

    // 2. Guardar UNA sola cookie global en la raíz "/" (compartida con index, crear, editar, etc.)
    document.cookie = `dark_mode=${valor}; path=/; max-age=2592000; SameSite=Lax`;

    // 3. Guardar en localStorage (sincronización instantánea entre pestañas y páginas)
    try {
        localStorage.setItem('dark_mode', valor);
    } catch (_) {}

    // 4. Actualizar el emoji del botón
    const btn = document.getElementById('btn-toggle-dark');
    if (btn) {
        btn.textContent = esOscuro ? '🌞' : '🌚';
    }
}

// Función global para cerrar/desvanecer cualquier alerta con animación
function cerrarAlerta(el) {
    if (!el) return;
    el.classList.add('oculta');
    setTimeout(() => {
        el.style.display = 'none';
        el.classList.remove('oculta');
    }, 450);
}

// Temporizador para auto-desaparecer tras N milisegundos
function autoDesaparecerAlerta(el, delayMs = 3500) {
    if (!el) return null;
    return setTimeout(() => {
        cerrarAlerta(el);
    }, delayMs);
}

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('form-login');
    const inputUsuario = document.getElementById('usuario');
    const inputClave = document.getElementById('clave');
    const btnToggleClave = document.getElementById('btn-toggle-clave');
    const btnSubmit = document.getElementById('btn-submit');
    const btnTexto = document.getElementById('btn-texto');
    const divError = document.getElementById('mensaje-error');
    const divErrorTexto = document.getElementById('mensaje-error-texto');
    const alertaLogout = document.getElementById('alerta-logout');
    let timerError = null;

    // Si hay alerta de logout, hacerla desaparecer a los 1.5s y limpiar URL
    if (alertaLogout) {
        autoDesaparecerAlerta(alertaLogout, 1500);
        if (window.history && window.history.replaceState) {
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    }

    // Si vino error renderizado desde PHP, hacerlo desaparecer a los 1.5s
    if (divError && divError.style.display !== 'none' && divErrorTexto && divErrorTexto.textContent.trim() !== '') {
        timerError = autoDesaparecerAlerta(divError, 1500);
    }

    // Al escribir en usuario o clave, ocultar el error inmediatamente
    [inputUsuario, inputClave].forEach(input => {
        input.addEventListener('input', () => {
            if (divError && divError.style.display !== 'none') {
                if (timerError) clearTimeout(timerError);
                cerrarAlerta(divError);
            }
        });
    });

    // Alternar visibilidad de contraseña
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

    // Envío asíncrono para almacenamiento seguro de token en navegador
    form.addEventListener('submit', function(e) {
        e.preventDefault();

        const usuario = inputUsuario.value.trim().toUpperCase();
        const clave = inputClave.value;

        if (!usuario || !clave) {
            mostrarError('Por favor complete todos los campos.');
            return;
        }

        btnSubmit.disabled = true;
        btnTexto.textContent = 'Validando credenciales...';
        if (timerError) clearTimeout(timerError);
        divError.style.display = 'none';

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
        .then(({ status, data }) => {
            if (data && data.exito) {
                // Limpiar cualquier residuo de credenciales o tokens en el cliente
                try {
                    localStorage.removeItem('auth_token');
                    sessionStorage.removeItem('auth_token');
                    localStorage.removeItem('auth_user');
                } catch (_) {}

                btnTexto.textContent = '¡Acceso concedido!';
                window.location.href = data.redirigir || 'index.php';
            } else {
                mostrarError((data && data.error) ? data.error : 'Usuario o contraseña incorrectos.');
                btnSubmit.disabled = false;
                btnTexto.textContent = 'Ingresar al Sistema';
                inputClave.value = '';
                inputClave.focus();
            }
        })
        .catch(err => {
            mostrarError('Error de conexión al servidor. Inténtalo nuevamente.');
            btnSubmit.disabled = false;
            btnTexto.textContent = 'Ingresar al Sistema';
        });
    });

    function mostrarError(mensaje) {
        if (timerError) clearTimeout(timerError);
        divErrorTexto.textContent = mensaje;
        divError.classList.remove('oculta');
        divError.style.display = 'flex';

        // Desaparece automáticamente después de 3.0 segundos
        timerError = autoDesaparecerAlerta(divError, 3000);
    }
});
</script>
</body>
</html>

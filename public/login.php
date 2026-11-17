<?php

declare(strict_types=1);

const PAGINA_PUBLICA = true;

require_once __DIR__ . '/seguridad.php';

// Si se solicita cerrar sesión, invalidar la sesión antes de comprobar si está autenticado
if (isset($_GET['logout'])) {
    cerrarSesionUsuario();
}

// Si el usuario ya está autenticado, redirigir a la selección de almacén
if (estaAutenticado()) {
    header('Location: seleccionar_almacen.php');
    exit;
}

// Modo oscuro (cookie compartida entre todas las páginas)
$darkMode = aplicarModoOscuro();

$error = null;
$usuarioInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $esAjax = esSolicitudAjax();

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
                'redirigir' => 'seleccionar_almacen.php'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Location: seleccionar_almacen.php');
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
    <script src="<?= h(assetUrl('js/comun.js')) ?>"></script>
    <link rel="stylesheet" href="<?= h(assetUrl('css/estilos.css')) ?>">
    <link rel="stylesheet" href="<?= h(assetUrl('css/login.css')) ?>">
    <script src="<?= h(assetUrl('js/sweetalert2.all.min.js')) ?>"></script>
</head>
<body>
<div class="login-wrapper">
    <div class="login-card">
        <div class="login-top-bar">
            <!-- Botón para alternar el modo oscuro/claro -->
            <a href="?toggle_dark_mode=1"
               id="btn-toggle-dark"
               class="btn-toggle-dark"
               onclick="toggleDarkMode(event)"
               title="Alternar modo claro / oscuro">
                <?php echo $darkMode ? '🌞' : '🌚'; ?>
            </a>
        </div>

        <div class="login-header">
            <img src="<?= h(assetUrl('img/tecnofilm.png')) ?>" alt="tecnofilm" class="login-logo logo-claro">
            <img src="<?= h(assetUrl('img/tecnofilm_dark.png')) ?>" alt="tecnofilm" class="login-logo logo-oscuro">
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

<script src="<?= h(assetUrl('js/login.js')) ?>"></script>
</body>
</html>

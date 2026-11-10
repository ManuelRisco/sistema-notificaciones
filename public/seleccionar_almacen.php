<?php

declare(strict_types=1);

require_once __DIR__ . '/seguridad.php';

// Comprobar que el usuario tenga sesión activa
requerirAutenticacion();

$usuario = obtenerUsuarioAutenticado();
$usrCodigo = (string) ($usuario['usr_codigo'] ?? '');
$usrNombre = (string) ($usuario['usr_nombre'] ?? $usrCodigo);

// Obtener los almacenes autorizados para el usuario
$almacenesAutorizados = obtenerAlmacenesAutorizadosUsuario($usrCodigo);
$todosSoportados = getAlmacenesSoportados();

// Modo oscuro sincronizado con cookie
$darkMode = isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'true';

// Alternar modo oscuro si se solicita vía GET
if (isset($_GET['toggle_dark_mode'])) {
    $darkMode = !$darkMode;
    $valorCookie = $darkMode ? 'true' : 'false';
    $tiempoExpira = time() + (86400 * 30);

    setcookie('dark_mode', $valorCookie, $tiempoExpira, "/");
    setcookie('dark_mode', '', time() - 3600, dirname($_SERVER['SCRIPT_NAME']));
    setcookie('dark_mode', '', time() - 3600);

    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

$error = null;

// Procesar selección de almacén vía POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        validarTokenCsrf();
        $almSeleccionado = trim((string) ($_POST['alm_codigo'] ?? ''));

        if ($almSeleccionado === '') {
            throw new RuntimeException('Debe seleccionar un almacén.');
        }

        establecerAlmacenActivo($almSeleccionado);

        header('Location: index.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// O permitir selección directa vía GET solo si está autorizado
if (isset($_GET['alm'])) {
    $almParam = trim((string) $_GET['alm']);
    try {
        establecerAlmacenActivo($almParam);
        header('Location: index.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es" class="<?= $darkMode ? 'dark-mode' : '' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seleccionar Almacén - Sistema de Alertas de Stock</title>
    <script>
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
        <link rel="stylesheet" href="css/almacenes.css?v=<?= file_exists(__DIR__ . '/css/almacenes.css') ? filemtime(__DIR__ . '/css/almacenes.css') : time() ?>">
    <script src="js/sweetalert2.all.min.js"></script>
</head>
<body>
<div class="seleccion-container">
    <header class="seleccion-header">
        <div class="seleccion-header-logo">
            <img src="img/tecnofilm.png?v=<?= file_exists(__DIR__ . '/img/tecnofilm.png') ? filemtime(__DIR__ . '/img/tecnofilm.png') : time() ?>" alt="Tecnofilm" class="no-invert">
        </div>
        <div class="seleccion-header-usuario">
            <span class="usuario-sesion-pill">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.7;">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                    <circle cx="12" cy="7" r="4"></circle>
                </svg>
                <?= htmlspecialchars($usrNombre, ENT_QUOTES, 'UTF-8') ?>
            </span>
            <a href="logout.php" class="btn-logout" title="Cerrar sesión">
                Salir
            </a>
            <button
                type="button"
                id="btn-toggle-dark"
                onclick="toggleDarkMode(event)"
                class="btn btn-secundario"
                style="padding: 4px 8px; border-radius: 50%; font-size: 15px; width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer;"
                title="Alternar Modo Oscuro"
            >
                <?= $darkMode ? '🌞' : '🌚' ?>
            </button>
        </div>
    </header>

    <main class="seleccion-main">
        <div class="seleccion-titulo">
            <h1>Seleccione el Almacén</h1>
            <p>Seleccione el almacén que desea administrar para acceder al catálogo de productos, stocks mínimos y alertas correspondientes.</p>
        </div>

        <?php if (!empty($almacenesAutorizados)): ?>
            <div class="seleccion-grid">
                <?php foreach ($almacenesAutorizados as $almCodigo): ?>
                    <?php
                    $info = $todosSoportados[$almCodigo] ?? null;
                    if (!$info) continue;
                    $iconoEmoji = $info['icono'] === 'palette' ? '🎨' : '📦';
                    ?>
                    <form method="POST" action="seleccionar_almacen.php" style="display: contents;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generarTokenCsrf(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="alm_codigo" value="<?= htmlspecialchars($almCodigo, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="tarjeta-almacen" onclick="this.closest('form').submit();">
                            <span class="almacen-badge-codigo">CÓDIGO: <?= htmlspecialchars($almCodigo, ENT_QUOTES, 'UTF-8') ?></span>
                            <h2 class="almacen-nombre"><?= htmlspecialchars($info['nombre'], ENT_QUOTES, 'UTF-8') ?></h2>
                            <span class="almacen-erp"><?= htmlspecialchars($info['nombre_erp'], ENT_QUOTES, 'UTF-8') ?></span>
                            <p class="almacen-descripcion"><?= htmlspecialchars($info['descripcion'], ENT_QUOTES, 'UTF-8') ?></p>
                            <button type="submit" class="almacen-btn-ingresar">
                                Ingresar
                            </button>
                        </div>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="sin-almacenes">
                <div style="font-size: 42px; margin-bottom: 16px;">⚠️</div>
                <h2 style="font-size: 19px; color: var(--color-peligro-texto); margin-bottom: 8px;">Sin almacenes asignados</h2>
                <p style="color: var(--color-texto-muted); font-size: 14px; line-height: 1.5; margin-bottom: 20px;">
                    No tiene autorización para ingresar a ningún almacén. Solo el personal encargado de almacén puede acceder.
                </p>
                <a href="logout.php" class="btn btn-secundario" style="text-decoration: none;">
                    Cerrar Sesión
                </a>
            </div>
        <?php endif; ?>
    </main>
</div>

<script>
function toggleDarkMode(e) {
    if (e) e.preventDefault();
    const html = document.documentElement;
    const esOscuro = html.classList.toggle('dark-mode');
    const valor = esOscuro ? 'true' : 'false';

    document.cookie = `dark_mode=${valor}; path=/; max-age=2592000; SameSite=Lax`;
    try {
        localStorage.setItem('dark_mode', valor);
    } catch (_) {}

    const btn = document.getElementById('btn-toggle-dark');
    if (btn) {
        btn.textContent = esOscuro ? '🌞' : '🌚';
    }
}

<?php if ($error !== null): ?>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
            icon: 'error',
            title: 'Acceso No Autorizado',
            text: <?= json_encode($error, JSON_UNESCAPED_UNICODE) ?>,
            confirmButtonColor: '#2563eb'
        });
    });
<?php endif; ?>
</script>
</body>
</html>

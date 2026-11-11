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
    <title>Lista de Almacenes - Sistema de Alertas de Stock</title>
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
<main class="contenedor">
    <header class="encabezado">
        <div class="encabezado-info">
            <h1>LISTA DE ALMACENES</h1>
        </div>

        <div class="encabezado-derecha">
            <div class="encabezado-usuario-barra">
                <?php if (!empty($usuario)): ?>
                    <div class="usuario-sesion-pill" title="Usuario: <?= htmlspecialchars($usuario['usr_codigo'] ?? '', ENT_QUOTES, 'UTF-8') ?> | Rol: <?= htmlspecialchars($usuario['pus_codigo'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <span class="usuario-sesion-nombre"><?= htmlspecialchars($usuario['usr_nombre'] ?: $usuario['usr_codigo'], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <a href="logout.php" class="btn btn-logout" title="Cerrar sesión">
                        Salir
                    </a>
                <?php endif; ?>
                <!-- Botón para alternar el modo -->
                <a href="?toggle_dark_mode=1"
                   id="btn-toggle-dark"
                   onclick="toggleDarkMode(event)"
                   class="btn-toggle-dark"
                   title="Alternar modo claro / oscuro">
                    <?= $darkMode ? '🌞' : '🌚' ?>
                </a>
            </div>
        </div>
    </header>

    <?php if (!empty($almacenesAutorizados)): ?>
        <div class="almacenes-grid">
            <?php foreach ($almacenesAutorizados as $almCodigo): ?>
                <?php
                $info = $todosSoportados[$almCodigo] ?? null;
                if (!$info) continue;
                ?>
                <form method="POST" action="seleccionar_almacen.php" class="form-tarjeta-almacen">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generarTokenCsrf(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="alm_codigo" value="<?= htmlspecialchars($almCodigo, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="tarjeta-almacen">
                        <div>
                            <div class="tarjeta-almacen-header">
                                <span class="badge badge-inactivo" style="font-weight: 700; font-size: 11.5px; padding: 4px 10px;">
                                    ALMACÉN <?= htmlspecialchars($almCodigo, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>

                            <h2 class="tarjeta-almacen-titulo">
                                <?= htmlspecialchars($info['nombre'], ENT_QUOTES, 'UTF-8') ?>
                            </h2>
                            <div class="tarjeta-almacen-erp">
                                <?= htmlspecialchars($info['nombre_erp'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <p class="tarjeta-almacen-desc">
                                <?= htmlspecialchars($info['descripcion'], ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </div>

                        <div class="tarjeta-almacen-acciones">
                            <button type="submit" class="btn btn-primario">
                                Ingresar
                            </button>
                        </div>
                    </div>
                </form>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="formulario" style="text-align: center; padding: 40px 24px; margin-top: 20px;">
            <div style="font-size: 42px; margin-bottom: 16px;">⚠️</div>
            <h2 style="font-size: 19px; color: var(--color-peligro-texto); margin-bottom: 8px;">Sin almacenes asignados</h2>
            <p style="color: var(--color-texto-muted); font-size: 14.5px; line-height: 1.5; margin-bottom: 24px;">
                No tiene autorización para ingresar a ningún almacén registrado en el sistema. Comuníquese con el administrador para solicitar asignación en el sistema.
            </p>
            <a href="logout.php" class="btn btn-secundario">
                Cerrar Sesión
            </a>
        </div>
    <?php endif; ?>
</main>

<script>
// Sincronizar el emoji del botón según el estado activo de la página
document.addEventListener('DOMContentLoaded', function() {
    const btnDark = document.getElementById('btn-toggle-dark');
    if (btnDark) {
        btnDark.textContent = document.documentElement.classList.contains('dark-mode') ? '🌞' : '🌚';
    }

    <?php if ($error !== null): ?>
    Swal.fire({
        icon: 'error',
        title: 'Atención',
        html: <?= json_encode($error, JSON_UNESCAPED_UNICODE) ?>,
        confirmButtonColor: '#dc2626',
        confirmButtonText: 'Entendido'
    });
    <?php endif; ?>
});

// Alternar modo oscuro compartido con index.php
function toggleDarkMode(e) {
    if (e) e.preventDefault();
    const html = document.documentElement;
    const esOscuro = html.classList.toggle('dark-mode');
    const valor = esOscuro ? 'true' : 'false';

    // Limpiar cualquier cookie residual
    document.cookie = "dark_mode=; path=" + window.location.pathname + "; expires=Thu, 01 Jan 1970 00:00:00 UTC;";
    const subpath = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
    if (subpath) {
        document.cookie = "dark_mode=; path=" + subpath + "; expires=Thu, 01 Jan 1970 00:00:00 UTC;";
        document.cookie = "dark_mode=; path=" + subpath + "/; expires=Thu, 01 Jan 1970 00:00:00 UTC;";
    }

    // Cookie global en raíz "/"
    document.cookie = `dark_mode=${valor}; path=/; max-age=2592000; SameSite=Lax`;

    try {
        localStorage.setItem('dark_mode', valor);
    } catch (_) {}

    const btn = document.getElementById('btn-toggle-dark');
    if (btn) {
        btn.textContent = esOscuro ? '🌞' : '🌚';
    }
}
</script>
</body>
</html>

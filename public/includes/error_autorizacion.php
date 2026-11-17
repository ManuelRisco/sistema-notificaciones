<?php

declare(strict_types=1);

/**
 * Vista de error HTTP 403 cuando el usuario no tiene permisos para un almacén.
 * Espera recibir $mensaje (string) y $darkMode (bool).
 */
$estilosHref = function_exists('assetUrl') ? assetUrl('css/estilos.css') : 'css/estilos.css';
$swalSrc = function_exists('assetUrl') ? assetUrl('js/sweetalert2.all.min.js') : 'js/sweetalert2.all.min.js';
?>
<!DOCTYPE html>
<html lang="es" class="<?= !empty($darkMode) ? 'dark-mode' : '' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Denegado - Tecnofilm</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($estilosHref, ENT_QUOTES, 'UTF-8') ?>">
    <script src="<?= htmlspecialchars($swalSrc, ENT_QUOTES, 'UTF-8') ?>"></script>
</head>
<body class="error-autorizacion-body">
    <div class="error-autorizacion-card">
        <div class="error-autorizacion-icono" aria-hidden="true">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line>
            </svg>
        </div>
        <h2 class="error-autorizacion-titulo">Acceso No Autorizado</h2>
        <p class="error-autorizacion-desc">
            <?= htmlspecialchars((string) ($mensaje ?? ''), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <div class="error-autorizacion-acciones">
            <a href="seleccionar_almacen.php" class="btn btn-primario">
                Ir a Selección de Almacén
            </a>
            <a href="logout.php" class="btn btn-secundario">
                Cerrar Sesión
            </a>
        </div>
    </div>
</body>
</html>


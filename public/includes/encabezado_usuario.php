<?php

declare(strict_types=1);

/**
 * Encabezado compartido para vistas autenticadas.
 *
 * Variables esperadas:
 * - string $tituloEncabezado
 * - string $nombreBD
 * - bool   $darkMode
 * Variables opcionales:
 * - ?string $subtituloEncabezado
 * - ?array  $usuarioEncabezado (si no se indica, usa obtenerUsuarioAutenticado())
 * - string  $accionesEncabezadoHtml
 */
$usuarioEncabezado = $usuarioEncabezado ?? obtenerUsuarioAutenticado();
$paramsToggleDark = $_GET;
$paramsToggleDark['toggle_dark_mode'] = 1;
$urlToggleDark = '?' . http_build_query($paramsToggleDark);
?>
<header class="encabezado">
    <div class="encabezado-info">
        <h1><?= h($tituloEncabezado) ?></h1>
        <?php if (!empty($subtituloEncabezado)): ?>
            <p><?= h($subtituloEncabezado) ?></p>
        <?php endif; ?>
    </div>

    <div class="encabezado-derecha">
        <div class="encabezado-usuario-barra">
            <?php if (!empty($usuarioEncabezado)): ?>
                <div class="encabezado-base-datos">
                    <span>BD: </span><?= h($nombreBD) ?>
                </div>
                <div class="usuario-sesion-pill" title="Usuario: <?= h($usuarioEncabezado['usr_codigo'] ?? '') ?> | Rol: <?= h($usuarioEncabezado['pus_codigo'] ?? '') ?>">
                    <span>USUARIO: </span>
                    <strong>
                        <span class="usuario-sesion-nombre"><?= h(!empty($usuarioEncabezado['usr_nombre']) ? $usuarioEncabezado['usr_nombre'] : ($usuarioEncabezado['usr_codigo'] ?? '')) ?></span>
                    </strong>
                </div>
                <a href="logout.php" class="btn btn-logout" title="Cerrar sesión">
                    Salir
                </a>
            <?php endif; ?>
            <?php renderBotonModoOscuro($urlToggleDark); ?>
        </div>

        <?php if (!empty($accionesEncabezadoHtml)): ?>
            <div class="encabezado-acciones">
                <?= $accionesEncabezadoHtml ?>
            </div>
        <?php endif; ?>
    </div>
</header>


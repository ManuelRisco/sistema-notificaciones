<?php

declare(strict_types=1);

require_once __DIR__ . '/seguridad.php';

// Comprobar que el usuario tenga sesión activa
requerirAutenticacion();

$usuario = obtenerUsuarioAutenticado();
$usrCodigo = (string) ($usuario['usr_codigo'] ?? '');
$empCodigo = (string) ($usuario['emp_codigo'] ?? getEmpresaDefecto());

// Obtener los almacenes autorizados para el usuario en su empresa
$almacenesAutorizados = obtenerAlmacenesAutorizadosUsuario($usrCodigo, $empCodigo);
$todosSoportados = getAlmacenesSoportados();

// Modo oscuro (cookie compartida entre todas las páginas)
$darkMode = aplicarModoOscuro();

// Obtener la base de datos seleccionada
$nombreBD = getDatabaseName();

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
    <script src="<?= h(assetUrl('js/comun.js')) ?>"></script>
    <link rel="stylesheet" href="<?= h(assetUrl('css/estilos.css')) ?>">
    <link rel="stylesheet" href="<?= h(assetUrl('css/almacenes.css')) ?>">
    <script src="<?= h(assetUrl('js/sweetalert2.all.min.js')) ?>"></script>
</head>
<body>
<main class="contenedor">
    <?php
    $tituloEncabezado = 'LISTA DE ALMACENES';
    $usuarioEncabezado = $usuario;
    $accionesEncabezadoHtml = '';
    require __DIR__ . '/includes/encabezado_usuario.php';
    ?>

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
                                <span class="badge badge-inactivo">
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
        <div class="formulario almacenes-vacio">
            <div class="almacenes-vacio-icono">⚠️</div>
            <h2 class="almacenes-vacio-titulo">Sin almacenes asignados</h2>
            <p class="almacenes-vacio-desc">
                No tiene autorización para ingresar a ningún almacén registrado en el sistema. Comuníquese con el administrador para solicitar asignación en el sistema.
            </p>
            <a href="logout.php" class="btn btn-secundario">
                Cerrar Sesión
            </a>
        </div>
    <?php endif; ?>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($error !== null): ?>
    swalEntendido('error', 'Atención', <?= json_encode($error, JSON_UNESCAPED_UNICODE) ?>);
    <?php endif; ?>
});

</script>
</body>
</html>

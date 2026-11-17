<?php

declare(strict_types=1);

use Manue\SistemaNotificaciones\StockRepository;

require_once __DIR__ . '/seguridad.php';

$raizProyecto = dirname(__DIR__);

// Validar autorización y obtener el almacén activo
$almCodigo = requerirAlmacenAutorizado($_GET['alm'] ?? null);
$almNombre = getNombreAlmacen($almCodigo);
$empCodigo = getEmpresaDefecto();

$artCodigo = '';
$artNombre = '';
$stockMinimo = '';
$categoria = '';
$famNombre = '';
$tfaNombre = '';
$disponible = 1;

$error = null;
$guardado = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $conexion = null;

    try {

        // 1. Validar seguridad.
        validarTokenCsrf();

        // 2. Recibir los datos editables.
        $artCodigo = trim($_POST['art_codigo'] ?? '');
        $stockMinimo = trim($_POST['stock_minimo'] ?? '');
        if (str_starts_with($stockMinimo, '.')) {
            $stockMinimo = '0' . $stockMinimo;
        }
        $categoria = trim($_POST['categoria'] ?? '');
        $famNombre = trim($_POST['fam_nombre'] ?? '');
        $tfaNombre = trim($_POST['tfa_nombre'] ?? '');
        $disponible = isset($_POST['disponible']) && (string)$_POST['disponible'] === '0' ? 0 : 1;

        // 3. Validar campos.
        if ($artCodigo === '' || $stockMinimo === '') {
            throw new RuntimeException(
                'El código de artículo y el stock mínimo son obligatorios.'
            );
        }

        if (!in_array($disponible, [0, 1], true)) {
            throw new RuntimeException(
                'El valor del campo disponibilidad no es válido.'
            );
        }

        if (strlen($artCodigo) > 30) {
            throw new RuntimeException(
                'El código del artículo admite máximo 30 caracteres.'
            );
        }

        if (!preg_match('/^\d{1,9}(?:\.\d{1,5})?$/D', $stockMinimo)) {
            throw new RuntimeException(
                'El stock mínimo debe ser positivo o cero, con máximo 5 decimales.'
            );
        }

        // 4. Conectar con SQL Server.
        $conexion = getSqlServerConn();
        $repositorio = new StockRepository($conexion);

        // 4.1 Consultar nombre del artículo en el maestro de artículos
        $artNombre = $repositorio->obtenerNombreArticulo($empCodigo, $artCodigo);

        if ($artNombre === '') {
            throw new RuntimeException(
                "El artículo con código '{$artCodigo}' no existe en el catálogo maestro (mae_articulo). Seleccione un artículo válido con el botón 'Buscar en catálogo'."
            );
        }

        // 5. Comprobar si ya existe en la base de datos (incluso si fue borrado lógicamente)
        $disponibleExistente = $repositorio->obtenerDisponibleConfiguracion(
            $empCodigo,
            $almCodigo,
            $artCodigo
        );

        if ($disponibleExistente !== null) {
            if ($disponibleExistente === 1) {
                throw new RuntimeException(
                    "El artículo {$artCodigo} ({$artNombre}) ya tiene una configuración activa para la empresa 001 y el almacén {$almCodigo} ({$almNombre})."
                );
            }

            // Si existía con borrado lógico (disponible = 0), se actualiza/reactiva con el estado seleccionado
            $repositorio->actualizarStockYDisponible(
                $empCodigo,
                $artCodigo,
                $almCodigo,
                $stockMinimo,
                $disponible
            );

        } else {

            // 6. Nuevo registro con disponibilidad seleccionada
            $repositorio->insertarConfiguracion(
                $empCodigo,
                $artCodigo,
                $almCodigo,
                $stockMinimo,
                $disponible
            );
        }

        $guardado = true;

    } catch (Throwable $e) {

        $error = $e->getMessage();

    } finally {

        if ($conexion !== null && $conexion !== false) {
            sqlsrv_close($conexion);
        }
    }

    if ($guardado) {
        $urlDestino = 'index.php?ok=creado&art=' . urlencode($artCodigo) . ($artNombre !== '' ? '&nom=' . urlencode($artNombre) : '');
        header('Location: ' . $urlDestino);
        exit;
    }
}

// Modo oscuro (cookie compartida entre todas las páginas)
$darkMode = aplicarModoOscuro();

// Obtener la base de datos seleccionada
$nombreBD = getDatabaseName();

?>
<!DOCTYPE html>
<html lang="es" class="<?php echo $darkMode ? 'dark-mode' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Nuevo registro de stock</title>
    <script src="<?= h(assetUrl('js/comun.js')) ?>"></script>
    <link rel="stylesheet" href="<?= h(assetUrl('css/estilos.css')) ?>">
    <script src="<?= h(assetUrl('js/sweetalert2.all.min.js')) ?>"></script>
    <script src="<?= h(assetUrl('js/formulario-articulo.js')) ?>"></script>
</head>
<body>
<main class="contenedor contenedor-formulario">
    <?php
    $tituloEncabezado = 'Nuevo registro - ' . $almNombre;
    $accionesEncabezadoHtml = '<a href="index.php" class="btn btn-secundario">← Volver</a>';
    require __DIR__ . '/includes/encabezado_usuario.php';
    ?>

    <form method="POST" class="formulario">
        <!-- Token CSRF -->
        <input
            type="hidden"
            name="csrf_token"
            value="<?= h(generarTokenCsrf()) ?>"
        >

        <!-- Artículo -->
        <div class="campo">
            <label for="art_codigo">
                Código Artículo
            </label>
            <div class="campo-con-boton">
                <input
                    type="text"
                    id="art_codigo"
                    name="art_codigo"
                    maxlength="30"
                    required
                    value="<?= h($artCodigo) ?>"
                    autocomplete="off"
                    readonly
                >
                <button
                    type="button"
                    class="btn btn-secundario btn-catalogo"
                    id="btn-catalogo"
                    onclick="abrirModalCatalogo()"
                    title="Buscar artículo en el catálogo"
                >
                    Buscar en catálogo
                </button>
            </div>
            <div id="info_articulo" class="info-articulo"></div>
        </div>

        <?php
        $unidadMedida = '';
        $placeholderStockMinimo = '20.00000';
        $textoBotonGuardar = 'Guardar registro';
        require __DIR__ . '/includes/campos_articulo_form.php';
        ?>
    </form>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($error !== null): ?>
    swalEntendido('error', 'Atención', <?= json_encode($error, JSON_UNESCAPED_UNICODE) ?>);
    <?php endif; ?>

    iniciarFormularioArticulo({
        modo: 'crear',
        almCodigo: <?= json_encode($almCodigo, JSON_UNESCAPED_UNICODE) ?>,
        almNombre: <?= json_encode($almNombre, JSON_UNESCAPED_UNICODE) ?>
    });
});
</script>
</body>
</html>

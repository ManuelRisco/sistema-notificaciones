<?php

declare(strict_types=1);

use Manue\SistemaNotificaciones\StockRepository;

require_once __DIR__ . '/seguridad.php';

$raizProyecto = dirname(__DIR__);

// Clave original del registro.
$empOriginal = trim($_GET['emp'] ?? $_POST['emp_original'] ?? '');
$artOriginal = trim($_GET['art'] ?? $_POST['art_original'] ?? '');
$almOriginal = trim($_GET['alm'] ?? $_POST['alm_original'] ?? '');

// Valores editables.
$artCodigo = $artOriginal;
$artNombre = '';
$stockMinimo = '';
$disponible = 1;
$unidadMedida = '';
$categoria = '';
$famNombre = '';
$tfaNombre = '';
$stockActual = null;

$error = null;
$registroEncontrado = false;
$actualizado = false;
$sinCambios = false;

$conexion = null;
$esPost = $_SERVER['REQUEST_METHOD'] === 'POST';

try {

    // Validar autorización del usuario para el almacén
    requerirAlmacenAutorizado($almOriginal);
    $almNombre = getNombreAlmacen($almOriginal);

    // 1. Validar identificación original.
    if (
        $empOriginal !== '001'
        || !esAlmacenSoportado($almOriginal)
        || $artOriginal === ''
        || strlen($artOriginal) > 30
    ) {
        throw new RuntimeException(
            'La identificación del registro no es válida.'
        );
    }

    // 2. Conectar con SQL Server.
    $conexion = getSqlServerConn();
    $repositorio = new StockRepository($conexion);

    if ($esPost) {

        // 3. Validar protección CSRF.
        validarTokenCsrf();

        // 4. Recuperar los nuevos valores.
        $artCodigo = trim($_POST['art_codigo'] ?? '');
        $stockMinimo = trim($_POST['stock_minimo'] ?? '');
        $disponible = isset($_POST['disponible']) ? (int) $_POST['disponible'] : 1;
        $categoria = trim($_POST['categoria'] ?? '');
        $famNombre = trim($_POST['fam_nombre'] ?? '');
        $tfaNombre = trim($_POST['tfa_nombre'] ?? '');

        // 5. Validar campos obligatorios.
        if ($artCodigo === '' || $stockMinimo === '') {
            throw new RuntimeException(
                'El código de artículo y el stock mínimo son obligatorios.'
            );
        }

        // 6. Validar disponible (solo 0 o 1).
        if (!in_array($disponible, [0, 1], true)) {
            throw new RuntimeException(
                'El valor del campo disponible no es válido.'
            );
        }

        // 7. Validar longitud del código.
        if (strlen($artCodigo) > 30) {
            throw new RuntimeException(
                'El código de artículo admite máximo 30 caracteres.'
            );
        }

        // 8. Validar NUMERIC(14,5).
        if (!preg_match('/^\d{1,9}(?:\.\d{1,5})?$/D', $stockMinimo)) {
            throw new RuntimeException(
                'El stock mínimo debe ser positivo o cero, con máximo 5 decimales.'
            );
        }

        // 9. Recuperar los valores actuales desde SQL Server.
        $registroActual = $repositorio->obtenerConfiguracion(
            $empOriginal,
            $artOriginal,
            $almOriginal
        );

        if ($registroActual === null) {
            throw new RuntimeException(
                'El registro que intentas modificar ya no existe.'
            );
        }

        $artNombre = trim((string) ($registroActual['art_nombre'] ?? ''));

        // 10. Comparar cambios en código, stock mínimo y disponible.
        $articuloCambio =
            $artCodigo !== trim((string) $registroActual['art_codigo']);

        // Si se modificó el código de artículo, consultar el nombre del nuevo artículo
        if ($articuloCambio && $artCodigo !== '') {
            $artNombre = $repositorio->obtenerNombreArticulo($empOriginal, $artCodigo);
        }

        // Validar que el artículo exista en el catálogo maestro (mae_articulo)
        if ($artNombre === '') {
            throw new RuntimeException(
                "El artículo con código '{$artCodigo}' no existe en el catálogo maestro (mae_articulo)."
            );
        }

        $stockCambio =
            normalizarStock($stockMinimo)
            !== normalizarStock($registroActual['stock_minimo']);

        $disponibleActual = (int) ($registroActual['disponible'] ?? 1);
        $disponibleCambio = ($disponible !== $disponibleActual);

        // 11. Comprobar si realmente existen modificaciones.
        if (!$articuloCambio && !$stockCambio && !$disponibleCambio) {

            // No ejecutar UPDATE innecesario.
            $sinCambios = true;

        } else {

            // 12. Actualizar si existen cambios.
            // Las banderas se conservan tal como están al cambiar stock o disponibilidad.
            // Solamente se reinician si cambia el código de artículo.
            $repositorio->actualizarConfiguracion(
                $empOriginal,
                $artOriginal,
                $almOriginal,
                $artCodigo,
                $stockMinimo,
                $disponible,
                $articuloCambio,
                $almNombre
            );

            $actualizado = true;
        }

    } else {

        // 13. GET: consultar el registro original y su stock físico real en el almacén
        $registro = $repositorio->obtenerConfiguracion(
            $empOriginal,
            $artOriginal,
            $almOriginal
        );

        if ($registro === null) {
            throw new RuntimeException(
                'El registro solicitado no existe.'
            );
        }

        $artCodigo = trim((string) $registro['art_codigo']);
        $artNombre = trim((string) ($registro['art_nombre'] ?? ''));
        $unidadMedida = trim((string) ($registro['unidad_medida'] ?? ''));
        $categoria = trim((string) ($registro['categoria'] ?? ''));
        $famNombre = trim((string) ($registro['fam_nombre'] ?? ''));
        $tfaNombre = trim((string) ($registro['tfa_nombre'] ?? ''));
        $stockActual = $registro['stock_actual'] !== null ? (float) $registro['stock_actual'] : null;
        $stockMinimo = (string) $registro['stock_minimo'];
        $disponible = (int) ($registro['disponible'] ?? 1);
        $registroEncontrado = true;
    }

} catch (Throwable $e) {

    $error = $e->getMessage();

} finally {

    if ($conexion !== null && $conexion !== false) {
        sqlsrv_close($conexion);
    }
}

// Redireccionar si la actualización fue exitosa
if ($actualizado) {
    $urlDestino = 'index.php?ok=editado&art=' . urlencode($artCodigo) . ($artNombre !== '' ? '&nom=' . urlencode($artNombre) : '');
    header('Location: ' . $urlDestino);
    exit;
}

$mostrarFormulario = $registroEncontrado || $esPost;

// Normalizar números para comparar NUMERIC(14,5).
function normalizarStock(mixed $valor): string
{
    $valor = trim((string) $valor);

    $partes = explode('.', $valor, 2);

    $entero = ltrim($partes[0], '0');

    if ($entero === '') {
        $entero = '0';
    }

    $decimal = $partes[1] ?? '';

    $decimal = str_pad($decimal, 5, '0');
    $decimal = substr($decimal, 0, 5);

    return $entero . '.' . $decimal;
}

// Escapar los valores antes de mostrarlos en HTML.
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
    <title>Editar Stock Mínimo</title>
    <script src="js/comun.js"></script>
    <link rel="stylesheet" href="css/estilos.css?v=<?= file_exists(__DIR__ . '/css/estilos.css') ? filemtime(__DIR__ . '/css/estilos.css') : time() ?>">
    <script src="js/sweetalert2.all.min.js"></script>
    <script src="js/formulario-articulo.js"></script>
</head>
<body>
<main class="contenedor contenedor-formulario">
    <header class="encabezado">
        <div class="encabezado-info">
            <h1>Editar Stock Mínimo - <?= h($almNombre) ?></h1>
        </div>
        <div class="encabezado-derecha">
            <div class="encabezado-usuario-barra">
                <?php
                $usuarioSesion = obtenerUsuarioAutenticado();
                if (!empty($usuarioSesion)):
                ?>
                    <div class="encabezado-base-datos">
                        <span>BD: </span><?= htmlspecialchars($nombreBD, ENT_QUOTES, 'UTF-8') ?>
                     </div>
                     <span>USUARIO: </span>
                     <div class="usuario-sesion-pill" title="Usuario: <?= h($usuarioSesion['usr_codigo'] ?? '') ?> | Rol: <?= h($usuarioSesion['pus_codigo'] ?? '') ?>">
                            <strong>
                                <span class="usuario-sesion-nombre"><?= h($usuarioSesion['usr_nombre'] ?: $usuarioSesion['usr_codigo']) ?></span>
                            </strong>
                    </div>
                    <a href="logout.php" class="btn btn-logout" title="Cerrar sesión">
                        Salir
                    </a>
                <?php endif; ?>
                <!-- Botón para alternar el modo -->
                <?php
                $paramsToggle = $_GET;
                $paramsToggle['toggle_dark_mode'] = 1;
                ?>
                <a href="?<?= htmlspecialchars(http_build_query($paramsToggle), ENT_QUOTES, 'UTF-8') ?>"
                   id="btn-toggle-dark"
                   onclick="toggleDarkMode(event)"
                   class="btn-toggle-dark"
                   title="Alternar modo claro / oscuro">
                    <?php echo $darkMode ? '🌞' : '🌚'; ?>
                </a>
            </div>
            <div class="encabezado-acciones">
                <a href="index.php" class="btn btn-secundario">
                    ← Volver
                </a>
            </div>
        </div>
    </header>

    <?php if ($mostrarFormulario): ?>
        <form method="POST" class="formulario">
            <!-- Token de seguridad. -->
            <input
                type="hidden"
                name="csrf_token"
                value="<?= h(generarTokenCsrf()) ?>"
            >

            <!-- Identificación original del registro. -->
            <input
                type="hidden"
                name="emp_original"
                value="<?= h($empOriginal) ?>"
            >
            <input
                type="hidden"
                name="art_original"
                value="<?= h($artOriginal) ?>"
            >
            <input
                type="hidden"
                name="alm_original"
                value="<?= h($almOriginal) ?>"
            >

            <!-- Artículo editable. -->
            <div class="campo">
                <label for="art_codigo">
                    Código Artículo
                </label>
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
                <div id="info_articulo" class="info-articulo">
                    <?php if ($artNombre !== ''): ?>
                        <div class="info-articulo-fila">
                            <span class="articulo-nombre-destacado"><?= h($artNombre) ?></span>
                            <span class="articulo-stock-tag">
                                Stock real: <strong><?= h($stockActual !== null ? number_format((float) $stockActual, 3, '.', ',') . ($unidadMedida !== '' ? ' ' . $unidadMedida : '') : '0.000 ' . $unidadMedida) ?></strong>
                            </span>
                        </div>
                    <?php else: ?>
                        <span class="badge badge-alerta badge-sm">No encontrado</span>
                        <span class="alerta-articulo-error">Este artículo no existe en el catálogo maestro.</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Categoría -->
            <div class="campo">
                <label for="art_categoria">
                    Categoría
                </label>
                <input
                    type="text"
                    id="art_categoria"
                    name="categoria"
                    value="<?= h($categoria) ?>"
                    autocomplete="off"
                    readonly
                    placeholder="—"
                >
            </div>

            <!-- Familia -->
            <div class="campo">
                <label for="art_familia">
                    Familia
                </label>
                <input
                    type="text"
                    id="art_familia"
                    name="fam_nombre"
                    value="<?= h($famNombre) ?>"
                    autocomplete="off"
                    readonly
                    placeholder="—"
                >
            </div>

            <!-- Tipo familia -->
            <div class="campo">
                <label for="art_tipo_familia">
                    Tipo familia
                </label>
                <input
                    type="text"
                    id="art_tipo_familia"
                    name="tfa_nombre"
                    value="<?= h($tfaNombre) ?>"
                    autocomplete="off"
                    readonly
                    placeholder="—"
                >
            </div>

            <!-- Stock mínimo editable. -->
            <div class="campo">
                <label for="stock_minimo">
                    Stock mínimo
                </label>
                <div class="campo-con-sufijo">
                    <input
                        type="number"
                        id="stock_minimo"
                        name="stock_minimo"
                        min="0"
                        max="999999999.99999"
                        step="0.00001"
                        required
                        value="<?= h($stockMinimo) ?>"
                    >
                    <span id="sufijo-unidad-minimo" class="sufijo-unidad"><?= h($unidadMedida) ?></span>
                </div>
            </div>

            <!-- Disponibilidad editable. -->
            <div class="campo">
                <label for="disponible">
                    Disponibilidad
                </label>
                <select id="disponible" name="disponible">
                    <option value="1" <?= $disponible === 1 ? 'selected' : '' ?>>
                        Activo
                    </option>
                    <option value="0" <?= $disponible === 0 ? 'selected' : '' ?>>
                        Inactivo
                    </option>
                </select>
            </div>

            <div class="acciones">
                <button
                    type="submit"
                    class="btn btn-primario"
                >
                    Guardar cambios
                </button>
                <a
                    href="index.php"
                    class="btn btn-secundario"
                >
                    Cancelar
                </a>
            </div>
        </form>
    <?php endif; ?>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {

    <?php if ($error !== null): ?>
    swalEntendido('error', 'Atención', <?= json_encode($error, JSON_UNESCAPED_UNICODE) ?>);
    <?php endif; ?>

    <?php if ($sinCambios): ?>
    Swal.fire({
        icon: 'info',
        title: 'Sin cambios',
        text: 'No se realizaron modificaciones en el registro.',
        timer: 3000,
        timerProgressBar: true,
        confirmButtonColor: '#2563eb',
        confirmButtonText: 'Aceptar'
    });
    <?php endif; ?>

    iniciarFormularioArticulo({
        modo: 'editar',
        almCodigo: <?= json_encode($almOriginal, JSON_UNESCAPED_UNICODE) ?>,
        almNombre: <?= json_encode($almNombre, JSON_UNESCAPED_UNICODE) ?>,
        artOriginal: <?= json_encode($artOriginal, JSON_UNESCAPED_UNICODE) ?>,
        artNombre: <?= json_encode($artNombre, JSON_UNESCAPED_UNICODE) ?>
    });
});
</script>
</body>
</html>

<?php

declare(strict_types=1);

use Manue\SistemaNotificaciones\StockRepository;

// 1.1 Protección del formulario de eliminación
require_once __DIR__ . '/seguridad.php';

// 2. Cargar las variables de entorno.
$raizProyecto = dirname(__DIR__);

// Validar autorización y recuperar almacén activo
$almActivo = requerirAlmacenAutorizado($_GET['alm'] ?? null);
$nombreAlmacen = getNombreAlmacen($almActivo);

$conexion = null;
$registros = [];
$error = null;
// Filtros recibidos por GET (consultados directamente a la base de datos)
$filtroDisponible = isset($_GET['disponible']) && in_array((string)$_GET['disponible'], ['0', '1'], true)
    ? (string)$_GET['disponible']
    : '';

$filtroStock = isset($_GET['stock']) && in_array((string)$_GET['stock'], ['0', '1'], true)
    ? (string)$_GET['stock']
    : '';

$filtroCorreo = isset($_GET['correo']) && in_array((string)$_GET['correo'], ['0', '1'], true)
    ? (string)$_GET['correo']
    : '';

$filtroCatalogo = isset($_GET['catalogo']) && $_GET['catalogo'] === 'no_encontrado'
    ? 'no_encontrado'
    : '';

$filtroCategoria = isset($_GET['categoria']) ? trim((string)$_GET['categoria']) : '';
$filtroFamilia = isset($_GET['familia']) ? trim((string)$_GET['familia']) : '';
$filtroTfa = isset($_GET['tfa']) ? trim((string)$_GET['tfa']) : '';

$ordenValidos = ['stock_desc', 'stock_asc', 'minimo_desc', 'minimo_asc'];
$orden = isset($_GET['orden']) && in_array((string)$_GET['orden'], $ordenValidos, true)
    ? (string)$_GET['orden']
    : '';

$opcionesCategoria = [];
$opcionesFamilia = [];
$opcionesTfa = [];

$totales = [
    'total' => 0,
    'disponibles' => 0,
    'no_disponibles' => 0,
    'con_stock' => 0,
    'sin_stock' => 0,
    'correo_enviado' => 0,
    'correo_no_enviado' => 0,
    'no_encontrados' => 0
];

try {

    // 4. Obtener la conexión y el repositorio.
    $conexion = getSqlServerConn();
    $repositorio = new StockRepository($conexion);

    // 5. Totales generales del almacén activo
    $totales = $repositorio->obtenerTotalesConfiguracion($almActivo);

    // 6. Registros del almacén activo con su stock físico (filtrados y ordenados en la BD)
    $registros = $repositorio->listarConfiguracion($almActivo, [
        'disponible' => $filtroDisponible,
        'catalogo' => $filtroCatalogo,
        'stock' => $filtroStock,
        'correo' => $filtroCorreo,
        'categoria' => $filtroCategoria,
        'familia' => $filtroFamilia,
        'tfa' => $filtroTfa
    ], $orden);

    // 7. Opciones de Detalle (Categoría, Familia, Tipo familia) para los filtros
    $opcionesDetalle = $repositorio->obtenerOpcionesClasificacion($almActivo);
    $opcionesCategoria = $opcionesDetalle['categorias'];
    $opcionesFamilia = $opcionesDetalle['familias'];
    $opcionesTfa = $opcionesDetalle['tipos_familia'];

} catch (Throwable $e) {

    $error = $e->getMessage();

} finally {

    if ($conexion !== null && $conexion !== false) {
        sqlsrv_close($conexion);
    }
}

/**
 * Genera URLs acumulativas o conmutables para los filtros en BD
 */
if (!function_exists('urlConFiltros')) {
    function urlConFiltros(array $cambios = []): string
    {
        $parametros = $_GET;
        unset($parametros['ok'], $parametros['error']);

        foreach ($cambios as $clave => $valor) {
            if ($valor === null || $valor === '') {
                unset($parametros[$clave]);
            } else {
                $parametros[$clave] = (string) $valor;
            }
        }

        $query = http_build_query($parametros);
        return 'index.php' . ($query !== '' ? '?' . $query : '');
    }
}

$hayFiltrosActivos = (
    $filtroDisponible !== '' ||
    $filtroStock !== '' ||
    $filtroCorreo !== '' ||
    $filtroCatalogo !== '' ||
    $filtroCategoria !== '' ||
    $filtroFamilia !== '' ||
    $filtroTfa !== '' ||
    $orden !== ''
);

// Gestión de mensajes de notificación (éxito, advertencia o error)
$mensajeExito = null;
$mensajeAdvertencia = null;
$ok = $_GET['ok'] ?? '';
$artParam = trim((string) ($_GET['art'] ?? ''));
$nomParam = trim((string) ($_GET['nom'] ?? ''));

$detalleArticuloParam = '';
if ($artParam !== '') {
    $detalleArticuloParam = ' para el artículo <strong>' . htmlspecialchars($artParam, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>';
    if ($nomParam !== '') {
        $detalleArticuloParam .= '<br><span class="articulo-nombre-secundario">' . htmlspecialchars($nomParam, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
    }
}

if ($ok === 'ejecutado') {
    $detalleEjecucion = $_SESSION['flash_ejecucion'] ?? '';
    $tipoEjecucion = $_SESSION['flash_ejecucion_tipo'] ?? 'success';
    unset($_SESSION['flash_ejecucion'], $_SESSION['flash_ejecucion_tipo']);
    if ($tipoEjecucion === 'warning') {
        $mensajeAdvertencia = $detalleEjecucion !== ''
            ? $detalleEjecucion
            : 'El stock se revisó, pero ocurrió un problema al enviar el correo de notificación.';
    } else {
        $mensajeExito = $detalleEjecucion !== '' ? $detalleEjecucion : 'Stock revisado correctamente.';
    }
} elseif ($ok === 'creado') {
    $mensajeExito = 'Configuración de stock' . $detalleArticuloParam . ' registrada exitosamente.';
} elseif ($ok === 'editado') {
    $mensajeExito = 'Configuración de stock' . $detalleArticuloParam . ' actualizada exitosamente.';
} elseif ($ok === 'eliminado') {
    $mensajeExito = 'Configuración de stock' . $detalleArticuloParam . ' desactivada exitosamente.';
} elseif ($ok === 'reactivado') {
    $mensajeExito = 'Configuración de stock' . $detalleArticuloParam . ' reactivada exitosamente.';
}

if (!empty($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
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
    <title>Almacén <?= htmlspecialchars(getNombreAlmacen($almActivo), ENT_QUOTES, 'UTF-8') ?> - Alertas de Stock</title>
    <script src="js/comun.js"></script>
    <link rel="stylesheet" href="css/estilos.css?v=<?= file_exists(__DIR__ . '/css/estilos.css') ? filemtime(__DIR__ . '/css/estilos.css') : time() ?>">
    <link rel="stylesheet" href="css/index.css?v=<?= file_exists(__DIR__ . '/css/index.css') ? filemtime(__DIR__ . '/css/index.css') : time() ?>">
    <script src="js/sweetalert2.all.min.js"></script>
</head>
<body>
<main class="contenedor">
    <header class="encabezado">
        <div class="encabezado-info">
            <h1>ALMACÉN - <?= htmlspecialchars(strtoupper(getNombreAlmacen($almActivo)), ENT_QUOTES, 'UTF-8') ?></h1>
            <p>Sistema de Alertas y Monitoreo de Stock Mínimo (Almacén <?= htmlspecialchars($almActivo, ENT_QUOTES, 'UTF-8') ?>)</p>
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
                    <div class="usuario-sesion-pill" title="Usuario: <?= h($usuarioSesion['usr_codigo'] ?? '') ?> | Rol: <?= h($usuarioSesion['pus_codigo'] ?? '') ?>">
                        <span>USUARIO: </span>
                            <strong>
                                <span class="usuario-sesion-nombre"><?= h($usuarioSesion['usr_nombre'] ?: $usuarioSesion['usr_codigo']) ?></span>
                            </strong>
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
                    <?php echo $darkMode ? '🌞' : '🌚'; ?>
                </a>
            </div>

            <div class="encabezado-acciones">
                <a href="seleccionar_almacen.php" class="btn btn-secundario" title="Cambiar de almacén">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                        <polyline points="9 22 9 12 15 12 15 22"></polyline>
                    </svg>
                    Cambiar Almacén
                </a>
                <a href="index.php" class="btn btn-secundario btn-icono-actualizar" title="Actualizar datos">
                    <img src="img/repetir.png" class="icono-invertible icono-btn" alt="Actualizar">
                </a>
                <form action="ejecutar_manual.php" method="POST" class="form-ejecutar-manual">
                    <input type="hidden" name="csrf_token" value="<?= h(generarTokenCsrf()) ?>">
                    <input type="hidden" name="almacen" value="<?= h($almActivo) ?>">
                    <button
                        type="submit"
                        class="btn btn-ejecutar"
                    >
                        Revisar Stock
                    </button>
                </form>
                <a href="crear.php" class="btn btn-primario">
                    + Nuevo registro
                </a>
            </div>
        </div>
    </header>

    <?php if ($error === null || !empty($registros) || $hayFiltrosActivos): ?>
        <!-- PANEL RESUMEN CON LOS TOTALES DE LOS ARTÍCULOS -->
        <?php require __DIR__ . '/includes/tarjetas_resumen.php'; ?>
    <?php endif; ?>

    <!-- BUSCADOR POR CÓDIGO O NOMBRE DE ARTÍCULO -->
    <div class="busqueda-contenedor">
        <form
                action="index.php"
                method="GET"
                class="busqueda-form"
        >
            <?php if ($filtroDisponible !== ''): ?>
                <input type="hidden" name="disponible" value="<?= h($filtroDisponible) ?>">
            <?php endif; ?>
            <?php if ($filtroStock !== ''): ?>
                <input type="hidden" name="stock" value="<?= h($filtroStock) ?>">
            <?php endif; ?>
            <?php if ($filtroCorreo !== ''): ?>
                <input type="hidden" name="correo" value="<?= h($filtroCorreo) ?>">
            <?php endif; ?>
            <?php if ($filtroCatalogo !== ''): ?>
                <input type="hidden" name="catalogo" value="<?= h($filtroCatalogo) ?>">
            <?php endif; ?>
            <?php if ($filtroCategoria !== ''): ?>
                <input type="hidden" name="categoria" value="<?= h($filtroCategoria) ?>">
            <?php endif; ?>
            <?php if ($filtroFamilia !== ''): ?>
                <input type="hidden" name="familia" value="<?= h($filtroFamilia) ?>">
            <?php endif; ?>
            <?php if ($filtroTfa !== ''): ?>
                <input type="hidden" name="tfa" value="<?= h($filtroTfa) ?>">
            <?php endif; ?>
            <?php if ($orden !== ''): ?>
                <input type="hidden" name="orden" value="<?= h($orden) ?>">
            <?php endif; ?>

            <div class="busqueda-campo busqueda-campo-texto">
                <label for="articulo">
                    Buscar artículo por código o nombre
                </label>
                <input
                        type="search"
                        id="articulo"
                        maxlength="50"
                        placeholder="020100427 | ROJO BANDERA 409"
                        autocomplete="off"
                >
            </div>
            <div class="busqueda-acciones">
                <?php if ($hayFiltrosActivos): ?>
                    <a
                            href="index.php"
                            class="btn btn-secundario"
                    >
                        Limpiar filtros
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <p id="busqueda-resultados" class="busqueda-resultados" style="<?= $hayFiltrosActivos ? '' : 'display: none;' ?>">
            <?php if ($hayFiltrosActivos): ?>
                Filtros activos en base de datos — <strong><?= count($registros) ?></strong> <?= count($registros) === 1 ? 'registro encontrado' : 'registros encontrados' ?>.
                <a href="index.php" class="busqueda-limpiar-enlace">Quitar todos los filtros</a>
            <?php endif; ?>
        </p>
    </div>

    <?php if ($error === null || !empty($registros) || $hayFiltrosActivos): ?>
        <div class="tabla-contenedor">
            <table>
                <thead>
                <tr>
                    <th>Artículo</th>
                    <!-- FILTRO TIPO: CATEGORÍA -->
                    <th class="th-filtrable">
                        <div class="th-contenido">
                            <span>Categoría</span>
                            <button
                                type="button"
                                class="btn-filtro-columna <?= ($filtroCategoria !== '') ? 'filtro-activo' : '' ?>"
                                title="Filtrar por Categoría"
                                onclick="toggleMenuFiltro(event, 'menu-filtro-categoria')"
                            >
                                <svg class="icono-filtro" viewBox="0 0 16 16" width="12" height="12" fill="currentColor">
                                    <path d="M1.5 2.5a.5.5 0 0 1 .5-.5h12a.5.5 0 0 1 .38.82L9 8.24V13.5a.5.5 0 0 1-.76.42l-2-1.25A.5.5 0 0 1 6 12.25V8.24L1.62 2.82a.5.5 0 0 1-.12-.32z"/>
                                </svg>
                            </button>
                        </div>
                        <div id="menu-filtro-categoria" class="menu-filtro">
                            <a href="<?= urlConFiltros(['categoria' => '']) ?>" class="opcion-filtro <?= ($filtroCategoria === '') ? 'seleccionada' : '' ?>">
                                <span class="indicador-check"><?= ($filtroCategoria === '') ? '✓' : '' ?></span> Todas las opciones
                            </a>
                            <?php foreach ($opcionesCategoria as $cat): ?>
                                <a href="<?= urlConFiltros(['categoria' => ($filtroCategoria === $cat ? '' : $cat)]) ?>" class="opcion-filtro <?= ($filtroCategoria === $cat) ? 'seleccionada' : '' ?>" title="<?= h($cat) ?>">
                                    <span class="indicador-check"><?= ($filtroCategoria === $cat) ? '✓' : '' ?></span> <?= h($cat) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </th>

                    <!-- FILTRO TIPO: FAMILIA -->
                    <th class="th-filtrable">
                        <div class="th-contenido">
                            <span>Familia</span>
                            <button
                                type="button"
                                class="btn-filtro-columna <?= ($filtroFamilia !== '') ? 'filtro-activo' : '' ?>"
                                title="Filtrar por Familia"
                                onclick="toggleMenuFiltro(event, 'menu-filtro-familia')"
                            >
                                <svg class="icono-filtro" viewBox="0 0 16 16" width="12" height="12" fill="currentColor">
                                    <path d="M1.5 2.5a.5.5 0 0 1 .5-.5h12a.5.5 0 0 1 .38.82L9 8.24V13.5a.5.5 0 0 1-.76.42l-2-1.25A.5.5 0 0 1 6 12.25V8.24L1.62 2.82a.5.5 0 0 1-.12-.32z"/>
                                </svg>
                            </button>
                        </div>
                        <div id="menu-filtro-familia" class="menu-filtro">
                            <a href="<?= urlConFiltros(['familia' => '']) ?>" class="opcion-filtro <?= ($filtroFamilia === '') ? 'seleccionada' : '' ?>">
                                <span class="indicador-check"><?= ($filtroFamilia === '') ? '✓' : '' ?></span> Todas las opciones
                            </a>
                            <?php foreach ($opcionesFamilia as $fam): ?>
                                <a href="<?= urlConFiltros(['familia' => ($filtroFamilia === $fam ? '' : $fam)]) ?>" class="opcion-filtro <?= ($filtroFamilia === $fam) ? 'seleccionada' : '' ?>" title="<?= h($fam) ?>">
                                    <span class="indicador-check"><?= ($filtroFamilia === $fam) ? '✓' : '' ?></span> <?= h($fam) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </th>

                    <!-- FILTRO TIPO: TIPO FAMILIA -->
                    <th class="th-filtrable">
                        <div class="th-contenido">
                            <span>Tipo familia</span>
                            <button
                                type="button"
                                class="btn-filtro-columna <?= ($filtroTfa !== '') ? 'filtro-activo' : '' ?>"
                                title="Filtrar por Tipo familia"
                                onclick="toggleMenuFiltro(event, 'menu-filtro-tfa')"
                            >
                                <svg class="icono-filtro" viewBox="0 0 16 16" width="12" height="12" fill="currentColor">
                                    <path d="M1.5 2.5a.5.5 0 0 1 .5-.5h12a.5.5 0 0 1 .38.82L9 8.24V13.5a.5.5 0 0 1-.76.42l-2-1.25A.5.5 0 0 1 6 12.25V8.24L1.62 2.82a.5.5 0 0 1-.12-.32z"/>
                                </svg>
                            </button>
                        </div>
                        <div id="menu-filtro-tfa" class="menu-filtro">
                            <a href="<?= urlConFiltros(['tfa' => '']) ?>" class="opcion-filtro <?= ($filtroTfa === '') ? 'seleccionada' : '' ?>">
                                <span class="indicador-check"><?= ($filtroTfa === '') ? '✓' : '' ?></span> Todas las opciones
                            </a>
                            <?php foreach ($opcionesTfa as $tf): ?>
                                <a href="<?= urlConFiltros(['tfa' => ($filtroTfa === $tf ? '' : $tf)]) ?>" class="opcion-filtro <?= ($filtroTfa === $tf) ? 'seleccionada' : '' ?>" title="<?= h($tf) ?>">
                                    <span class="indicador-check"><?= ($filtroTfa === $tf) ? '✓' : '' ?></span> <?= h($tf) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </th>
                    <!-- FILTRO / ORDEN: STOCK FÍSICO -->
                    <th class="th-filtrable">
                        <div class="th-contenido">
                            <span>Stock físico</span>
                            <button
                                type="button"
                                class="btn-filtro-columna <?= in_array($orden, ['stock_desc', 'stock_asc'], true) ? 'filtro-activo' : '' ?>"
                                title="Ordenar por Stock físico"
                                onclick="toggleMenuFiltro(event, 'menu-filtro-stock-fisico')"
                            >
                                <svg class="icono-filtro" viewBox="0 0 16 16" width="12" height="12" fill="currentColor">
                                    <path d="M1.5 2.5a.5.5 0 0 1 .5-.5h12a.5.5 0 0 1 .38.82L9 8.24V13.5a.5.5 0 0 1-.76.42l-2-1.25A.5.5 0 0 1 6 12.25V8.24L1.62 2.82a.5.5 0 0 1-.12-.32z"/>
                                </svg>
                            </button>
                        </div>
                        <div id="menu-filtro-stock-fisico" class="menu-filtro">
                            <a href="<?= urlConFiltros(['orden' => '']) ?>" class="opcion-filtro <?= !in_array($orden, ['stock_desc', 'stock_asc'], true) ? 'seleccionada' : '' ?>">
                                <span class="indicador-check"><?= !in_array($orden, ['stock_desc', 'stock_asc'], true) ? '✓' : '' ?></span> Por defecto
                            </a>
                            <a href="<?= urlConFiltros(['orden' => ($orden === 'stock_desc' ? '' : 'stock_desc')]) ?>" class="opcion-filtro <?= ($orden === 'stock_desc') ? 'seleccionada' : '' ?>">
                                <span class="indicador-check"><?= ($orden === 'stock_desc') ? '✓' : '' ?></span> De mayor a menor (↓)
                            </a>
                            <a href="<?= urlConFiltros(['orden' => ($orden === 'stock_asc' ? '' : 'stock_asc')]) ?>" class="opcion-filtro <?= ($orden === 'stock_asc') ? 'seleccionada' : '' ?>">
                                <span class="indicador-check"><?= ($orden === 'stock_asc') ? '✓' : '' ?></span> De menor a mayor (↑)
                            </a>
                        </div>
                    </th>

                    <!-- FILTRO / ORDEN: STOCK MÍNIMO -->
                    <th class="th-filtrable">
                        <div class="th-contenido">
                            <span>Stock mínimo</span>
                            <button
                                type="button"
                                class="btn-filtro-columna <?= in_array($orden, ['minimo_desc', 'minimo_asc'], true) ? 'filtro-activo' : '' ?>"
                                title="Ordenar por Stock mínimo"
                                onclick="toggleMenuFiltro(event, 'menu-filtro-stock-minimo')"
                            >
                                <svg class="icono-filtro" viewBox="0 0 16 16" width="12" height="12" fill="currentColor">
                                    <path d="M1.5 2.5a.5.5 0 0 1 .5-.5h12a.5.5 0 0 1 .38.82L9 8.24V13.5a.5.5 0 0 1-.76.42l-2-1.25A.5.5 0 0 1 6 12.25V8.24L1.62 2.82a.5.5 0 0 1-.12-.32z"/>
                                </svg>
                            </button>
                        </div>
                        <div id="menu-filtro-stock-minimo" class="menu-filtro">
                            <a href="<?= urlConFiltros(['orden' => '']) ?>" class="opcion-filtro <?= !in_array($orden, ['minimo_desc', 'minimo_asc'], true) ? 'seleccionada' : '' ?>">
                                <span class="indicador-check"><?= !in_array($orden, ['minimo_desc', 'minimo_asc'], true) ? '✓' : '' ?></span> Por defecto
                            </a>
                            <a href="<?= urlConFiltros(['orden' => ($orden === 'minimo_desc' ? '' : 'minimo_desc')]) ?>" class="opcion-filtro <?= ($orden === 'minimo_desc') ? 'seleccionada' : '' ?>">
                                <span class="indicador-check"><?= ($orden === 'minimo_desc') ? '✓' : '' ?></span> De mayor a menor (↓)
                            </a>
                            <a href="<?= urlConFiltros(['orden' => ($orden === 'minimo_asc' ? '' : 'minimo_asc')]) ?>" class="opcion-filtro <?= ($orden === 'minimo_asc') ? 'seleccionada' : '' ?>">
                                <span class="indicador-check"><?= ($orden === 'minimo_asc') ? '✓' : '' ?></span> De menor a mayor (↑)
                            </a>
                        </div>
                    </th>

                    <!-- FILTRO TIPO: BAND. STOCK -->
                    <th class="th-filtrable">
                        <div class="th-contenido">
                            <span>Band. stock</span>
                            <button
                                type="button"
                                class="btn-filtro-columna <?= ($filtroStock !== '' || $filtroCatalogo === 'no_encontrado') ? 'filtro-activo' : '' ?>"
                                title="Filtrar por Stock"
                                onclick="toggleMenuFiltro(event, 'menu-filtro-stock')"
                            >
                                <svg class="icono-filtro" viewBox="0 0 16 16" width="12" height="12" fill="currentColor">
                                    <path d="M1.5 2.5a.5.5 0 0 1 .5-.5h12a.5.5 0 0 1 .38.82L9 8.24V13.5a.5.5 0 0 1-.76.42l-2-1.25A.5.5 0 0 1 6 12.25V8.24L1.62 2.82a.5.5 0 0 1-.12-.32z"/>
                                </svg>
                            </button>
                        </div>
                        <div id="menu-filtro-stock" class="menu-filtro">
                            <a href="<?= urlConFiltros(['stock' => '', 'catalogo' => '']) ?>" class="opcion-filtro <?= ($filtroStock === '' && $filtroCatalogo === '') ? 'seleccionada' : '' ?>">
                                <span class="indicador-check"><?= ($filtroStock === '' && $filtroCatalogo === '') ? '✓' : '' ?></span> Todas las opciones
                            </a>
                            <a href="<?= urlConFiltros(['stock' => '0', 'catalogo' => '']) ?>" class="opcion-filtro <?= ($filtroStock === '0') ? 'seleccionada' : '' ?>">
                                <span class="indicador-check"><?= ($filtroStock === '0') ? '✓' : '' ?></span> Con Stock
                            </a>
                            <a href="<?= urlConFiltros(['stock' => '1', 'catalogo' => '']) ?>" class="opcion-filtro <?= ($filtroStock === '1') ? 'seleccionada' : '' ?>">
                                <span class="indicador-check"><?= ($filtroStock === '1') ? '✓' : '' ?></span> Sin Stock
                            </a>
                            <?php if ($totales['no_encontrados'] > 0): ?>
                                <a href="<?= urlConFiltros(['catalogo' => 'no_encontrado', 'stock' => '', 'correo' => '']) ?>" class="opcion-filtro <?= ($filtroCatalogo === 'no_encontrado') ? 'seleccionada' : '' ?>">
                                    <span class="indicador-check"><?= ($filtroCatalogo === 'no_encontrado') ? '✓' : '' ?></span> No encontrado en catálogo
                                </a>
                            <?php endif; ?>
                        </div>
                    </th>

                    <!-- FILTRO TIPO: BAND. CORREO -->
                    <th class="th-filtrable">
                        <div class="th-contenido">
                            <span>Band. correo</span>
                            <button
                                type="button"
                                class="btn-filtro-columna <?= ($filtroCorreo !== '') ? 'filtro-activo' : '' ?>"
                                title="Filtrar por Correo"
                                onclick="toggleMenuFiltro(event, 'menu-filtro-correo')"
                            >
                                <svg class="icono-filtro" viewBox="0 0 16 16" width="12" height="12" fill="currentColor">
                                    <path d="M1.5 2.5a.5.5 0 0 1 .5-.5h12a.5.5 0 0 1 .38.82L9 8.24V13.5a.5.5 0 0 1-.76.42l-2-1.25A.5.5 0 0 1 6 12.25V8.24L1.62 2.82a.5.5 0 0 1-.12-.32z"/>
                                </svg>
                            </button>
                        </div>
                        <div id="menu-filtro-correo" class="menu-filtro">
                            <a href="<?= urlConFiltros(['correo' => '', 'catalogo' => '']) ?>" class="opcion-filtro <?= ($filtroCorreo === '') ? 'seleccionada' : '' ?>">
                                <span class="indicador-check"><?= ($filtroCorreo === '') ? '✓' : '' ?></span> Todas las opciones
                            </a>
                            <a href="<?= urlConFiltros(['correo' => '1', 'catalogo' => '']) ?>" class="opcion-filtro <?= ($filtroCorreo === '1') ? 'seleccionada' : '' ?>">
                                <span class="indicador-check"><?= ($filtroCorreo === '1') ? '✓' : '' ?></span> Correo enviado
                            </a>
                            <a href="<?= urlConFiltros(['correo' => '0', 'catalogo' => '']) ?>" class="opcion-filtro <?= ($filtroCorreo === '0') ? 'seleccionada' : '' ?>">
                                <span class="indicador-check"><?= ($filtroCorreo === '0') ? '✓' : '' ?></span> Correo no enviado
                            </a>
                        </div>
                    </th>

                    <!-- FILTRO: ESTADO (DISPONIBILIDAD) -->
                    <th class="th-filtrable">
                        <div class="th-contenido">
                            <span>Estado</span>
                            <button
                                type="button"
                                class="btn-filtro-columna <?= ($filtroDisponible !== '') ? 'filtro-activo' : '' ?>"
                                title="Filtrar por Estado"
                                onclick="toggleMenuFiltro(event, 'menu-filtro-estado')"
                            >
                                <svg class="icono-filtro" viewBox="0 0 16 16" width="12" height="12" fill="currentColor">
                                    <path d="M1.5 2.5a.5.5 0 0 1 .5-.5h12a.5.5 0 0 1 .38.82L9 8.24V13.5a.5.5 0 0 1-.76.42l-2-1.25A.5.5 0 0 1 6 12.25V8.24L1.62 2.82a.5.5 0 0 1-.12-.32z"/>
                                </svg>
                            </button>
                        </div>
                        <div id="menu-filtro-estado" class="menu-filtro">
                            <a href="<?= urlConFiltros(['disponible' => '']) ?>" class="opcion-filtro <?= ($filtroDisponible === '') ? 'seleccionada' : '' ?>">
                                <span class="indicador-check"><?= ($filtroDisponible === '') ? '✓' : '' ?></span> Todas las opciones
                            </a>
                            <a href="<?= urlConFiltros(['disponible' => '1']) ?>" class="opcion-filtro <?= ($filtroDisponible === '1') ? 'seleccionada' : '' ?>">
                                <span class="indicador-check"><?= ($filtroDisponible === '1') ? '✓' : '' ?></span> Disponible
                            </a>
                            <a href="<?= urlConFiltros(['disponible' => '0']) ?>" class="opcion-filtro <?= ($filtroDisponible === '0') ? 'seleccionada' : '' ?>">
                                <span class="indicador-check"><?= ($filtroDisponible === '0') ? '✓' : '' ?></span> No disponible
                            </a>
                        </div>
                    </th>

                    <th>Fecha actualización</th>
                    <th>Acciones</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($registros)): ?>
                    <tr>
                        <td colspan="11" class="tabla-vacia">
                            <?= $hayFiltrosActivos
                                ? 'No existen configuraciones que coincidan con los filtros aplicados en la base de datos.'
                                : 'No existen configuraciones registradas.' ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($registros as $registro): ?>
                        <?php
                        $estaDisponible = ((int) ($registro['disponible'] ?? 1) === 1);
                        $artCodigo = trim((string) ($registro['art_codigo'] ?? ''));
                        $artNombre = trim((string) ($registro['art_nombre'] ?? ''));
                        $artEncontrado = !empty($registro['mae_art_codigo']);
                        ?>
                        <tr class="<?= $estaDisponible ? '' : 'fila-inactiva' ?>"
                            data-codigo="<?= h($artCodigo) ?>"
                            data-nombre="<?= h($artNombre) ?>">
                            <td>
                                <strong>
                                    <?= $artEncontrado ? h($artNombre) : '<span class="badge badge-alerta" title="El artículo no existe en el catálogo maestro (mae_articulo)">No encontrado</span>' ?>
                                </strong>
                                <span class="articulo-titulo">
                                    <?= h($artCodigo) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($artEncontrado && !empty($registro['categoria'])): ?>
                                    <span class="texto-clasificacion"><?= h($registro['categoria']) ?></span>
                                <?php else: ?>
                                    <span class="badge badge-sin-datos" title="Sin categoría">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($artEncontrado && !empty($registro['fam_nombre'])): ?>
                                    <span class="texto-clasificacion"><?= h($registro['fam_nombre']) ?></span>
                                <?php else: ?>
                                    <span class="badge badge-sin-datos" title="Sin familia">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($artEncontrado && !empty($registro['tfa_nombre'])): ?>
                                    <span class="texto-clasificacion"><?= h($registro['tfa_nombre']) ?></span>
                                <?php else: ?>
                                    <span class="badge badge-sin-datos" title="Sin tipo de familia">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$artEncontrado): ?>
                                    <span class="badge badge-sin-datos" title="No existe en el catálogo maestro">—</span>
                                <?php elseif ($registro['stock_actual'] === null): ?>
                                    <span class="badge badge-sin-datos">
                                    NULL
                                    </span>
                                <?php else: ?>
                                    <strong>
                                        <?= h(
                                                number_format(
                                                        (float) $registro['stock_actual'],
                                                        5,
                                                        '.',
                                                        ','
                                                )
                                        ) . (!empty($registro['unidad_medida']) ? ' ' . h($registro['unidad_medida']) : '') ?>
                                    </strong>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= h(
                                        number_format(
                                                (float) $registro['stock_minimo'],
                                                        5,
                                                        '.',
                                                        ','
                                        )
                                ) . (!empty($registro['unidad_medida']) ? ' ' . h($registro['unidad_medida']) : '') ?>
                            </td>
                            <td>
                                <?php if (!$artEncontrado): ?>
                                    <span class="badge badge-sin-datos" title="No existe en el catálogo maestro">—</span>
                                <?php elseif ($registro['band_stock']): ?>
                                    <span class="badge badge-alerta">Alerta de stock</span>
                                <?php else: ?>
                                    <span class="badge badge-activo">Con Stock</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$artEncontrado): ?>
                                    <span class="badge badge-sin-datos" title="No existe en el catálogo maestro">—</span>
                                <?php elseif ($registro['band_correo']): ?>
                                    <span class="badge badge-alerta">Correo enviado</span>
                                <?php else: ?>
                                    <span class="badge badge-inactivo">Correo no enviado</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($estaDisponible): ?>
                                    <span class="badge badge-activo">Disponible</span>
                                <?php else: ?>
                                    <span class="badge badge-inactivo">No disponible</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $fecha = $registro['fecha_actualizacion'];
                                if ($fecha instanceof DateTimeInterface) {
                                    echo '<div class="fecha-contenedor">';
                                    echo '<span class="fecha-dia">' . h($fecha->format('d/m/Y')) . '</span>';
                                    echo '<span class="fecha-hora">' . h($fecha->format('H:i:s')) . '</span>';
                                    echo '</div>';
                                } else {
                                    echo h($fecha);
                                }
                                ?>
                            </td>
                            <td>
                                <div class="acciones-celda">
                                    <a
                                        href="editar.php?emp=<?= urlencode(trim($registro['emp_codigo'])) ?>&art=<?= urlencode(trim($registro['art_codigo'])) ?>&alm=<?= urlencode(trim($registro['alm_codigo'])) ?>"
                                        class="btn btn-editar"
                                    >
                                        Editar
                                    </a>

                                    <?php if ($estaDisponible): ?>
                                        <!-- Borrado lógico (Desactivar) -->
                                        <form
                                            action="cambiar_estado.php"
                                            method="POST"
                                            class="form-eliminar"
                                        >
                                            <input
                                                type="hidden"
                                                name="emp_codigo"
                                                value="<?= h(trim($registro['emp_codigo'])) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="art_codigo"
                                                value="<?= h(trim($registro['art_codigo'])) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="alm_codigo"
                                                value="<?= h(trim($registro['alm_codigo'])) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="art_nombre"
                                                value="<?= h(trim((string) ($registro['art_nombre'] ?? ''))) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="categoria"
                                                value="<?= h(trim((string) ($registro['categoria'] ?? ''))) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="fam_nombre"
                                                value="<?= h(trim((string) ($registro['fam_nombre'] ?? ''))) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="tfa_nombre"
                                                value="<?= h(trim((string) ($registro['tfa_nombre'] ?? ''))) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="accion"
                                                value="eliminar"
                                            >
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= h(generarTokenCsrf()) ?>"
                                            >
                                            <button
                                                type="submit"
                                                class="btn btn-eliminar"
                                            >
                                                Desactivar
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <!-- Reactivar registro -->
                                        <form
                                            action="cambiar_estado.php"
                                            method="POST"
                                            class="form-eliminar"
                                        >
                                            <input
                                                type="hidden"
                                                name="emp_codigo"
                                                value="<?= h(trim($registro['emp_codigo'])) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="art_codigo"
                                                value="<?= h(trim($registro['art_codigo'])) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="alm_codigo"
                                                value="<?= h(trim($registro['alm_codigo'])) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="art_nombre"
                                                value="<?= h(trim((string) ($registro['art_nombre'] ?? ''))) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="categoria"
                                                value="<?= h(trim((string) ($registro['categoria'] ?? ''))) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="fam_nombre"
                                                value="<?= h(trim((string) ($registro['fam_nombre'] ?? ''))) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="tfa_nombre"
                                                value="<?= h(trim((string) ($registro['tfa_nombre'] ?? ''))) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="accion"
                                                value="reactivar"
                                            >
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= h(generarTokenCsrf()) ?>"
                                            >
                                            <button
                                                type="submit"
                                                class="btn btn-reactivar"
                                            >
                                                Reactivar
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>

<script src="js/index.js?v=<?= file_exists(__DIR__ . '/js/index.js') ? filemtime(__DIR__ . '/js/index.js') : time() ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    iniciarIndex({
        mensajeExito: <?= json_encode($mensajeExito, JSON_UNESCAPED_UNICODE) ?>,
        mensajeAdvertencia: <?= json_encode($mensajeAdvertencia, JSON_UNESCAPED_UNICODE) ?>,
        error: <?= json_encode($error, JSON_UNESCAPED_UNICODE) ?>,
        hayFiltrosActivos: <?= json_encode($hayFiltrosActivos) ?>,
        totalRegistros: <?= count($registros) ?>
    });
});
</script>
</body>
</html>

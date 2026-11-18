<?php

declare(strict_types=1);

use Manue\SistemaNotificaciones\StockRepository;

// 1.1 Protección del formulario de eliminación y componentes visuales
require_once __DIR__ . '/seguridad.php';
require_once __DIR__ . '/includes/componentes_index.php';

// 2. Cargar las variables de entorno.
$raizProyecto = dirname(__DIR__);

// Validar autorización y recuperar almacén activo
$almActivo = requerirAlmacenAutorizado($_GET['alm'] ?? null);
$nombreAlmacen = getNombreAlmacen($almActivo);

$conexion = null;
$registros = [];
$totalRegistrosFiltrados = 0;
$paginaActual = 1;
$totalPaginas = 1;
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

$busqueda = substr(trim((string) ($_GET['q'] ?? '')), 0, 50);
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$porPaginaRaw = (int) ($_GET['por_pagina'] ?? 10);
$porPagina = in_array($porPaginaRaw, [10, 15, 25, 50], true) ? $porPaginaRaw : 10;
$esAjax = (($_GET['ajax'] ?? '') === '1');

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
    if (!$esAjax) {
        $totales = $repositorio->obtenerTotalesConfiguracion($almActivo);
    }

    // 6. Registros del almacén activo paginados con filtros, búsqueda y orden en BD
    $resultadoPaginado = $repositorio->listarConfiguracionPaginada($almActivo, [
        'disponible' => $filtroDisponible,
        'catalogo' => $filtroCatalogo,
        'stock' => $filtroStock,
        'correo' => $filtroCorreo,
        'categoria' => $filtroCategoria,
        'familia' => $filtroFamilia,
        'tfa' => $filtroTfa,
        'busqueda' => $busqueda
    ], $orden, $pagina, $porPagina);

    $registros = $resultadoPaginado['items'];
    $totalRegistrosFiltrados = $resultadoPaginado['total'];
    $paginaActual = $resultadoPaginado['pagina'];
    $porPagina = $resultadoPaginado['por_pagina'];
    $totalPaginas = $resultadoPaginado['total_paginas'];

    // 7. Opciones de Detalle (Categoría, Familia, Tipo familia) para los filtros
    if (!$esAjax) {
        $opcionesDetalle = $repositorio->obtenerOpcionesClasificacion($almActivo);
        $opcionesCategoria = $opcionesDetalle['categorias'];
        $opcionesFamilia = $opcionesDetalle['familias'];
        $opcionesTfa = $opcionesDetalle['tipos_familia'];
    }

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
        unset($parametros['ok'], $parametros['error'], $parametros['ajax']);

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
    $orden !== '' ||
    $busqueda !== ''
);

$urlPagina = static fn(int $p): string => urlConFiltros(['pagina' => $p > 1 ? (string) $p : '']);

if ($esAjax) {
    header('Content-Type: application/json; charset=utf-8');
    if ($error !== null) {
        http_response_code(500);
        echo json_encode([
            'exito' => false,
            'error' => $error,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    ob_start();
    renderFilasTablaConfiguracion($registros, $hayFiltrosActivos);
    $tbodyHtml = (string) ob_get_clean();

    ob_start();
    renderPaginacionIndex($paginaActual, $totalPaginas, $totalRegistrosFiltrados, $porPagina, $urlPagina);
    $paginacionHtml = (string) ob_get_clean();

    ob_start();
    renderResumenFiltrosIndex($hayFiltrosActivos, $busqueda, $totalRegistrosFiltrados);
    $resumenHtml = trim((string) ob_get_clean());

    echo json_encode([
        'exito' => true,
        'tbody_html' => $tbodyHtml,
        'paginacion_html' => $paginacionHtml,
        'resumen_html' => $resumenHtml,
        'hay_filtros_activos' => $hayFiltrosActivos,
        'total_registros' => $totalRegistrosFiltrados,
        'pagina' => $paginaActual,
        'por_pagina' => $porPagina,
        'total_paginas' => $totalPaginas,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

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
    <title>Almacén <?= h(getNombreAlmacen($almActivo)) ?> - Alertas de Stock</title>
    <script src="<?= h(assetUrl('js/comun.js')) ?>"></script>
    <link rel="stylesheet" href="<?= h(assetUrl('css/estilos.css')) ?>">
    <link rel="stylesheet" href="<?= h(assetUrl('css/index.css')) ?>">
    <script src="<?= h(assetUrl('js/sweetalert2.all.min.js')) ?>"></script>
</head>
<body>
<main class="contenedor">
    <?php
    $tituloEncabezado = 'ALMACÉN - ' . strtoupper(getNombreAlmacen($almActivo));
    $subtituloEncabezado = 'Sistema de Alertas y Monitoreo de Stock Mínimo';
    ob_start();
    ?>
        <a href="seleccionar_almacen.php" class="btn btn-secundario" title="Cambiar de almacén">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                <polyline points="9 22 9 12 15 12 15 22"></polyline>
            </svg>
            Cambiar Almacén
        </a>
        <form action="ejecutar_manual.php" method="POST" class="form-ejecutar-manual" title="revisar stock manualmente">
            <input type="hidden" name="csrf_token" value="<?= h(generarTokenCsrf()) ?>">
            <input type="hidden" name="almacen" value="<?= h($almActivo) ?>">
            <button type="submit" class="btn btn-ejecutar">
                Revisar Stock
            </button>
        </form>
        <a href="crear.php" class="btn btn-primario" title="Crear nuevo registro">
            + Nuevo registro
        </a>
    <?php
    $accionesEncabezadoHtml = ob_get_clean();
    require __DIR__ . '/includes/encabezado_usuario.php';
    ?>

    <?php if ($error === null || !empty($registros) || $hayFiltrosActivos): ?>
        <!-- PANEL RESUMEN CON LOS TOTALES DE LOS ARTÍCULOS -->
        <?php require __DIR__ . '/includes/tarjetas_resumen.php'; ?>
    <?php endif; ?>

    <!-- PANEL DE BÚSQUEDA, TABLA Y PAGINACIÓN (SOPORTA PANTALLA COMPLETA) -->
    <div id="panel-tabla-index" class="panel-tabla-index">
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
                <input type="hidden" name="por_pagina" id="hidden-por-pagina" value="<?= (int) $porPagina ?>">

                <div class="busqueda-campo busqueda-campo-texto">
                    <label for="articulo">
                        Buscar artículo por código o nombre
                    </label>
                    <input
                            type="search"
                            id="articulo"
                            name="q"
                            maxlength="50"
                            value="<?= h($busqueda) ?>"
                            placeholder="020100427 | ROJO BANDERA 409"
                            autocomplete="off"
                    >
                </div>
                <div class="busqueda-acciones">
                    <a
                            href="index.php"
                            id="btn-limpiar-filtros"
                            class="btn btn-secundario"
                            style="<?= $hayFiltrosActivos ? '' : 'display: none;' ?>"
                    >
                        Limpiar filtros
                    </a>
                    <?php if ($error === null || !empty($registros) || $hayFiltrosActivos): ?>
                        <button
                                type="button"
                                id="btn-pantalla-completa"
                                class="btn btn-secundario btn-accion-icono btn-pantalla-completa"
                                aria-pressed="false"
                                title="Ver tabla en pantalla completa"
                                aria-label="Ver tabla en pantalla completa"
                        >
                            <svg class="icono-expandir" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <polyline points="15 3 21 3 21 9"></polyline>
                                <polyline points="9 21 3 21 3 15"></polyline>
                                <line x1="21" y1="3" x2="14" y2="10"></line>
                                <line x1="3" y1="21" x2="10" y2="14"></line>
                            </svg>
                            <svg class="icono-contraer" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <polyline points="4 14 10 14 10 20"></polyline>
                                <polyline points="20 10 14 10 14 4"></polyline>
                                <line x1="14" y1="10" x2="21" y2="3"></line>
                                <line x1="3" y1="21" x2="10" y2="14"></line>
                            </svg>
                        </button>
                    <?php endif; ?>
                    <a
                            href="<?= h(urlConFiltros()) ?>"
                            id="btn-recargar-pagina"
                            class="btn btn-secundario btn-accion-icono"
                            title="Recargar página"
                            aria-label="Recargar página"
                    >
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <polyline points="23 4 23 10 17 10"></polyline>
                            <polyline points="1 20 1 14 7 14"></polyline>
                            <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                        </svg>
                    </a>
                </div>
            </form>

            <p id="busqueda-resultados" class="busqueda-resultados" style="<?= $hayFiltrosActivos ? '' : 'display: none;' ?>">
                <?php renderResumenFiltrosIndex($hayFiltrosActivos, $busqueda, $totalRegistrosFiltrados); ?>
            </p>
        </div>

        <?php if ($error === null || !empty($registros) || $hayFiltrosActivos): ?>
            <div class="tabla-contenedor">
                <table>
                    <thead>
                    <tr>
                        <th>Artículo</th>
                        <?php
                        $construirOpcionesClasificacion = static function (string $claveParam, string $valorActivo, array $lista): array {
                            $opciones = [[
                                'href' => urlConFiltros([$claveParam => '', 'pagina' => '']),
                                'seleccionada' => ($valorActivo === ''),
                                'texto' => 'Todas las opciones',
                            ]];
                            foreach ($lista as $item) {
                                $opciones[] = [
                                    'href' => urlConFiltros([$claveParam => ($valorActivo === $item ? '' : $item), 'pagina' => '']),
                                    'seleccionada' => ($valorActivo === $item),
                                    'texto' => $item,
                                    'title' => $item,
                                ];
                            }
                            return $opciones;
                        };

                        renderThFiltro(
                            'Categoría',
                            'menu-filtro-categoria',
                            $filtroCategoria !== '',
                            'Filtrar por Categoría',
                            $construirOpcionesClasificacion('categoria', $filtroCategoria, $opcionesCategoria)
                        );
                        renderThFiltro(
                            'Familia',
                            'menu-filtro-familia',
                            $filtroFamilia !== '',
                            'Filtrar por Familia',
                            $construirOpcionesClasificacion('familia', $filtroFamilia, $opcionesFamilia)
                        );
                        renderThFiltro(
                            'Tipo familia',
                            'menu-filtro-tfa',
                            $filtroTfa !== '',
                            'Filtrar por Tipo familia',
                            $construirOpcionesClasificacion('tfa', $filtroTfa, $opcionesTfa)
                        );

                        renderThFiltro(
                            'Stock físico',
                            'menu-filtro-stock-fisico',
                            in_array($orden, ['stock_desc', 'stock_asc'], true),
                            'Ordenar por Stock físico',
                            [
                                [
                                    'href' => urlConFiltros(['orden' => '', 'pagina' => '']),
                                    'seleccionada' => !in_array($orden, ['stock_desc', 'stock_asc'], true),
                                    'texto' => 'Por defecto',
                                ],
                                [
                                    'href' => urlConFiltros(['orden' => ($orden === 'stock_desc' ? '' : 'stock_desc'), 'pagina' => '']),
                                    'seleccionada' => ($orden === 'stock_desc'),
                                    'texto' => 'De mayor a menor (↓)',
                                ],
                                [
                                    'href' => urlConFiltros(['orden' => ($orden === 'stock_asc' ? '' : 'stock_asc'), 'pagina' => '']),
                                    'seleccionada' => ($orden === 'stock_asc'),
                                    'texto' => 'De menor a mayor (↑)',
                                ],
                            ]
                        );

                        renderThFiltro(
                            'Stock mínimo',
                            'menu-filtro-stock-minimo',
                            in_array($orden, ['minimo_desc', 'minimo_asc'], true),
                            'Ordenar por Stock mínimo',
                            [
                                [
                                    'href' => urlConFiltros(['orden' => '', 'pagina' => '']),
                                    'seleccionada' => !in_array($orden, ['minimo_desc', 'minimo_asc'], true),
                                    'texto' => 'Por defecto',
                                ],
                                [
                                    'href' => urlConFiltros(['orden' => ($orden === 'minimo_desc' ? '' : 'minimo_desc'), 'pagina' => '']),
                                    'seleccionada' => ($orden === 'minimo_desc'),
                                    'texto' => 'De mayor a menor (↓)',
                                ],
                                [
                                    'href' => urlConFiltros(['orden' => ($orden === 'minimo_asc' ? '' : 'minimo_asc'), 'pagina' => '']),
                                    'seleccionada' => ($orden === 'minimo_asc'),
                                    'texto' => 'De menor a mayor (↑)',
                                ],
                            ]
                        );

                        $opcionesFiltroStock = [
                            [
                                'href' => urlConFiltros(['stock' => '', 'catalogo' => '', 'pagina' => '']),
                                'seleccionada' => ($filtroStock === '' && $filtroCatalogo === ''),
                                'texto' => 'Todas las opciones',
                            ],
                            [
                                'href' => urlConFiltros(['stock' => '0', 'catalogo' => '', 'pagina' => '']),
                                'seleccionada' => ($filtroStock === '0'),
                                'texto' => 'Con Stock',
                            ],
                            [
                                'href' => urlConFiltros(['stock' => '1', 'catalogo' => '', 'pagina' => '']),
                                'seleccionada' => ($filtroStock === '1'),
                                'texto' => 'Sin Stock',
                            ],
                        ];
                        if ($totales['no_encontrados'] > 0) {
                            $opcionesFiltroStock[] = [
                                'href' => urlConFiltros(['catalogo' => 'no_encontrado', 'stock' => '', 'correo' => '', 'pagina' => '']),
                                'seleccionada' => ($filtroCatalogo === 'no_encontrado'),
                                'texto' => 'No encontrado en catálogo',
                            ];
                        }
                        renderThFiltro(
                            'Band. stock',
                            'menu-filtro-stock',
                            ($filtroStock !== '' || $filtroCatalogo === 'no_encontrado'),
                            'Filtrar por Stock',
                            $opcionesFiltroStock
                        );

                        renderThFiltro(
                            'Band. correo',
                            'menu-filtro-correo',
                            $filtroCorreo !== '',
                            'Filtrar por Correo',
                            [
                                [
                                    'href' => urlConFiltros(['correo' => '', 'catalogo' => '', 'pagina' => '']),
                                    'seleccionada' => ($filtroCorreo === ''),
                                    'texto' => 'Todas las opciones',
                                ],
                                [
                                    'href' => urlConFiltros(['correo' => '1', 'catalogo' => '', 'pagina' => '']),
                                    'seleccionada' => ($filtroCorreo === '1'),
                                    'texto' => 'Correo enviado',
                                ],
                                [
                                    'href' => urlConFiltros(['correo' => '0', 'catalogo' => '', 'pagina' => '']),
                                    'seleccionada' => ($filtroCorreo === '0'),
                                    'texto' => 'Correo no enviado',
                                ],
                            ]
                        );

                        renderThFiltro(
                            'Estado',
                            'menu-filtro-estado',
                            $filtroDisponible !== '',
                            'Filtrar por Estado',
                            [
                                [
                                    'href' => urlConFiltros(['disponible' => '', 'pagina' => '']),
                                    'seleccionada' => ($filtroDisponible === ''),
                                    'texto' => 'Todas las opciones',
                                ],
                                [
                                    'href' => urlConFiltros(['disponible' => '1', 'pagina' => '']),
                                    'seleccionada' => ($filtroDisponible === '1'),
                                    'texto' => 'Disponible',
                                ],
                                [
                                    'href' => urlConFiltros(['disponible' => '0', 'pagina' => '']),
                                    'seleccionada' => ($filtroDisponible === '0'),
                                    'texto' => 'No disponible',
                                ],
                            ]
                        );
                        ?>

                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody id="tabla-configuraciones-body">
                    <?php renderFilasTablaConfiguracion($registros, $hayFiltrosActivos); ?>
                    </tbody>
                </table>
            </div>

            <div id="paginacion-index">
                <?php renderPaginacionIndex($paginaActual, $totalPaginas, $totalRegistrosFiltrados, $porPagina, $urlPagina); ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<script src="<?= h(assetUrl('js/index.js')) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    iniciarIndex({
        mensajeExito: <?= json_encode($mensajeExito, JSON_UNESCAPED_UNICODE) ?>,
        mensajeAdvertencia: <?= json_encode($mensajeAdvertencia, JSON_UNESCAPED_UNICODE) ?>,
        error: <?= json_encode($error, JSON_UNESCAPED_UNICODE) ?>,
        hayFiltrosActivos: <?= json_encode($hayFiltrosActivos) ?>,
        totalRegistros: <?= (int) $totalRegistrosFiltrados ?>,
        pagina: <?= (int) $paginaActual ?>,
        porPagina: <?= (int) $porPagina ?>,
        totalPaginas: <?= (int) $totalPaginas ?>,
        busqueda: <?= json_encode($busqueda, JSON_UNESCAPED_UNICODE) ?>
    });
});
</script>
</body>
</html>

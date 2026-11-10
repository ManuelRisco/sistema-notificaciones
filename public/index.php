<?php

declare(strict_types=1);

// 1. Cargar Composer.
require_once dirname(__DIR__) . '/vendor/autoload.php';
// 1.1 Protección del formulario de eliminación
require_once __DIR__ . '/seguridad.php';

// 2. Cargar las variables de entorno.
$raizProyecto = dirname(__DIR__);

$dotenv = Dotenv\Dotenv::createImmutable($raizProyecto);
$dotenv->safeLoad();

// 3. Cargar la conexión a SQL Server.
require_once $raizProyecto . '/config/database.php';

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

    // 4. Obtener la conexión.
    $conexion = getSqlServerConn();

    // 5. Consultar los totales generales directamente en la base de datos
    // Se excluyen los artículos no encontrados de las métricas de stock y correo
    $sqlTotales = "
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN cfg.disponible = 1 THEN 1 ELSE 0 END) AS disponibles,
            SUM(CASE WHEN cfg.disponible = 0 THEN 1 ELSE 0 END) AS no_disponibles,
            SUM(CASE WHEN m.art_codigo IS NOT NULL AND cfg.band_stock = 0 THEN 1 ELSE 0 END) AS con_stock,
            SUM(CASE WHEN m.art_codigo IS NOT NULL AND cfg.band_stock = 1 THEN 1 ELSE 0 END) AS sin_stock,
            SUM(CASE WHEN m.art_codigo IS NOT NULL AND cfg.band_correo = 1 THEN 1 ELSE 0 END) AS correo_enviado,
            SUM(CASE WHEN m.art_codigo IS NOT NULL AND cfg.band_correo = 0 THEN 1 ELSE 0 END) AS correo_no_enviado,
            SUM(CASE WHEN m.art_codigo IS NULL THEN 1 ELSE 0 END) AS no_encontrados
        FROM dbo.cfg_alerta_stock cfg
        LEFT JOIN dbo.mae_articulo m
            ON m.emp_codigo = cfg.emp_codigo
            AND m.art_codigo = cfg.art_codigo;
    ";

    $stmtTotales = sqlsrv_query($conexion, $sqlTotales);
    if ($stmtTotales !== false) {
        $filaTotales = sqlsrv_fetch_array($stmtTotales, SQLSRV_FETCH_ASSOC);
        if ($filaTotales) {
            $totales = [
                'total' => (int) ($filaTotales['total'] ?? 0),
                'disponibles' => (int) ($filaTotales['disponibles'] ?? 0),
                'no_disponibles' => (int) ($filaTotales['no_disponibles'] ?? 0),
                'con_stock' => (int) ($filaTotales['con_stock'] ?? 0),
                'sin_stock' => (int) ($filaTotales['sin_stock'] ?? 0),
                'correo_enviado' => (int) ($filaTotales['correo_enviado'] ?? 0),
                'correo_no_enviado' => (int) ($filaTotales['correo_no_enviado'] ?? 0),
                'no_encontrados' => (int) ($filaTotales['no_encontrados'] ?? 0)
            ];
        }
        sqlsrv_free_stmt($stmtTotales);
    }

    // 6. Construir condiciones WHERE dinámicas para filtrar en SQL Server
    $condiciones = [];
    $params = [];

    if ($filtroDisponible !== '') {
        $condiciones[] = "cfg.disponible = ?";
        $params[] = (int) $filtroDisponible;
    }

    if ($filtroCatalogo === 'no_encontrado') {
        $condiciones[] = "m.art_codigo IS NULL";
    }

    if ($filtroStock !== '') {
        $condiciones[] = "cfg.band_stock = ? AND m.art_codigo IS NOT NULL";
        $params[] = (int) $filtroStock;
    }

    if ($filtroCorreo !== '') {
        $condiciones[] = "cfg.band_correo = ? AND m.art_codigo IS NOT NULL";
        $params[] = (int) $filtroCorreo;
    }

    if ($filtroCategoria !== '') {
        $condiciones[] = "RTRIM(l.lin_nombre) = ?";
        $params[] = $filtroCategoria;
    }

    if ($filtroFamilia !== '') {
        $condiciones[] = "RTRIM(f.fam_nombre) = ?";
        $params[] = $filtroFamilia;
    }

    if ($filtroTfa !== '') {
        $condiciones[] = "RTRIM(tf.tfa_nombre) = ?";
        $params[] = $filtroTfa;
    }

    $clausulaWhere = !empty($condiciones) ? 'WHERE ' . implode(' AND ', $condiciones) : '';

    $clausulaOrderBy = match ($orden) {
        'stock_desc'  => 'ORDER BY ISNULL(s.stock_actual, 0) DESC, cfg.disponible DESC, cfg.art_codigo ASC',
        'stock_asc'   => 'ORDER BY ISNULL(s.stock_actual, 0) ASC, cfg.disponible DESC, cfg.art_codigo ASC',
        'minimo_desc' => 'ORDER BY ISNULL(cfg.stock_minimo, 0) DESC, cfg.disponible DESC, cfg.art_codigo ASC',
        'minimo_asc'  => 'ORDER BY ISNULL(cfg.stock_minimo, 0) ASC, cfg.disponible DESC, cfg.art_codigo ASC',
        default       => 'ORDER BY cfg.disponible DESC, cfg.emp_codigo, cfg.art_codigo, cfg.alm_codigo',
    };

    // 7. Calcular el stock físico de cada artículo configurado (filtrado en BD)
    $sql = "
        ;WITH StockCalculado AS
        (
            SELECT
                cfg.emp_codigo,
                a.art_codigo,
                b.alm_codigo,

                SUM(
                    CASE
                        WHEN b.mov_tipo = 'I'
                            THEN a.mov_ctdmov
                        ELSE 0
                    END
                ) -
                SUM(
                    CASE
                        WHEN b.mov_tipo = 'S'
                            THEN a.mov_ctdmov
                        ELSE 0
                    END
                ) AS stock_actual

            FROM dbo.cfg_alerta_stock cfg

            INNER JOIN dbo.log_detmov a
                ON a.emp_codigo = cfg.emp_codigo
                AND a.art_codigo = cfg.art_codigo

            INNER JOIN dbo.log_cabmov b
                ON a.mov_id = b.mov_id
                AND b.alm_codigo = cfg.alm_codigo

            WHERE b.mov_flag <> 'A'
              AND b.mov_fecemi <= GETDATE()

            GROUP BY
                cfg.emp_codigo,
                a.art_codigo,
                b.alm_codigo
        )

        SELECT
            cfg.emp_codigo,
            cfg.art_codigo,
            cfg.alm_codigo,
            cfg.stock_minimo,
            cfg.band_stock,
            cfg.band_correo,
            cfg.disponible,

            m.art_codigo AS mae_art_codigo,
            RTRIM(m.art_nombre) AS art_nombre,
            RTRIM(d.ume_abrv) AS unidad_medida,
            RTRIM(l.lin_nombre) AS categoria,
            RTRIM(f.fam_nombre) AS fam_nombre,
            RTRIM(tf.tfa_nombre) AS tfa_nombre,

            s.stock_actual AS stock_actual,
            cfg.fecha_actualizacion

        FROM dbo.cfg_alerta_stock cfg

        LEFT JOIN StockCalculado s
            ON s.emp_codigo = cfg.emp_codigo
            AND s.art_codigo = cfg.art_codigo
            AND s.alm_codigo = cfg.alm_codigo

        LEFT JOIN dbo.mae_articulo m
            ON m.emp_codigo = cfg.emp_codigo
            AND m.art_codigo = cfg.art_codigo

        LEFT JOIN dbo.mae_cabumedida d
            ON d.ume_codigo = m.ume_codigo

        LEFT JOIN dbo.mae_linea l
            ON l.lin_codigo = m.lin_codigo

        LEFT JOIN dbo.mae_familia f
            ON f.emp_codigo = m.emp_codigo
            AND f.tar_codigo = m.tar_codigo
            AND f.fam_codigo = m.fam_codigo

        LEFT JOIN dbo.mae_tpofamilia tf
            ON tf.emp_codigo = m.emp_codigo
            AND tf.tar_codigo = m.tar_codigo
            AND tf.fam_codigo = m.fam_codigo
            AND tf.tfa_codigo = m.tfa_codigo

        {$clausulaWhere}

        {$clausulaOrderBy};
    ";

    $stmt = sqlsrv_query($conexion, $sql, $params);

    if ($stmt === false) {
        $errores = sqlsrv_errors(SQLSRV_ERR_ALL);

        throw new RuntimeException(
            'No se pudo consultar la configuración de stock: ' .
            print_r($errores, true)
        );
    }

    // 8. Recuperar los registros.
    while ($fila = sqlsrv_fetch_array(
        $stmt,
        SQLSRV_FETCH_ASSOC
    )) {
        $registros[] = $fila;
    }

    sqlsrv_free_stmt($stmt);

    // 9. Consultar opciones distintas de Detalle (Categoría, Familia, Tipo familia)
    $sqlOpcionesDetalle = "
        SELECT DISTINCT
            RTRIM(l.lin_nombre) AS categoria,
            RTRIM(f.fam_nombre) AS fam_nombre,
            RTRIM(tf.tfa_nombre) AS tfa_nombre
        FROM dbo.cfg_alerta_stock cfg
        INNER JOIN dbo.mae_articulo m
            ON m.emp_codigo = cfg.emp_codigo
            AND m.art_codigo = cfg.art_codigo
        LEFT JOIN dbo.mae_linea l
            ON l.lin_codigo = m.lin_codigo
        LEFT JOIN dbo.mae_familia f
            ON f.emp_codigo = m.emp_codigo
            AND f.tar_codigo = m.tar_codigo
            AND f.fam_codigo = m.fam_codigo
        LEFT JOIN dbo.mae_tpofamilia tf
            ON tf.emp_codigo = m.emp_codigo
            AND tf.tar_codigo = m.tar_codigo
            AND tf.fam_codigo = m.fam_codigo
            AND tf.tfa_codigo = m.tfa_codigo;
    ";

    $stmtOpciones = sqlsrv_query($conexion, $sqlOpcionesDetalle);
    if ($stmtOpciones !== false) {
        while ($rowOp = sqlsrv_fetch_array($stmtOpciones, SQLSRV_FETCH_ASSOC)) {
            $cat = trim((string)($rowOp['categoria'] ?? ''));
            $fam = trim((string)($rowOp['fam_nombre'] ?? ''));
            $tfa = trim((string)($rowOp['tfa_nombre'] ?? ''));

            if ($cat !== '') {
                $opcionesCategoria[$cat] = true;
            }
            if ($fam !== '') {
                $opcionesFamilia[$fam] = true;
            }
            if ($tfa !== '') {
                $opcionesTfa[$tfa] = true;
            }
        }
        sqlsrv_free_stmt($stmtOpciones);
    }

    $opcionesCategoria = array_keys($opcionesCategoria);
    sort($opcionesCategoria, SORT_NATURAL | SORT_FLAG_CASE);
    $opcionesFamilia = array_keys($opcionesFamilia);
    sort($opcionesFamilia, SORT_NATURAL | SORT_FLAG_CASE);
    $opcionesTfa = array_keys($opcionesTfa);
    sort($opcionesTfa, SORT_NATURAL | SORT_FLAG_CASE);

} catch (Throwable $e) {

    $error = $e->getMessage();

} finally {

    if ($conexion !== null && $conexion !== false) {
        sqlsrv_close($conexion);
    }
}

/**
 * Escapa los datos antes de mostrarlos en HTML.
 */
if (!function_exists('escapar')) {
    function escapar(mixed $valor): string
    {
        return htmlspecialchars(
            (string) $valor,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
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

// Gestión de mensajes de notificación (éxito o error)
$mensajeExito = null;
$ok = $_GET['ok'] ?? '';
$artParam = trim((string) ($_GET['art'] ?? ''));
$nomParam = trim((string) ($_GET['nom'] ?? ''));

$detalleArticuloParam = '';
if ($artParam !== '') {
    $detalleArticuloParam = ' para el artículo <strong>' . htmlspecialchars($artParam, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>';
    if ($nomParam !== '') {
        $detalleArticuloParam .= '<br><span style="color: #64748b; font-size: 13px;">' . htmlspecialchars($nomParam, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
    }
}

if ($ok === 'ejecutado') {
    $detalleEjecucion = $_SESSION['flash_ejecucion'] ?? '';
    unset($_SESSION['flash_ejecucion']);
    $mensajeExito = 'Proceso de alertas de stock ejecutado correctamente.' . ($detalleEjecucion !== '' ? ' — ' . $detalleEjecucion : '');
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

// Detectar si hay una cookie de modo oscuro activa
$darkMode = isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'true';

// Si el usuario hace clic en el botón, procesamos la petición mediante una variable GET
if (isset($_GET['toggle_dark_mode'])) {
    $darkMode = !$darkMode;
    $valorCookie = $darkMode ? 'true' : 'false';
    $tiempoExpira = time() + (86400 * 30);

    // Guardar ÚNICAMENTE en la raíz "/" para compartir con todas las páginas
    setcookie('dark_mode', $valorCookie, $tiempoExpira, "/");
    // Limpiar posibles cookies antiguas en subrutas
    setcookie('dark_mode', '', time() - 3600, dirname($_SERVER['SCRIPT_NAME']));
    setcookie('dark_mode', '', time() - 3600);

    // Redirigir a la misma página para limpiar la URL de la variable GET
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

?>
<!DOCTYPE html>
<html lang="es" class="<?php echo $darkMode ? 'dark-mode' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Sistema de Notificaciones</title>
    <script>
        // Sincronizar modo oscuro antes de renderizar para evitar parpadeos
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
    <script src="js/sweetalert2.all.min.js"></script>
</head>
<body>
<main class="contenedor">
    <header class="encabezado">
        <div class="encabezado-info">
            <h1>ALMACÉN - TINTAS Y SOLVENTES</h1>
            <p>Sistema de Alertas y Monitoreo de Stock Mínimo</p>
        </div>

        <div class="encabezado-derecha">
            <div class="encabezado-usuario-barra">
                <?php
                $usuarioSesion = obtenerUsuarioAutenticado();
                if (!empty($usuarioSesion)):
                ?>
                    <div class="usuario-sesion-pill" title="Usuario: <?= escapar($usuarioSesion['usr_codigo'] ?? '') ?> | Rol: <?= escapar($usuarioSesion['pus_codigo'] ?? '') ?>">
                        <span class="usuario-sesion-nombre"><?= escapar($usuarioSesion['usr_nombre'] ?: $usuarioSesion['usr_codigo']) ?></span>
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
                <a href="index.php" class="btn btn-secundario btn-icono-actualizar" title="Actualizar datos">
                    <img src="img/repetir.png" class="icono-invertible" alt="Actualizar" style="width: 18px; height: 18px;">
                </a>
                <form action="ejecutar_manual.php" method="POST" class="form-ejecutar-manual">
                    <input type="hidden" name="csrf_token" value="<?= escapar(generarTokenCsrf()) ?>">
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
        <!-- PANEL RESUMEN CON LOS TOTALES DE LOS ARTICULOS -->
        <div class="resumen">
            <div class="resumen-grupo resumen-total">
                <span class="resumen-total-label">Total:</span>
                <strong><?= $totales['total'] ?></strong>
            </div>

            <div class="resumen-separador"></div>

            <div class="resumen-grupo">
                <span class="resumen-grupo-titulo">Disponibilidad:</span>
                <div class="resumen-item">
                    <span>Disponibles</span>
                    <strong><?= $totales['disponibles'] ?></strong>
                </div>
                <div class="resumen-item">
                    <span>No disponibles</span>
                    <strong><?= $totales['no_disponibles'] ?></strong>
                </div>
            </div>

            <div class="resumen-separador"></div>

            <div class="resumen-grupo">
                <span class="resumen-grupo-titulo">Stock:</span>
                <div class="resumen-item">
                    <span>Con stock</span>
                    <strong><?= $totales['con_stock'] ?></strong>
                </div>
                <div class="resumen-item">
                    <span>Sin stock</span>
                    <strong><?= $totales['sin_stock'] ?></strong>
                </div>
            </div>

            <div class="resumen-separador"></div>

            <div class="resumen-grupo">
                <span class="resumen-grupo-titulo">Correo:</span>
                <div class="resumen-item">
                    <span>Enviado</span>
                    <strong><?= $totales['correo_enviado'] ?></strong>
                </div>
                <div class="resumen-item">
                    <span>No enviado</span>
                    <strong><?= $totales['correo_no_enviado'] ?></strong>
                </div>
            </div>

            <?php if ($totales['no_encontrados'] > 0): ?>
                <div class="resumen-separador"></div>
                <div class="resumen-grupo">
                    <span class="resumen-grupo-titulo">Catálogo:</span>
                    <div class="resumen-item">
                        <span>No encontrados</span>
                        <strong><?= $totales['no_encontrados'] ?></strong>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- BUSCADOR POR CÓDIGO O NOMBRE DE ARTÍCULO -->
    <div class="busqueda-contenedor">
        <form
                action="index.php"
                method="GET"
                class="busqueda-form"
        >
            <?php if ($filtroDisponible !== ''): ?>
                <input type="hidden" name="disponible" value="<?= escapar($filtroDisponible) ?>">
            <?php endif; ?>
            <?php if ($filtroStock !== ''): ?>
                <input type="hidden" name="stock" value="<?= escapar($filtroStock) ?>">
            <?php endif; ?>
            <?php if ($filtroCorreo !== ''): ?>
                <input type="hidden" name="correo" value="<?= escapar($filtroCorreo) ?>">
            <?php endif; ?>
            <?php if ($filtroCatalogo !== ''): ?>
                <input type="hidden" name="catalogo" value="<?= escapar($filtroCatalogo) ?>">
            <?php endif; ?>
            <?php if ($filtroCategoria !== ''): ?>
                <input type="hidden" name="categoria" value="<?= escapar($filtroCategoria) ?>">
            <?php endif; ?>
            <?php if ($filtroFamilia !== ''): ?>
                <input type="hidden" name="familia" value="<?= escapar($filtroFamilia) ?>">
            <?php endif; ?>
            <?php if ($filtroTfa !== ''): ?>
                <input type="hidden" name="tfa" value="<?= escapar($filtroTfa) ?>">
            <?php endif; ?>
            <?php if ($orden !== ''): ?>
                <input type="hidden" name="orden" value="<?= escapar($orden) ?>">
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
                <a href="index.php" style="margin-left: 8px; color: var(--color-primario); text-decoration: underline;">Quitar todos los filtros</a>
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
                                <a href="<?= urlConFiltros(['categoria' => ($filtroCategoria === $cat ? '' : $cat)]) ?>" class="opcion-filtro <?= ($filtroCategoria === $cat) ? 'seleccionada' : '' ?>" title="<?= escapar($cat) ?>">
                                    <span class="indicador-check"><?= ($filtroCategoria === $cat) ? '✓' : '' ?></span> <?= escapar($cat) ?>
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
                                <a href="<?= urlConFiltros(['familia' => ($filtroFamilia === $fam ? '' : $fam)]) ?>" class="opcion-filtro <?= ($filtroFamilia === $fam) ? 'seleccionada' : '' ?>" title="<?= escapar($fam) ?>">
                                    <span class="indicador-check"><?= ($filtroFamilia === $fam) ? '✓' : '' ?></span> <?= escapar($fam) ?>
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
                                <a href="<?= urlConFiltros(['tfa' => ($filtroTfa === $tf ? '' : $tf)]) ?>" class="opcion-filtro <?= ($filtroTfa === $tf) ? 'seleccionada' : '' ?>" title="<?= escapar($tf) ?>">
                                    <span class="indicador-check"><?= ($filtroTfa === $tf) ? '✓' : '' ?></span> <?= escapar($tf) ?>
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
                        <td colspan="11" style="text-align: center; padding: 34px 16px; color: var(--color-texto-muted);">
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
                            data-codigo="<?= escapar($artCodigo) ?>"
                            data-nombre="<?= escapar($artNombre) ?>">
                            <td>
                                <strong>
                                    <?= $artEncontrado ? escapar($artNombre) : '<span class="badge badge-alerta" title="El artículo no existe en el catálogo maestro (mae_articulo)">No encontrado</span>' ?>
                                </strong>
                                <span class="articulo-titulo">
                                    <?= escapar($artCodigo) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($artEncontrado && !empty($registro['categoria'])): ?>
                                    <span class="texto-clasificacion"><?= escapar($registro['categoria']) ?></span>
                                <?php else: ?>
                                    <span class="badge badge-sin-datos" title="Sin categoría">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($artEncontrado && !empty($registro['fam_nombre'])): ?>
                                    <span class="texto-clasificacion"><?= escapar($registro['fam_nombre']) ?></span>
                                <?php else: ?>
                                    <span class="badge badge-sin-datos" title="Sin familia">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($artEncontrado && !empty($registro['tfa_nombre'])): ?>
                                    <span class="texto-clasificacion"><?= escapar($registro['tfa_nombre']) ?></span>
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
                                        <?= escapar(
                                                number_format(
                                                        (float) $registro['stock_actual'],
                                                        5,
                                                        '.',
                                                        ','
                                                )
                                        ) . (!empty($registro['unidad_medida']) ? ' ' . escapar($registro['unidad_medida']) : '') ?>
                                    </strong>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= escapar(
                                        number_format(
                                                (float) $registro['stock_minimo'],
                                                        5,
                                                        '.',
                                                        ','
                                        )
                                ) . (!empty($registro['unidad_medida']) ? ' ' . escapar($registro['unidad_medida']) : '') ?>
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
                                    echo '<span class="fecha-dia">' . escapar($fecha->format('d/m/Y')) . '</span>';
                                    echo '<span class="fecha-hora">' . escapar($fecha->format('H:i:s')) . '</span>';
                                    echo '</div>';
                                } else {
                                    echo escapar($fecha);
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
                                                value="<?= escapar(trim($registro['emp_codigo'])) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="art_codigo"
                                                value="<?= escapar(trim($registro['art_codigo'])) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="alm_codigo"
                                                value="<?= escapar(trim($registro['alm_codigo'])) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="art_nombre"
                                                value="<?= escapar(trim((string) ($registro['art_nombre'] ?? ''))) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="categoria"
                                                value="<?= escapar(trim((string) ($registro['categoria'] ?? ''))) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="fam_nombre"
                                                value="<?= escapar(trim((string) ($registro['fam_nombre'] ?? ''))) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="tfa_nombre"
                                                value="<?= escapar(trim((string) ($registro['tfa_nombre'] ?? ''))) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="accion"
                                                value="eliminar"
                                            >
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= escapar(generarTokenCsrf()) ?>"
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
                                                value="<?= escapar(trim($registro['emp_codigo'])) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="art_codigo"
                                                value="<?= escapar(trim($registro['art_codigo'])) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="alm_codigo"
                                                value="<?= escapar(trim($registro['alm_codigo'])) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="art_nombre"
                                                value="<?= escapar(trim((string) ($registro['art_nombre'] ?? ''))) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="categoria"
                                                value="<?= escapar(trim((string) ($registro['categoria'] ?? ''))) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="fam_nombre"
                                                value="<?= escapar(trim((string) ($registro['fam_nombre'] ?? ''))) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="tfa_nombre"
                                                value="<?= escapar(trim((string) ($registro['tfa_nombre'] ?? ''))) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="accion"
                                                value="reactivar"
                                            >
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= escapar(generarTokenCsrf()) ?>"
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

<script>
// Alternar modo oscuro / claro compartido globalmente en todas las páginas
function toggleDarkMode(e) {
    if (e) e.preventDefault();
    const html = document.documentElement;
    const esOscuro = html.classList.toggle('dark-mode');
    const valor = esOscuro ? 'true' : 'false';

    // 1. Limpiar cualquier cookie residual con ruta específica
    document.cookie = "dark_mode=; path=" + window.location.pathname + "; expires=Thu, 01 Jan 1970 00:00:00 UTC;";
    const subpath = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
    if (subpath) {
        document.cookie = "dark_mode=; path=" + subpath + "; expires=Thu, 01 Jan 1970 00:00:00 UTC;";
        document.cookie = "dark_mode=; path=" + subpath + "/; expires=Thu, 01 Jan 1970 00:00:00 UTC;";
    }

    // 2. Guardar UNA sola cookie global en la raíz "/" (accesible por index, crear, editar, etc.)
    document.cookie = `dark_mode=${valor}; path=/; max-age=2592000; SameSite=Lax`;

    // 3. Guardar en localStorage (sincronización instantánea entre pestañas y páginas)
    try {
        localStorage.setItem('dark_mode', valor);
    } catch (_) {}

    // 4. Actualizar el emoji del botón
    const btn = document.getElementById('btn-toggle-dark');
    if (btn) {
        btn.textContent = esOscuro ? '🌞' : '🌚';
    }
}

// Manejo del menú desplegable para filtros de columnas
function toggleMenuFiltro(e, menuId) {
    e.stopPropagation();
    const menu = document.getElementById(menuId);
    if (!menu) return;

    const estaAbierto = menu.classList.contains('menu-abierto');

    // Cerrar cualquier otro menú de filtro abierto
    document.querySelectorAll('.menu-filtro').forEach(function(m) {
        m.classList.remove('menu-abierto');
        const th = m.closest('.th-filtrable');
        if (th) th.classList.remove('th-menu-activo');
    });

    if (!estaAbierto) {
        menu.classList.add('menu-abierto');
        const thActual = menu.closest('.th-filtrable');
        if (thActual) thActual.classList.add('th-menu-activo');
    }
}

// Cerrar menús al hacer clic fuera de los encabezados filtrables
document.addEventListener('click', function(e) {
    if (!e.target.closest('.th-filtrable')) {
        document.querySelectorAll('.menu-filtro').forEach(function(m) {
            m.classList.remove('menu-abierto');
            const th = m.closest('.th-filtrable');
            if (th) th.classList.remove('th-menu-activo');
        });
    }
});

// Cerrar menús con la tecla Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.menu-filtro').forEach(function(m) {
            m.classList.remove('menu-abierto');
            const th = m.closest('.th-filtrable');
            if (th) th.classList.remove('th-menu-activo');
        });
    }
});

document.addEventListener('DOMContentLoaded', function() {

    // 0. Sincronizar el emoji del botón según el estado activo de la página
    const btnDark = document.getElementById('btn-toggle-dark');
    if (btnDark) {
        btnDark.textContent = document.documentElement.classList.contains('dark-mode') ? '🌞' : '🌚';
    }

    // 1. Mostrar modales animados para mensajes de sesión o URL (sin incrustar alertas estáticas en el HTML)
    <?php if ($mensajeExito !== null): ?>
    Swal.fire({
        icon: 'success',
        title: '¡Operación Exitosa!',
        html: <?= json_encode($mensajeExito, JSON_UNESCAPED_UNICODE) ?>,
        timer: 3500,
        timerProgressBar: true,
        confirmButtonColor: '#2563eb',
        confirmButtonText: 'Aceptar'
    });
    if (window.history.replaceState) {
        const url = new URL(window.location);
        url.searchParams.delete('ok');
        url.searchParams.delete('error');
        window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : ''));
    }
    <?php elseif ($error !== null): ?>
    Swal.fire({
        icon: 'error',
        title: 'Atención',
        html: <?= json_encode($error, JSON_UNESCAPED_UNICODE) ?>,
        confirmButtonColor: '#dc2626',
        confirmButtonText: 'Entendido'
    });
    <?php endif; ?>

    // Función auxiliar para escapar texto en HTML dentro de modales
    function escapeHtml(texto) {
        const div = document.createElement('div');
        div.textContent = texto || '';
        return div.innerHTML;
    }

    // 2. Control animado para "Revisar Stock" (con modal de progreso según el tiempo de respuesta)
    const formEjecutar = document.querySelector('.form-ejecutar-manual');
    if (formEjecutar) {
        formEjecutar.addEventListener('submit', function(e) {
            e.preventDefault();

            Swal.fire({
                title: '¿Deseas revisar el inventario?',
                text: 'Se analizará el inventario actual y se enviarán correos si existen alertas de stock que no se enviaron por correo.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#059669',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Sí, ejecutar ahora',
                cancelButtonText: 'Cancelar',
                reverseButtons: true,
                focusCancel: true
            }).then((result) => {
                if (result.isConfirmed) {
                    const tiempoInicio = performance.now();

                    // Modal de carga mientras se procesan las alertas
                    Swal.fire({
                        title: 'Ejecutando alertas...',
                        html: 'Por favor espera un momento...',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });

                    const formData = new FormData(formEjecutar);
                    formData.append('formato', 'json');

                    fetch('ejecutar_manual.php', {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: formData
                    })
                    .then(response => {
                        return response.json().then(data => ({
                            status: response.status,
                            data: data
                        })).catch(() => ({
                            status: response.status,
                            data: { exito: false, error: 'Respuesta inválida del servidor.' }
                        }));
                    })
                    .then(({ status, data }) => {
                        const tiempoSegundos = ((performance.now() - tiempoInicio) / 1000).toFixed(1);

                        if (status === 200 && data.exito) {
                            if (data.en_curso) {
                                Swal.fire({
                                    icon: 'info',
                                    title: 'Proceso en curso',
                                    text: data.mensaje || 'Ya hay una revisión de alertas en ejecución en este momento. Por favor espera a que finalice.',
                                    confirmButtonColor: '#2563eb',
                                    confirmButtonText: 'Entendido'
                                });
                            } else {
                                Swal.fire({
                                    icon: 'success',
                                    title: '¡Alertas procesadas con éxito!',
                                    confirmButtonColor: '#059669',
                                    confirmButtonText: 'Aceptar'
                                }).then(() => {
                                    window.location.reload();
                                });
                            }
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error en la ejecución',
                                text: data.error || 'Ocurrió un error inesperado al procesar las alertas.',
                                confirmButtonColor: '#dc2626'
                            });
                        }
                    })
                    .catch(err => {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error de conexión',
                            text: 'No se pudo comunicar con el servidor: ' + err.message,
                            confirmButtonColor: '#dc2626'
                        });
                    });
                }
            });
        });
    }

    // 3. Control animado para botones "Desactivar" y "Reactivar"
    document.querySelectorAll('.form-eliminar').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const accion = form.querySelector('input[name="accion"]')?.value || 'eliminar';
            const artCodigo = form.querySelector('input[name="art_codigo"]')?.value || '';
            const artNombre = form.querySelector('input[name="art_nombre"]')?.value || '';
            const categoria = form.querySelector('input[name="categoria"]')?.value || '';
            const famNombre = form.querySelector('input[name="fam_nombre"]')?.value || '';
            const tfaNombre = form.querySelector('input[name="tfa_nombre"]')?.value || '';
            const esReactivar = (accion === 'reactivar');

            let detalleArticulo = '';
            if (artNombre) {
                detalleArticulo += `<div style="color: #1e293b; font-size: 14px; font-weight: 600; margin-top: 6px; line-height: 1.35;">${escapeHtml(artNombre)}</div>`;
            }

            if (categoria || famNombre || tfaNombre) {
                detalleArticulo += `
                    <div style="margin-top: 12px; padding: 10px 14px; background: #f8fafc; border: 1px solid var(--color-borde, #e2e8f0); border-radius: 8px; font-size: 12.5px; text-align: left; display: inline-flex; flex-direction: column; gap: 4px; min-width: 260px; max-width: 100%; box-sizing: border-box;">
                        <div style="display: flex; gap: 8px;"><span style="color: var(--color-texto-muted, #64748b); font-weight: 600; min-width: 82px;">Categoría:</span> <strong style="color: var(--color-texto, #0f172a);">${escapeHtml(categoria || '—')}</strong></div>
                        <div style="display: flex; gap: 8px;"><span style="color: var(--color-texto-muted, #64748b); font-weight: 600; min-width: 82px;">Familia:</span> <strong style="color: var(--color-texto, #0f172a);">${escapeHtml(famNombre || '—')}</strong></div>
                        <div style="display: flex; gap: 8px;"><span style="color: var(--color-texto-muted, #64748b); font-weight: 600; min-width: 82px;">Tipo familia:</span> <strong style="color: var(--color-texto, #0f172a);">${escapeHtml(tfaNombre || '—')}</strong></div>
                    </div>
                `;
            }

            const titulo = esReactivar ? '¿Reactivar configuración?' : '¿Desactivar configuración?';
            const mensaje = esReactivar
                ? `¿Deseas volver a habilitar el artículo <strong>${escapeHtml(artCodigo)}</strong> para el monitoreo de stock?${detalleArticulo}`
                : `¿Estás seguro de desactivar el artículo <strong>${escapeHtml(artCodigo)}</strong>?${detalleArticulo}`;
            const icono = esReactivar ? 'question' : 'warning';
            const colorConfirmar = esReactivar ? '#059669' : '#dc2626';
            const textoConfirmar = esReactivar ? 'Sí, reactivar' : 'Sí, desactivar';

            Swal.fire({
                title: titulo,
                html: mensaje,
                icon: icono,
                showCancelButton: true,
                confirmButtonColor: colorConfirmar,
                cancelButtonColor: '#64748b',
                confirmButtonText: textoConfirmar,
                cancelButtonText: 'Cancelar',
                reverseButtons: true,
                focusCancel: true
            }).then((result) => {
                if (result.isConfirmed) {
                    const tiempoInicio = performance.now();

                    Swal.fire({
                        title: esReactivar ? 'Reactivando...' : 'Desactivando...',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });

                    const formData = new FormData(form);
                    formData.append('formato', 'json');

                    fetch('cambiar_estado.php', {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: formData
                    })
                    .then(response => {
                        return response.json().then(data => ({
                            status: response.status,
                            data: data
                        })).catch(() => ({
                            status: response.status,
                            data: { exito: false, error: 'Respuesta inválida del servidor.' }
                        }));
                    })
                    .then(({ status, data }) => {
                        const tiempoSegundos = ((performance.now() - tiempoInicio) / 1000).toFixed(1);

                        if (status === 200 && data.exito) {
                            Swal.fire({
                                icon: 'success',
                                title: esReactivar ? '¡Reactivado!' : '¡Desactivado!',
                                text: data.mensaje,
                                timer: 3000,
                                timerProgressBar: true,
                                showConfirmButton: false
                            }).then(() => {
                                window.location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.error || 'No se pudo completar la operación.',
                                confirmButtonColor: '#dc2626'
                            });
                        }
                    })
                    .catch(err => {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error de conexión',
                            text: err.message,
                            confirmButtonColor: '#dc2626'
                        });
                    });
                }
            });
        });
    });

    // 4. Búsqueda instantánea en tiempo real sobre la tabla de artículos
    const inputArticulo = document.getElementById('articulo');
    const formBusqueda = document.querySelector('.busqueda-form');
    const tablaCuerpo = document.querySelector('.tabla-contenedor tbody');
    const parrafoResultados = document.getElementById('busqueda-resultados');

    if (inputArticulo && tablaCuerpo) {
        // Evitar que al presionar Enter se recargue la página
        if (formBusqueda) {
            formBusqueda.addEventListener('submit', function(e) {
                e.preventDefault();
            });
        }

        // Obtener todas las filas reales de datos (ignorando filas de mensajes estáticos)
        const filas = Array.from(tablaCuerpo.querySelectorAll('tr')).filter(tr => {
            return !tr.querySelector('td[colspan]');
        });

        // Crear dinámicamente fila para mensaje de "Sin resultados" si no existe
        let filaSinResultados = document.getElementById('fila-sin-resultados');
        if (!filaSinResultados) {
            filaSinResultados = document.createElement('tr');
            filaSinResultados.id = 'fila-sin-resultados';
            filaSinResultados.style.display = 'none';
            filaSinResultados.innerHTML = `
                <td colspan="11" style="text-align: center; padding: 34px 16px; color: var(--color-texto-muted);">
                    No se encontraron artículos que coincidan con la búsqueda.
                </td>
            `;
            tablaCuerpo.appendChild(filaSinResultados);
        }

        function ejecutarFiltroEnVivo() {
            const termino = inputArticulo.value.toLowerCase().trim();
            let visibles = 0;

            filas.forEach(fila => {
                // Obtenemos código y descripción directamente de data-attributes o del DOM
                const codigo = (fila.dataset.codigo || fila.querySelector('.articulo-titulo')?.textContent || '').toLowerCase().trim();
                const nombre = (fila.dataset.nombre || fila.querySelector('strong')?.textContent || '').toLowerCase().trim();

                // Búsqueda estricta de izquierda a derecha (starts with):
                // 1. Código: coincide si el código empieza por el término (o sin ceros iniciales si los omite)
                const coincideCodigo = codigo.startsWith(termino) || codigo.replace(/^0+/, '').startsWith(termino);

                // 2. Nombre: coincide si el nombre o alguna de sus palabras empieza por el término
                const palabrasNombre = nombre.split(/\s+/);
                const coincideNombre = nombre.startsWith(termino) || palabrasNombre.some(p => p.startsWith(termino));

                if (termino === '' || coincideCodigo || coincideNombre) {
                    fila.style.display = '';
                    visibles++;
                } else {
                    fila.style.display = 'none';
                }
            });

            // Si hay término y 0 coincidencias, mostrar mensaje
            if (filas.length > 0) {
                if (visibles === 0 && termino !== '') {
                    filaSinResultados.style.display = '';
                } else {
                    filaSinResultados.style.display = 'none';
                }
            }

            // Actualizar indicador de resultados en pantalla
            if (parrafoResultados) {
                if (termino !== '') {
                    parrafoResultados.style.display = 'block';
                    parrafoResultados.innerHTML = `Coincidencias en tiempo real: <strong>${visibles}</strong> de <strong>${filas.length}</strong> artículos.`;
                } else {
                    <?php if ($hayFiltrosActivos): ?>
                    parrafoResultados.style.display = 'block';
                    parrafoResultados.innerHTML = `Filtros activos en base de datos — <strong><?= count($registros) ?></strong> <?= count($registros) === 1 ? 'registro encontrado' : 'registros encontrados' ?>. <a href="index.php" style="margin-left: 8px; color: var(--color-primario); text-decoration: underline;">Quitar todos los filtros</a>`;
                    <?php else: ?>
                    parrafoResultados.style.display = 'none';
                    <?php endif; ?>
                }
            }
        }

        // Se ejecuta cada vez que el usuario escribe, borra o pega (instantáneo)
        inputArticulo.addEventListener('input', ejecutarFiltroEnVivo);
        // Se ejecuta cuando el usuario hace clic en la "x" nativa de input[type=search]
        inputArticulo.addEventListener('search', ejecutarFiltroEnVivo);

        // Si ya hay un valor cargado al entrar a la página, filtrar inmediatamente
        if (inputArticulo.value.trim() !== '') {
            ejecutarFiltroEnVivo();
        }
    }
});
</script>
</body>
</html>

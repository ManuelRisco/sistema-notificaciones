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
$busqueda = isset($_GET['articulo']) && is_string($_GET['articulo'])
    ? trim($_GET['articulo'])
    : '';

$filtroDisponible = isset($_GET['disponible']) && in_array((string)$_GET['disponible'], ['0', '1'], true)
    ? (string)$_GET['disponible']
    : '';

$filtroStock = isset($_GET['stock']) && in_array((string)$_GET['stock'], ['0', '1'], true)
    ? (string)$_GET['stock']
    : '';

$filtroCorreo = isset($_GET['correo']) && in_array((string)$_GET['correo'], ['0', '1'], true)
    ? (string)$_GET['correo']
    : '';

$totales = [
    'total' => 0,
    'disponibles' => 0,
    'no_disponibles' => 0,
    'con_stock' => 0,
    'sin_stock' => 0,
    'correo_enviado' => 0,
    'correo_no_enviado' => 0
];

try {

    // 4. Obtener la conexión.
    $conexion = getSqlServerConn();

    // 5. Consultar los totales generales directamente en la base de datos
    $sqlTotales = "
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN disponible = 1 THEN 1 ELSE 0 END) AS disponibles,
            SUM(CASE WHEN disponible = 0 THEN 1 ELSE 0 END) AS no_disponibles,
            SUM(CASE WHEN band_stock = 0 THEN 1 ELSE 0 END) AS con_stock,
            SUM(CASE WHEN band_stock = 1 THEN 1 ELSE 0 END) AS sin_stock,
            SUM(CASE WHEN band_correo = 1 THEN 1 ELSE 0 END) AS correo_enviado,
            SUM(CASE WHEN band_correo = 0 THEN 1 ELSE 0 END) AS correo_no_enviado
        FROM dbo.cfg_alerta_stock;
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
                'correo_no_enviado' => (int) ($filaTotales['correo_no_enviado'] ?? 0)
            ];
        }
        sqlsrv_free_stmt($stmtTotales);
    }

    // 6. Construir condiciones WHERE dinámicas para filtrar en SQL Server
    $condiciones = [];
    $params = [];

    // YA NO SE USA ESTO, IGUAL SE DEJA POR SI ACASO
    if ($busqueda !== '') {
        $condiciones[] = "(cfg.art_codigo LIKE ? OR m.art_nombre LIKE ?)";
        $parametroBusqueda = $busqueda . '%';
        $params[] = $parametroBusqueda;
        $params[] = $parametroBusqueda;
    }

    if ($filtroDisponible !== '') {
        $condiciones[] = "cfg.disponible = ?";
        $params[] = (int) $filtroDisponible;
    }

    if ($filtroStock !== '') {
        $condiciones[] = "cfg.band_stock = ?";
        $params[] = (int) $filtroStock;
    }

    if ($filtroCorreo !== '') {
        $condiciones[] = "cfg.band_correo = ?";
        $params[] = (int) $filtroCorreo;
    }

    $clausulaWhere = !empty($condiciones) ? 'WHERE ' . implode(' AND ', $condiciones) : '';

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

            RTRIM(m.art_nombre) AS art_nombre,

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

        {$clausulaWhere}

        ORDER BY
            cfg.disponible DESC,
            cfg.emp_codigo,
            cfg.art_codigo,
            cfg.alm_codigo;
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

$hayFiltrosActivos = ($busqueda !== '' || $filtroDisponible !== '' || $filtroStock !== '' || $filtroCorreo !== '');

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

    // Guardar en todas las rutas posibles para evitar cookies huérfanas
    setcookie('dark_mode', $valorCookie, $tiempoExpira, "/");
    setcookie('dark_mode', $valorCookie, $tiempoExpira, dirname($_SERVER['SCRIPT_NAME']));
    setcookie('dark_mode', $valorCookie, $tiempoExpira);

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
    <link rel="stylesheet" href="css/estilos.css">
    <script src="js/sweetalert2.all.min.js"></script>
</head>
<body>
<main class="contenedor">
    <header class="encabezado">
        <div>
            <h1>Sistema de Notificaciones</h1>
        </div>

        <div class="encabezado-acciones">
            <a href="index.php" class="btn btn-secundario">
                <img src="img/repetir.png" class="icono-invertible" alt="Imagen para actualizar pagina" style="width: 20px; height: 20px;">
            </a>
            <form action="ejecutar_manual.php" method="POST" class="form-ejecutar-manual">
                <input type="hidden" name="csrf_token" value="<?= escapar(generarTokenCsrf()) ?>">
                <button
                    type="submit"
                    class="btn btn-ejecutar"
                >
                    Ejecutar Alertas
                </button>
            </form>
            <a href="crear.php" class="btn btn-primario">
                + Nuevo registro
            </a>
            <!-- Botón para alternar el modo -->
            <a href="?toggle_dark_mode=1"
               id="btn-toggle-dark"
               onclick="toggleDarkMode(event)"
               style="display: grid; place-items: center; width: 30px; height: 30px; border-radius: 50%; font-size: 24px; text-decoration: none; line-height: 1; filter: invert(1) hue-rotate(180deg);"
               title="Alternar modo claro / oscuro">
                <?php echo $darkMode ? '🌞' : '🌚'; ?>
            </a>
        </div>
    </header>

    <?php if ($error === null || !empty($registros) || $hayFiltrosActivos): ?>
        <!-- PANEL RESUMEN CON LOS TOTALES DE LOS ARTICULOS -->
        <div class="resumen">
            <div class="resumen-grupo" style="font-size: 16px">
                Total: <strong><?= $totales['total'] ?></strong>
            </div>

            <div class="resumen-separador"></div>

            <div class="resumen-grupo">
                <span class="resumen-grupo-titulo">Disponibilidad:</span>
                <div class="resumen-item">
                    <span>Disponibles</span>
                    <strong><?= $totales['disponibles'] ?></strong>
                </div>
                <div class="resumen-separador"></div>
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
                <div class="resumen-separador"></div>
                <div class="resumen-item">
                    <span>Sin stock</span>
                    <strong><?= $totales['sin_stock'] ?></strong>
                </div>
            </div>

            <div class="resumen-separador"></div>

            <div class="resumen-grupo">
                <span class="resumen-grupo-titulo">Correo:</span>
                <div class="resumen-item">
                    <span>Correo enviado</span>
                    <strong><?= $totales['correo_enviado'] ?></strong>
                </div>
                <div class="resumen-separador"></div>
                <div class="resumen-item">
                    <span>Correo no enviado</span>
                    <strong><?= $totales['correo_no_enviado'] ?></strong>
                </div>
            </div>
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

            <div class="busqueda-campo busqueda-campo-texto">
                <label for="articulo">
                    Buscar artículo por código o nombre
                </label>
                <input
                        type="search"
                        id="articulo"
                        name="articulo"
                        maxlength="50"
                        value="<?= escapar($busqueda) ?>"
                        placeholder="020100427 | ROJO BANDERA 409"
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

        <p id="busqueda-resultados" class="busqueda-resultados" style="<?= ($hayFiltrosActivos || $busqueda !== '') ? '' : 'display: none;' ?>">
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
                    <th>Stock físico</th>
                    <th>Stock mínimo</th>

                    <!-- FILTRO TIPO: BAND. STOCK -->
                    <th class="th-filtrable">
                        <div class="th-contenido">
                            <span>Band. stock</span>
                            <button
                                type="button"
                                class="btn-filtro-columna <?= ($filtroStock !== '') ? 'filtro-activo' : '' ?>"
                                title="Filtrar por Stock"
                                onclick="toggleMenuFiltro(event, 'menu-filtro-stock')"
                            >
                                <svg class="icono-filtro" viewBox="0 0 16 16" width="12" height="12" fill="currentColor">
                                    <path d="M1.5 2.5a.5.5 0 0 1 .5-.5h12a.5.5 0 0 1 .38.82L9 8.24V13.5a.5.5 0 0 1-.76.42l-2-1.25A.5.5 0 0 1 6 12.25V8.24L1.62 2.82a.5.5 0 0 1-.12-.32z"/>
                                </svg>
                            </button>
                        </div>
                        <div id="menu-filtro-stock" class="menu-filtro">
                            <a href="<?= urlConFiltros(['stock' => '']) ?>" class="opcion-filtro <?= ($filtroStock === '') ? 'seleccionada' : '' ?>">
                                <span class="indicador-check"><?= ($filtroStock === '') ? '✓' : '' ?></span> Todas las opciones
                            </a>
                            <a href="<?= urlConFiltros(['stock' => '0']) ?>" class="opcion-filtro <?= ($filtroStock === '0') ? 'seleccionada' : '' ?>">
                                <span class="indicador-check"><?= ($filtroStock === '0') ? '✓' : '' ?></span> Con Stock
                            </a>
                            <a href="<?= urlConFiltros(['stock' => '1']) ?>" class="opcion-filtro <?= ($filtroStock === '1') ? 'seleccionada' : '' ?>">
                                <span class="indicador-check"><?= ($filtroStock === '1') ? '✓' : '' ?></span> Sin Stock
                            </a>
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
                            <a href="<?= urlConFiltros(['correo' => '']) ?>" class="opcion-filtro <?= ($filtroCorreo === '') ? 'seleccionada' : '' ?>">
                                <span class="indicador-check"><?= ($filtroCorreo === '') ? '✓' : '' ?></span> Todas las opciones
                            </a>
                            <a href="<?= urlConFiltros(['correo' => '1']) ?>" class="opcion-filtro <?= ($filtroCorreo === '1') ? 'seleccionada' : '' ?>">
                                <span class="indicador-check"><?= ($filtroCorreo === '1') ? '✓' : '' ?></span> Correo enviado
                            </a>
                            <a href="<?= urlConFiltros(['correo' => '0']) ?>" class="opcion-filtro <?= ($filtroCorreo === '0') ? 'seleccionada' : '' ?>">
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
                        <td colspan="8" style="text-align: center; padding: 34px 16px; color: var(--color-texto-muted);">
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
                        ?>
                        <tr class="<?= $estaDisponible ? '' : 'fila-inactiva' ?>"
                            data-codigo="<?= escapar($artCodigo) ?>"
                            data-nombre="<?= escapar($artNombre) ?>">
                            <td>
                                <strong>
                                    <?= escapar($artNombre) ?: '<span class="badge badge-alerta">No encontrado</span>' ?>
                                </strong>
                                <span class="articulo-titulo">
                                    <?= escapar($artCodigo) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($registro['stock_actual'] === null): ?>
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
                                        ) ?>
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
                                ) ?>
                            </td>
                            <td>
                                <?php if ($registro['band_stock']): ?>
                                    <span class="badge badge-alerta">Alerta de stock</span>
                                <?php else: ?>
                                    <span class="badge badge-activo">Con Stock</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($registro['band_correo']): ?>
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
                                            action="eliminar.php"
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
                                            action="eliminar.php"
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
// Alternar modo oscuro / claro de forma instantánea sin recargar
function toggleDarkMode(e) {
    if (e) e.preventDefault();
    const html = document.documentElement;
    const esOscuro = html.classList.toggle('dark-mode');
    const valor = esOscuro ? 'true' : 'false';

    // Guardar en cookie para todas las rutas y limpiar duplicados
    const expira = new Date(Date.now() + 86400 * 30 * 1000).toUTCString();
    document.cookie = `dark_mode=${valor}; path=/; expires=${expira}`;
    document.cookie = `dark_mode=${valor}; path=${window.location.pathname}; expires=${expira}`;

    // Actualizar el emoji del botón
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

    // 2. Control animado para "Ejecutar Alertas" (con modal de progreso según el tiempo de respuesta)
    const formEjecutar = document.querySelector('.form-ejecutar-manual');
    if (formEjecutar) {
        formEjecutar.addEventListener('submit', function(e) {
            e.preventDefault();

            Swal.fire({
                title: '¿Ejecutar revisión de stock?',
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
            const esReactivar = (accion === 'reactivar');

            const detalleArticulo = artNombre
                ? `<br><span style="color: #64748b; font-size: 13px; font-weight: 500;">${escapeHtml(artNombre)}</span>`
                : '';

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

                    fetch('eliminar.php', {
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
                <td colspan="8" style="text-align: center; padding: 34px 16px; color: var(--color-texto-muted);">
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

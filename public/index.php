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
// Código de artículo ingresado en el buscador.
$busqueda = isset($_GET['articulo']) && is_string($_GET['articulo'])
        ? trim($_GET['articulo'])
        : '';

try {

    // 4. Obtener la conexión.
    $conexion = getSqlServerConn();


    // 5. Calcular el stock físico de cada artículo configurado.
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

            s.stock_actual AS stock_actual,

            cfg.fecha_actualizacion

        FROM dbo.cfg_alerta_stock cfg

        LEFT JOIN StockCalculado s
            ON s.emp_codigo = cfg.emp_codigo
            AND s.art_codigo = cfg.art_codigo
            AND s.alm_codigo = cfg.alm_codigo
            
        WHERE cfg.art_codigo LIKE ?     
            
        ORDER BY
            cfg.disponible DESC,
            cfg.emp_codigo,
            cfg.art_codigo,
            cfg.alm_codigo;
    ";

    // 6. Ejecutar la consulta.
    // Buscar por coincidencia parcial o completa.
    $params = [
            '%' . $busqueda . '%'
    ];

    $stmt = sqlsrv_query($conexion, $sql, $params);

    if ($stmt === false) {
        throw new RuntimeException(
                'No se pudo consultar la configuración de stock.'
        );
    }

    // 7. Recuperar los registros.
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
function escapar(mixed $valor): string
{
    return htmlspecialchars(
        (string) $valor,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$totalActivos = 0;
foreach ($registros as $reg) {
    if ((int) ($reg['disponible'] ?? 1) === 1) {
        $totalActivos++;
    }
}
$totalInactivos = count($registros) - $totalActivos;

// Gestión de mensajes de notificación (éxito o error)
$mensajeExito = null;
$ok = $_GET['ok'] ?? '';

if ($ok === 'ejecutado') {
    $detalleEjecucion = $_SESSION['flash_ejecucion'] ?? '';
    unset($_SESSION['flash_ejecucion']);
    $mensajeExito = 'Proceso de alertas de stock ejecutado correctamente.' . ($detalleEjecucion !== '' ? ' — ' . $detalleEjecucion : '');
} elseif ($ok === 'creado') {
    $mensajeExito = 'Configuración de stock registrada exitosamente.';
} elseif ($ok === 'editado') {
    $mensajeExito = 'Configuración de stock actualizada exitosamente.';
} elseif ($ok === 'eliminado') {
    $mensajeExito = 'Configuración de stock desactivada (borrado lógico) exitosamente.';
} elseif ($ok === 'reactivado') {
    $mensajeExito = 'Configuración de stock reactivada exitosamente.';
}

if (!empty($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Configuración de stock</title>
    <link rel="stylesheet" href="css/estilos.css">
    <script src="js/sweetalert2.all.min.js"></script>
</head>
<body>
<main class="contenedor">
    <header class="encabezado">
        <div>
            <h1>Administración de Stock mínimo</h1>
        </div>
        <div class="encabezado-acciones">
            <a href="index.php" class="btn btn-secundario">
                Recargar Página
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
        </div>
    </header>

    <!-- BUSCADOR POR CÓDIGO DE ARTÍCULO -->
    <div class="busqueda-contenedor">
        <form
                action="index.php"
                method="GET"
                class="busqueda-form"
        >
            <div class="busqueda-campo">
                <label for="articulo">
                    Buscar artículo por código
                </label>
                <input
                        type="search"
                        id="articulo"
                        name="articulo"
                        maxlength="15"
                        value="<?= escapar($busqueda) ?>"
                        placeholder="Ingrese el código del artículo..."
                >
            </div>
            <button type="submit" class="btn btn-primario">
                Buscar
            </button>
            <a
                    href="index.php"
                    class="btn btn-secundario"
            >
                Limpiar
            </a>
        </form>

        <?php if ($busqueda !== ''): ?>

            <p class="busqueda-resultados">
                Resultados para:
                <strong><?= escapar($busqueda) ?></strong>

                — <?= count($registros) ?> registros encontrados.
            </p>

        <?php endif; ?>

    </div>

    <?php if ($error === null || !empty($registros)): ?>
        <div class="resumen">
            <div class="resumen-item">
                Total de registros: <strong><?= count($registros) ?></strong>
            </div>
            <div class="resumen-item">
                <span class="badge badge-activo">Disponibles</span>
                <strong><?= $totalActivos ?></strong>
            </div>
            <div class="resumen-item">
                <span class="badge badge-inactivo">No disponibles</span>
                <strong><?= $totalInactivos ?></strong>
            </div>
        </div>
        <div class="tabla-contenedor">
            <table>
                <thead>
                <tr>
                    <th>emp_codigo</th>
                    <th>Código Artículo</th>
                    <th>alm_codigo</th>
                    <th>Stock físico</th>
                    <th>Stock mínimo</th>
                    <th>Band. stock</th>
                    <th>Band. correo</th>
                    <th>Estado</th>
                    <th>Fecha actualización</th>
                    <th>Acciones</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($registros)): ?>
                    <tr>
                        <td colspan="10">
                            No existen configuraciones registradas.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($registros as $registro): ?>
                        <?php $estaDisponible = ((int) ($registro['disponible'] ?? 1) === 1); ?>
                        <tr class="<?= $estaDisponible ? '' : 'fila-inactiva' ?>">
                            <td>
                                <?= escapar(trim($registro['emp_codigo'])) ?>
                            </td>
                            <td>
                                <strong><?= escapar(trim($registro['art_codigo'])) ?></strong>
                            </td>
                            <td>
                                <?= escapar(trim($registro['alm_codigo'])) ?>
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
                                                        3,
                                                        '.',
                                                        ','
                                                )
                                        ) ?>
                                    </strong>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= escapar($registro['stock_minimo']) ?>
                            </td>
                            <td>
                                <?= escapar($registro['band_stock']) ?>
                            </td>
                            <td>
                                <?= escapar($registro['band_correo']) ?>
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
                                    echo escapar(
                                        $fecha->format('d/m/Y H:i:s')
                                    );
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
                text: 'Se analizará el inventario actual y se enviarán correos si existen alertas pendientes.',
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
                            Swal.fire({
                                icon: 'success',
                                title: '¡Alertas procesadas con éxito!',
                                confirmButtonColor: '#059669',
                                confirmButtonText: 'Aceptar'
                            }).then(() => {
                                window.location.reload();
                            });
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
            const esReactivar = (accion === 'reactivar');

            const titulo = esReactivar ? '¿Reactivar configuración?' : '¿Desactivar configuración?';
            const mensaje = esReactivar
                ? `¿Deseas volver a habilitar el artículo <strong>${escapeHtml(artCodigo)}</strong> para el monitoreo de stock?`
                : `¿Estás seguro de desactivar el artículo <strong>${escapeHtml(artCodigo)}</strong>?`;
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
});
</script>
</body>
</html>

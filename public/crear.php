<?php

declare(strict_types=1);

require_once __DIR__ . '/seguridad.php';

$raizProyecto = dirname(__DIR__);

require_once $raizProyecto . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable($raizProyecto);
$dotenv->safeLoad();

require_once $raizProyecto . '/config/database.php';

// Estos valores son fijos y no dependen del formulario.
$empCodigo = '001';
$almCodigo = '003';

$artCodigo = '';
$artNombre = '';
$stockMinimo = '';

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

        // 3. Validar campos.
        if ($artCodigo === '' || $stockMinimo === '') {
            throw new RuntimeException(
                'El código de artículo y el stock mínimo son obligatorios.'
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

        // 4.1 Consultar nombre del artículo en el maestro de artículos
        $sqlMae = "
            SELECT RTRIM(art_nombre) AS art_nombre
            FROM TECNOTEST.dbo.mae_articulo
            WHERE emp_codigo = ? AND art_codigo = ?;
        ";
        $stmtMae = sqlsrv_query($conexion, $sqlMae, [$empCodigo, $artCodigo]);
        if ($stmtMae !== false) {
            $filaMae = sqlsrv_fetch_array($stmtMae, SQLSRV_FETCH_ASSOC);
            if ($filaMae && isset($filaMae['art_nombre'])) {
                $artNombre = trim((string) $filaMae['art_nombre']);
            }
            sqlsrv_free_stmt($stmtMae);
        }

        // 5. Comprobar si ya existe en la base de datos (incluso si fue borrado lógicamente)
        $sqlCheck = "
            SELECT disponible
            FROM TECNOTEST.dbo.cfg_alerta_stock
            WHERE emp_codigo = ?
              AND art_codigo = ?
              AND alm_codigo = ?;
        ";

        $stmtCheck = sqlsrv_query(
            $conexion,
            $sqlCheck,
            [$empCodigo, $artCodigo, $almCodigo]
        );

        if ($stmtCheck === false) {
            throw new RuntimeException(
                'No se pudo verificar el registro existente.'
            );
        }

        $existente = sqlsrv_fetch_array($stmtCheck, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmtCheck);

        if ($existente !== null && $existente !== false) {
            $detalleNombre = $artNombre !== '' ? " ({$artNombre})" : '';
            if ((int) ($existente['disponible'] ?? 0) === 1) {
                throw new RuntimeException(
                    "El artículo {$artCodigo}{$detalleNombre} ya tiene una configuración activa para la empresa 001 y el almacén 003."
                );
            }

            // Si existía con borrado lógico (disponible = 0), se reactiva conservando sus banderas
            $sqlReactivar = "
                UPDATE TECNOTEST.dbo.cfg_alerta_stock
                SET stock_minimo = CAST(? AS NUMERIC(14,5)),
                    disponible = 1,
                    fecha_actualizacion = SYSDATETIME()
                WHERE emp_codigo = ?
                  AND art_codigo = ?
                  AND alm_codigo = ?;
            ";

            $stmtReactivar = sqlsrv_query(
                $conexion,
                $sqlReactivar,
                [$stockMinimo, $empCodigo, $artCodigo, $almCodigo]
            );

            if ($stmtReactivar === false) {
                throw new RuntimeException(
                    'No se pudo reactivar la configuración del artículo.'
                );
            }

            sqlsrv_free_stmt($stmtReactivar);

        } else {

            // 6. Nuevo registro con disponible = 1
            $sql = "
                INSERT INTO TECNOTEST.dbo.cfg_alerta_stock
                (
                    emp_codigo,
                    art_codigo,
                    alm_codigo,
                    stock_minimo,
                    disponible
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    CAST(? AS NUMERIC(14,5)),
                    1
                );
            ";

            $params = [
                $empCodigo,
                $artCodigo,
                $almCodigo,
                $stockMinimo
            ];

            $stmt = sqlsrv_query($conexion, $sql, $params);

            if ($stmt === false) {
                throw new RuntimeException(
                    'No se pudo registrar la configuración.'
                );
            }

            sqlsrv_free_stmt($stmt);
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

// Escapar texto para HTML
if (!function_exists('h')) {
    function h(mixed $valor): string
    {
        return htmlspecialchars(
            (string) $valor,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
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
    <title>Nuevo registro de stock</title>
    <link rel="stylesheet" href="css/estilos.css">
    <script src="js/sweetalert2.all.min.js"></script>
</head>
<body>
<main class="contenedor contenedor-formulario">
    <header class="encabezado">
        <div>
            <h1>Nuevo registro</h1>
            <p>Agregar una configuración de stock mínimo.</p>
        </div>
        <a href="index.php" class="btn btn-secundario">
            ← Volver
        </a>
    </header>

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
            <input
                type="text"
                id="art_codigo"
                name="art_codigo"
                maxlength="30"
                required
                placeholder="020100149"
                value="<?= h($artCodigo) ?>"
                autocomplete="off"
            >
            <div id="info_articulo" class="info-articulo"></div>
        </div>

        <!-- Stock mínimo -->
        <div class="campo">
            <label for="stock_minimo">
                Stock mínimo
            </label>
            <input
                type="number"
                id="stock_minimo"
                name="stock_minimo"
                min="0"
                max="999999999.99999"
                step="0.00001"
                required
                placeholder="50.000"
                value="<?= h($stockMinimo) ?>"
            >
        </div>

        <div class="acciones">
            <button
                type="submit"
                class="btn btn-primario"
            >
                Guardar registro
            </button>
            <a
                href="index.php"
                class="btn btn-secundario"
            >
                Cancelar
            </a>
        </div>
    </form>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($error !== null): ?>
    Swal.fire({
        icon: 'error',
        title: 'Atención',
        html: <?= json_encode($error, JSON_UNESCAPED_UNICODE) ?>,
        confirmButtonColor: '#dc2626',
        confirmButtonText: 'Entendido'
    });
    <?php endif; ?>

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    const form = document.querySelector('.formulario');
    const artInput = document.getElementById('art_codigo');
    const infoContainer = document.getElementById('info_articulo');
    let debounceTimer = null;
    let currentArtData = null;

    function renderInfoArticulo(data) {
        if (!data || !infoContainer) {
            if (infoContainer) infoContainer.innerHTML = '';
            return;
        }

        if (!data.encontrado) {
            infoContainer.innerHTML = '<span class="badge badge-alerta" style="display: inline-block; font-size: 11px;">No encontrado</span>';
            return;
        }

        const nombreHtml = `<span class="articulo-descripcion" style="margin-top: 0; font-size: 13px; font-weight: 500; color: #334155;">${escapeHtml(data.art_nombre || '')}</span>`;

        if (data.registrado) {
            if (data.disponible === 1) {
                infoContainer.innerHTML = `<span class="badge badge-alerta" style="display: inline-block; font-size: 11px;">Ya está registrado</span> ${nombreHtml}`;
            } else {
                infoContainer.innerHTML = `<span class="badge badge-inactivo" style="display: inline-block; font-size: 11px;">Ya registrado (Inactivo - se reactivará)</span> ${nombreHtml}`;
            }
        } else {
            infoContainer.innerHTML = nombreHtml;
        }
    }

    function consultarArticulo(codigo) {
        codigo = codigo.trim();
        if (!codigo) {
            if (infoContainer) infoContainer.innerHTML = '';
            currentArtData = null;
            return;
        }

        if (infoContainer) {
            infoContainer.innerHTML = '<span style="color: #64748b; font-size: 13px;">Buscando artículo...</span>';
        }

        fetch(`consultar_articulo.php?codigo=${encodeURIComponent(codigo)}`)
            .then(res => res.json())
            .then(data => {
                if (artInput && artInput.value.trim() !== codigo) {
                    return;
                }
                currentArtData = data;
                renderInfoArticulo(data);
            })
            .catch(() => {
                if (infoContainer) infoContainer.innerHTML = '';
            });
    }

    if (artInput) {
        artInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                consultarArticulo(artInput.value);
            }, 300);
        });

        if (artInput.value.trim() !== '') {
            consultarArticulo(artInput.value);
        }
    }

    if (form) {
        let confirmado = false;

        form.addEventListener('submit', function(e) {
            if (confirmado) {
                return;
            }

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            e.preventDefault();

            const artCodigo = artInput ? artInput.value.trim() : '';

            const procesarConfirmacion = (data) => {
                const artNombre = (data && data.encontrado && data.art_nombre) ? data.art_nombre : '';
                const estaRegistrado = data && data.registrado;
                const disponible = data ? data.disponible : null;
                const noEncontrado = data && !data.encontrado;

                // 1. Ya registrado y activo en cfg_alerta_stock
                if (estaRegistrado && disponible === 1) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Artículo ya registrado',
                        html: `El artículo <strong>${escapeHtml(artCodigo)}</strong>${artNombre ? `<br><small style="color: #64748b; font-size: 13px;">${escapeHtml(artNombre)}</small>` : ''}<br><br><span style="color: #dc2626;">Ya cuenta con una configuración activa registrada.</span>`,
                        confirmButtonColor: '#dc2626',
                        confirmButtonText: 'Entendido'
                    });
                    return;
                }

                // 2. Ya registrado pero inactivo (se reactivará)
                if (estaRegistrado && disponible === 0) {
                    Swal.fire({
                        title: '¿Reactivar configuración?',
                        html: `El artículo <strong>${escapeHtml(artCodigo)}</strong>${artNombre ? `<br><small style="color: #64748b; font-size: 13px;">${escapeHtml(artNombre)}</small>` : ''}<br><br>Ya se encuentra registrado pero está <strong>inactivo</strong>.<br>¿Deseas reactivarlo con el nuevo stock mínimo?`,
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonColor: '#059669',
                        cancelButtonColor: '#64748b',
                        confirmButtonText: 'Sí, reactivar',
                        cancelButtonText: 'Cancelar',
                        reverseButtons: true,
                        focusCancel: true
                    }).then((result) => {
                        if (result.isConfirmed) {
                            ejecutarEnvio();
                        }
                    });
                    return;
                }

                // 3. No encontrado en el catálogo mae_articulo
                if (noEncontrado) {
                    Swal.fire({
                        title: 'Artículo no encontrado',
                        html: `El artículo <strong>${escapeHtml(artCodigo)}</strong> no se encuentra en el catálogo de artículos.<br><br>¿Deseas registrar esta configuración de todas formas?`,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#2563eb',
                        cancelButtonColor: '#64748b',
                        confirmButtonText: 'Sí, guardar de todas formas',
                        cancelButtonText: 'Cancelar',
                        reverseButtons: true,
                        focusCancel: true
                    }).then((result) => {
                        if (result.isConfirmed) {
                            ejecutarEnvio();
                        }
                    });
                    return;
                }

                // 4. Caso normal: encontrado y no registrado
                const detalleArticulo = artNombre
                    ? `<br><span style="color: #64748b; font-size: 13px; font-weight: 500;">${escapeHtml(artNombre)}</span>`
                    : '';

                Swal.fire({
                    title: '¿Guardar nuevo registro?',
                    html: `¿Estás seguro de que deseas registrar esta configuración para el artículo <strong>${escapeHtml(artCodigo)}</strong>?${detalleArticulo}`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#2563eb',
                    cancelButtonColor: '#64748b',
                    confirmButtonText: 'Sí, guardar',
                    cancelButtonText: 'Cancelar',
                    reverseButtons: true,
                    focusCancel: true
                }).then((result) => {
                    if (result.isConfirmed) {
                        ejecutarEnvio();
                    }
                });
            };

            function ejecutarEnvio() {
                confirmado = true;
                Swal.fire({
                    title: 'Guardando registro...',
                    html: 'Registrando artículo y stock mínimo en la base de datos.<br><small style="color: #64748b;">Por favor espera un momento...</small>',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                form.submit();
            }

            if (currentArtData && currentArtData.art_codigo === artCodigo) {
                procesarConfirmacion(currentArtData);
            } else {
                fetch(`consultar_articulo.php?codigo=${encodeURIComponent(artCodigo)}`)
                    .then(res => res.json())
                    .then(data => {
                        currentArtData = data;
                        procesarConfirmacion(data);
                    })
                    .catch(() => {
                        procesarConfirmacion(null);
                    });
            }
        });
    }
});
</script>
</body>
</html>

<?php

declare(strict_types=1);

require_once __DIR__ . '/seguridad.php';

$raizProyecto = dirname(__DIR__);

require_once $raizProyecto . '/vendor/autoload.php';

// Cargar variables de entorno.
$dotenv = Dotenv\Dotenv::createImmutable($raizProyecto);
$dotenv->safeLoad();

require_once $raizProyecto . '/config/database.php';

// Clave original del registro.
$empOriginal = trim($_GET['emp'] ?? $_POST['emp_original'] ?? '');
$artOriginal = trim($_GET['art'] ?? $_POST['art_original'] ?? '');
$almOriginal = trim($_GET['alm'] ?? $_POST['alm_original'] ?? '');

// Valores editables.
$artCodigo = $artOriginal;
$artNombre = '';
$stockMinimo = '';
$disponible = 1;

$error = null;
$registroEncontrado = false;
$actualizado = false;
$sinCambios = false;

$conexion = null;
$esPost = $_SERVER['REQUEST_METHOD'] === 'POST';

try {

    // 1. Validar identificación original.
    if (
        $empOriginal !== '001'
        || $almOriginal !== '003'
        || $artOriginal === ''
        || strlen($artOriginal) > 30
    ) {
        throw new RuntimeException(
            'La identificación del registro no es válida.'
        );
    }

    // 2. Conectar con SQL Server.
    $conexion = getSqlServerConn();

    if ($esPost) {

        // 3. Validar protección CSRF.
        validarTokenCsrf();

        // 4. Recuperar los nuevos valores.
        $artCodigo = trim($_POST['art_codigo'] ?? '');
        $stockMinimo = trim($_POST['stock_minimo'] ?? '');
        $disponible = isset($_POST['disponible']) ? (int) $_POST['disponible'] : 1;

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
        $sqlActual = "
            SELECT
                cfg.art_codigo,
                cfg.stock_minimo,
                cfg.disponible,
                RTRIM(m.art_nombre) AS art_nombre
            FROM TECNOTEST.dbo.cfg_alerta_stock cfg
            LEFT JOIN TECNOTEST.dbo.mae_articulo m
                ON m.emp_codigo = cfg.emp_codigo
                AND m.art_codigo = cfg.art_codigo
            WHERE cfg.emp_codigo = ?
              AND cfg.art_codigo = ?
              AND cfg.alm_codigo = ?;
        ";

        $paramsActual = [
            $empOriginal,
            $artOriginal,
            $almOriginal
        ];

        $stmtActual = sqlsrv_query(
            $conexion,
            $sqlActual,
            $paramsActual
        );

        if ($stmtActual === false) {
            throw new RuntimeException(
                'No se pudieron consultar los valores actuales.'
            );
        }

        $registroActual = sqlsrv_fetch_array(
            $stmtActual,
            SQLSRV_FETCH_ASSOC
        );

        sqlsrv_free_stmt($stmtActual);

        if ($registroActual === null || $registroActual === false) {
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
            $sqlNom = "
                SELECT RTRIM(art_nombre) AS art_nombre
                FROM TECNOTEST.dbo.mae_articulo
                WHERE emp_codigo = ?
                  AND art_codigo = ?;
            ";
            $stmtNom = sqlsrv_query($conexion, $sqlNom, [$empOriginal, $artCodigo]);
            if ($stmtNom !== false) {
                $filaNom = sqlsrv_fetch_array($stmtNom, SQLSRV_FETCH_ASSOC);
                $artNombre = trim((string) ($filaNom['art_nombre'] ?? ''));
                sqlsrv_free_stmt($stmtNom);
            }
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
            $sql = "
                UPDATE TECNOTEST.dbo.cfg_alerta_stock
                SET
                    art_codigo = ?,
                    stock_minimo = CAST(? AS NUMERIC(14,5)),
                    disponible = ?,

                    band_stock = CASE
                        WHEN ? = 1 THEN 0
                        ELSE band_stock
                    END,

                    band_correo = CASE
                        WHEN ? = 1 THEN 0
                        ELSE band_correo
                    END,

                    fecha_actualizacion = SYSDATETIME()

                WHERE emp_codigo = ?
                  AND art_codigo = ?
                  AND alm_codigo = ?;
            ";

            $params = [
                $artCodigo,
                $stockMinimo,
                $disponible,

                // Reiniciar banderas únicamente si cambia el artículo
                $articuloCambio ? 1 : 0,
                $articuloCambio ? 1 : 0,

                // Identificación original del registro
                $empOriginal,
                $artOriginal,
                $almOriginal
            ];

            $stmt = sqlsrv_query(
                $conexion,
                $sql,
                $params
            );

            if ($stmt === false) {

                $erroresSql = sqlsrv_errors(SQLSRV_ERR_ERRORS) ?: [];

                foreach ($erroresSql as $errorSql) {

                    $codigo = (int) ($errorSql['code'] ?? 0);

                    // Clave primaria duplicada.
                    if ($codigo === 2601 || $codigo === 2627) {

                        throw new RuntimeException(
                            'El nuevo código de artículo ya tiene una configuración registrada para la empresa 001 y el almacén 003.'
                        );
                    }
                }

                throw new RuntimeException(
                    'No se pudo actualizar la configuración.'
                );
            }

            $filasAfectadas = sqlsrv_rows_affected($stmt);

            sqlsrv_free_stmt($stmt);

            if ($filasAfectadas !== 1) {
                throw new RuntimeException(
                    'No se pudo completar la actualización.'
                );
            }

            $actualizado = true;
        }

    } else {

        // 13. GET: consultar el registro original.
        $sql = "
            SELECT
                cfg.emp_codigo,
                cfg.art_codigo,
                cfg.alm_codigo,
                cfg.stock_minimo,
                cfg.disponible,
                RTRIM(m.art_nombre) AS art_nombre
            FROM TECNOTEST.dbo.cfg_alerta_stock cfg
            LEFT JOIN TECNOTEST.dbo.mae_articulo m
                ON m.emp_codigo = cfg.emp_codigo
                AND m.art_codigo = cfg.art_codigo
            WHERE cfg.emp_codigo = ?
              AND cfg.art_codigo = ?
              AND cfg.alm_codigo = ?;
        ";

        $params = [
            $empOriginal,
            $artOriginal,
            $almOriginal
        ];

        $stmt = sqlsrv_query(
            $conexion,
            $sql,
            $params
        );

        if ($stmt === false) {
            throw new RuntimeException(
                'No se pudo consultar el registro.'
            );
        }

        $registro = sqlsrv_fetch_array(
            $stmt,
            SQLSRV_FETCH_ASSOC
        );

        sqlsrv_free_stmt($stmt);

        if ($registro === null || $registro === false) {
            throw new RuntimeException(
                'El registro solicitado no existe.'
            );
        }

        $artCodigo = trim((string) $registro['art_codigo']);
        $artNombre = trim((string) ($registro['art_nombre'] ?? ''));
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

// Detectar si hay una cookie de modo oscuro activa
$darkMode = isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'true';

// Si el usuario hace clic en el botón, procesamos la petición mediante una variable GET
if (isset($_GET['toggle_dark_mode'])) {
    $darkMode = !$darkMode;
    $valorCookie = $darkMode ? 'true' : 'false';
    $tiempoExpira = time() + (86400 * 30);

    setcookie('dark_mode', $valorCookie, $tiempoExpira, "/");
    setcookie('dark_mode', $valorCookie, $tiempoExpira, dirname($_SERVER['SCRIPT_NAME']));
    setcookie('dark_mode', $valorCookie, $tiempoExpira);

    // Mantener los parámetros GET originales (emp, art, alm)
    $params = $_GET;
    unset($params['toggle_dark_mode']);
    $urlLimpia = strtok($_SERVER["REQUEST_URI"], '?');
    if (!empty($params)) {
        $urlLimpia .= '?' . http_build_query($params);
    }
    header("Location: " . $urlLimpia);
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
    <title>Editar Stock Mínimo</title>
    <link rel="stylesheet" href="css/estilos.css">
    <script src="js/sweetalert2.all.min.js"></script>
</head>
<body>
<main class="contenedor contenedor-formulario">
    <header class="encabezado">
        <div>
            <h1>Editar Stock Mínimo</h1>
            <p>Modificar código artículo, stock mínimo y disponibilidad.</p>
        </div>
        <div class="encabezado-acciones">
            <a href="index.php" class="btn btn-secundario">
                ← Volver
            </a>
            <!-- Botón para alternar el modo -->
            <?php
            $paramsToggle = $_GET;
            $paramsToggle['toggle_dark_mode'] = 1;
            ?>
            <a href="?<?= htmlspecialchars(http_build_query($paramsToggle), ENT_QUOTES, 'UTF-8') ?>"
               id="btn-toggle-dark"
               onclick="toggleDarkMode(event)"
               style="display: grid; place-items: center; width: 30px; height: 30px; border-radius: 50%; font-size: 24px; text-decoration: none; line-height: 1; filter: invert(1) hue-rotate(180deg);"
               title="Alternar modo claro / oscuro">
                <?php echo $darkMode ? '🌞' : '🌚'; ?>
            </a>
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
                >
                <div id="info_articulo" class="info-articulo">
                    <?php if ($artNombre !== ''): ?>
                        <span class="articulo-descripcion" style="margin-top: 0; font-size: 13px; font-weight: 500; color: #334155;">
                            <?= h($artNombre) ?>
                        </span>
                    <?php else: ?>
                        <span class="badge badge-alerta" style="display: inline-block; font-size: 11px;">No encontrado</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Stock mínimo editable. -->
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
                    value="<?= h($stockMinimo) ?>"
                >
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
// Alternar modo oscuro / claro de forma instantánea sin recargar
function toggleDarkMode(e) {
    if (e) e.preventDefault();
    const html = document.documentElement;
    const esOscuro = html.classList.toggle('dark-mode');
    const valor = esOscuro ? 'true' : 'false';

    const expira = new Date(Date.now() + 86400 * 30 * 1000).toUTCString();
    document.cookie = `dark_mode=${valor}; path=/; expires=${expira}`;
    document.cookie = `dark_mode=${valor}; path=${window.location.pathname}; expires=${expira}`;

    const btn = document.getElementById('btn-toggle-dark');
    if (btn) {
        btn.textContent = esOscuro ? '🌞' : '🌚';
    }
}

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

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    const form = document.querySelector('.formulario');
    const artInput = document.getElementById('art_codigo');
    const infoContainer = document.getElementById('info_articulo');
    const codigoOriginal = <?= json_encode($artOriginal, JSON_UNESCAPED_UNICODE) ?>;
    const nombreOriginal = <?= json_encode($artNombre, JSON_UNESCAPED_UNICODE) ?>;
    let debounceTimer = null;
    let currentArtData = null;

    function renderInfoArticulo(data, codigo) {
        if (!infoContainer) return;

        if (!codigo) {
            infoContainer.innerHTML = '';
            return;
        }

        if (!data || !data.encontrado) {
            infoContainer.innerHTML = '<span class="badge badge-alerta" style="display: inline-block; font-size: 11px;">No encontrado</span>';
            return;
        }

        const nombreHtml = `<span class="articulo-descripcion" style="margin-top: 0; font-size: 13px; font-weight: 500; color: #334155;">${escapeHtml(data.art_nombre || '')}</span>`;

        // Si ya está registrado en la base de datos
        if (data.registrado) {
            // Si es el mismo código original que se está editando, no es advertencia
            if (codigo === codigoOriginal) {
                infoContainer.innerHTML = nombreHtml;
                return;
            }

            if (data.disponible === 1) {
                infoContainer.innerHTML = `<span class="badge badge-alerta" style="display: inline-block; font-size: 11px;">Ya está registrado</span> ${nombreHtml}`;
            } else {
                infoContainer.innerHTML = `<span class="badge badge-inactivo" style="display: inline-block; font-size: 11px;">Ya registrado (Inactivo)</span> ${nombreHtml}`;
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

        // Si es el código original y ya conocemos su nombre
        if (codigo === codigoOriginal && nombreOriginal !== '') {
            currentArtData = {
                encontrado: true,
                art_codigo: codigo,
                art_nombre: nombreOriginal,
                registrado: true,
                disponible: 1
            };
            renderInfoArticulo(currentArtData, codigo);
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
                renderInfoArticulo(data, codigo);
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
                const artNombre = (data && data.encontrado && data.art_nombre)
                    ? data.art_nombre
                    : (artCodigo === codigoOriginal ? nombreOriginal : '');
                const estaRegistrado = data && data.registrado;
                const codigoCambio = (artCodigo !== codigoOriginal);
                const noEncontrado = data && !data.encontrado;

                // 1. Si cambió de código y el nuevo código ya está registrado en otra alerta
                if (codigoCambio && estaRegistrado) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Artículo ya registrado',
                        html: `El artículo <strong>${escapeHtml(artCodigo)}</strong>${artNombre ? `<br><small style="color: #64748b; font-size: 13px;">${escapeHtml(artNombre)}</small>` : ''}<br><br><span style="color: #dc2626;">Ya se encuentra registrado en el sistema.</span>`,
                        confirmButtonColor: '#dc2626',
                        confirmButtonText: 'Entendido'
                    });
                    return;
                }

                // 2. Si no fue encontrado en el catálogo mae_articulo
                if (noEncontrado) {
                    Swal.fire({
                        title: 'Artículo no encontrado',
                        html: `El artículo <strong>${escapeHtml(artCodigo)}</strong> no se encuentra en el catálogo de artículos.<br><br>¿Deseas continuar con la actualización de todas formas?`,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#2563eb',
                        cancelButtonColor: '#64748b',
                        confirmButtonText: 'Sí, actualizar de todas formas',
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

                // 3. Confirmación normal con código y nombre
                const detalleArticulo = artNombre
                    ? `<br><span style="color: #64748b; font-size: 13px; font-weight: 500;">${escapeHtml(artNombre)}</span>`
                    : '';

                Swal.fire({
                    title: '¿Guardar cambios?',
                    html: `¿Estás seguro de que deseas actualizar la configuración para el artículo <strong>${escapeHtml(artCodigo)}</strong>?${detalleArticulo}`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#2563eb',
                    cancelButtonColor: '#64748b',
                    confirmButtonText: 'Sí, guardar cambios',
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
                    title: 'Actualizando registro...',
                    html: 'Guardando los cambios en la base de datos.<br><small style="color: #64748b;">Por favor espera un momento...</small>',
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
            } else if (artCodigo === codigoOriginal) {
                procesarConfirmacion({
                    encontrado: (nombreOriginal !== ''),
                    art_codigo: artCodigo,
                    art_nombre: nombreOriginal,
                    registrado: true,
                    disponible: 1
                });
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

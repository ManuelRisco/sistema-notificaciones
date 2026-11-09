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
$categoria = '';
$famNombre = '';
$tfaNombre = '';

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
        $categoria = trim($_POST['categoria'] ?? '');
        $famNombre = trim($_POST['fam_nombre'] ?? '');
        $tfaNombre = trim($_POST['tfa_nombre'] ?? '');

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

        if ($artNombre === '') {
            throw new RuntimeException(
                "El artículo con código '{$artCodigo}' no existe en el catálogo maestro (mae_articulo). Seleccione un artículo válido con el botón 'Buscar en catálogo'."
            );
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

// Detectar si hay una cookie de modo oscuro activa
$darkMode = isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'true';

// Si el usuario hace clic en el botón, procesamos la petición mediante una variable GET
if (isset($_GET['toggle_dark_mode'])) {
    $darkMode = !$darkMode;
    $valorCookie = $darkMode ? 'true' : 'false';
    $tiempoExpira = time() + (86400 * 30);

    // Guardar ÚNICAMENTE en la raíz "/" para compartir con todas las páginas
    setcookie('dark_mode', $valorCookie, $tiempoExpira, "/");
    setcookie('dark_mode', '', time() - 3600, dirname($_SERVER['SCRIPT_NAME']));
    setcookie('dark_mode', '', time() - 3600);

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
    <title>Nuevo registro de stock</title>
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
<main class="contenedor contenedor-formulario">
    <header class="encabezado">
        <div class="encabezado-info">
            <h1>Nuevo registro</h1>
            <p>Agregar una configuración de stock mínimo.</p>
        </div>
        <div class="encabezado-derecha">
            <div class="encabezado-usuario-barra">
                <?php
                $usuarioSesion = obtenerUsuarioAutenticado();
                if (!empty($usuarioSesion)):
                ?>
                    <div class="usuario-sesion-pill" title="Usuario: <?= h($usuarioSesion['usr_codigo'] ?? '') ?> | Rol: <?= h($usuarioSesion['pus_codigo'] ?? '') ?>">
                        <span class="usuario-sesion-nombre"><?= h($usuarioSesion['usr_nombre'] ?: $usuarioSesion['usr_codigo']) ?></span>
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
                <a href="index.php" class="btn btn-secundario">
                    ← Volver
                </a>
            </div>
        </div>
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

        <!-- Stock mínimo -->
        <div class="campo">
            <label for="stock_minimo">
                Stock mínimo
            </label>
            <div style="position: relative; display: flex; align-items: center;">
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
                    style="padding-right: 55px;"
                >
                <span id="sufijo-unidad-minimo" style="position: absolute; right: 12px; font-weight: 700; font-size: 12px; color: var(--color-texto-muted, #64748b); pointer-events: none;"></span>
            </div>
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

// Variable en caché para los artículos del catálogo
let catalogoCache = null;

// Modal interactivo de catálogo de artículos con búsqueda en tiempo real
function abrirModalCatalogo() {
    Swal.fire({
        title: 'Catálogo de Artículos (Almacén 003)',
        html: `
            <div style="text-align: left; font-size: 13px;">
                <p style="color: var(--color-texto-muted); margin-bottom: 12px;">
                    Buscar artículo por código o nombre
                </p>
                <div style="margin-bottom: 12px;">
                    <input type="search" id="swal-buscador-catalogo" class="swal2-input" placeholder="020100149 | AMARILLO HUEVO" style="width: 100%; margin: 0; font-size: 13.5px; height: 40px; box-sizing: border-box;" autocomplete="off">
                </div>
                <div id="swal-lista-catalogo" style="max-height: 400px; overflow-y: auto; overflow-x: hidden; border: 1px solid var(--color-borde); border-radius: 8px; background: var(--color-tarjeta);">
                    <div style="padding: 30px; text-align: center; color: var(--color-texto-muted);">
                        Cargando artículos...
                    </div>
                </div>
                <div id="swal-contador-catalogo" style="margin-top: 8px; font-size: 12px; color: var(--color-texto-muted); text-align: right;"></div>
            </div>
        `,
        width: 'min(1160px, 96vw)',
        customClass: { popup: 'swal-catalogo-ancho' },
        showConfirmButton: false,
        showCloseButton: true,
        didOpen: () => {
            const buscador = document.getElementById('swal-buscador-catalogo');
            const lista = document.getElementById('swal-lista-catalogo');
            const contador = document.getElementById('swal-contador-catalogo');

            function escapeModalText(text) {
                const div = document.createElement('div');
                div.textContent = text || '';
                return div.innerHTML;
            }

            function renderizar(items) {
                if (!items || items.length === 0) {
                    lista.innerHTML = `
                        <div style="padding: 36px 16px; text-align: center; color: var(--color-texto-muted); font-size: 13px;">
                            No hay artículos pendientes de configurar en este almacén.
                        </div>
                    `;
                    if (contador) contador.textContent = '0 artículos disponibles';
                    return;
                }

                // Código del artículo que actualmente está seleccionado en el formulario
                const codigoActualEnFormulario = (document.getElementById('art_codigo')?.value || '').trim();

                let html = '<table style="width: 100%; table-layout: fixed; border-collapse: collapse; font-size: 12.5px; text-align: left;">';
                html += `
                    <thead>
                        <tr style="background: var(--color-secundario-fondo); position: sticky; top: 0; z-index: 2; border-bottom: 1.5px solid var(--color-borde);">
                            <th style="padding: 8px 6px; width: 95px; font-size: 11px; text-transform: uppercase;">Código</th>
                            <th style="padding: 8px 6px; font-size: 11px; text-transform: uppercase;">Artículo</th>
                            <th style="padding: 8px 6px; width: 230px; font-size: 11px; text-transform: uppercase;">Clasificación</th>
                            <th style="padding: 8px 6px; width: 115px; font-size: 11px; text-transform: uppercase; text-align: right;">Stock real</th>
                            <th style="padding: 8px 6px; width: 95px; font-size: 11px; text-transform: uppercase; text-align: center;">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                `;

                items.forEach(art => {
                    const metaTexto = `${art.categoria || ''} ${art.fam_nombre || ''} ${art.tfa_nombre || ''}`.trim();
                    const esEnUso = (codigoActualEnFormulario !== '' && art.codigo === codigoActualEnFormulario);

                    html += `
                        <tr class="fila-catalogo-modal" style="border-bottom: 1px solid var(--color-borde);" data-codigo="${escapeModalText(art.codigo)}" data-nombre="${escapeModalText(art.nombre)}" data-meta="${escapeModalText(metaTexto)}">
                            <td style="padding: 8px 6px; font-weight: 700; font-family: monospace; font-size: 12.5px; word-break: break-all;">
                                ${escapeModalText(art.codigo)}
                            </td>
                            <td style="padding: 8px 6px; line-height: 1.3; font-size: 12.5px; font-weight: 600; color: #1e293b; word-break: break-word;">
                                ${escapeModalText(art.nombre)}
                            </td>
                            <td style="padding: 6px; font-size: 12px; line-height: 1.35; color: #334155;">
                                <div style="display: flex; flex-direction: column; gap: 2px;">
                                    <div style="display: flex; gap: 6px;"><span style="color: #64748b; font-weight: 600; min-width: 68px; font-size: 11px;">Categoría:</span> <strong style="color: #1e293b; font-size: 11.5px; font-weight: 600;">${escapeModalText(art.categoria || '—')}</strong></div>
                                    <div style="display: flex; gap: 6px;"><span style="color: #64748b; font-weight: 600; min-width: 68px; font-size: 11px;">Familia:</span> <strong style="color: #1e293b; font-size: 11.5px; font-weight: 600;">${escapeModalText(art.fam_nombre || '—')}</strong></div>
                                    <div style="display: flex; gap: 6px;"><span style="color: #64748b; font-weight: 600; min-width: 68px; font-size: 11px;">Tipo fam:</span> <strong style="color: #1e293b; font-size: 11.5px; font-weight: 600;">${escapeModalText(art.tfa_nombre || '—')}</strong></div>
                                </div>
                            </td>
                            <td style="padding: 8px 6px; text-align: right; white-space: nowrap;">
                                <span class="articulo-stock-tag" style="font-size: 11.5px; padding: 2px 6px;">
                                    ${escapeModalText(art.stock_formateado || '0.000')}
                                </span>
                            </td>
                            <td style="padding: 8px 6px; text-align: center; white-space: nowrap;">
                                ${esEnUso
                                    ? `<span class="badge badge-activo" style="font-size: 11px; padding: 4px 6px;">En uso</span>`
                                    : `<button type="button" class="btn btn-primario btn-elegir-art" data-codigo="${escapeModalText(art.codigo)}" style="padding: 2px 10px; font-size: 11.5px; height: 26px; line-height: 26px; font-weight: 600;">Elegir</button>`
                                }
                            </td>
                        </tr>
                    `;
                });

                html += `
                    <tr id="swal-fila-vacia" style="display: none;">
                        <td colspan="7" style="padding: 30px 16px; text-align: center; color: var(--color-texto-muted);">
                            No se encontraron artículos que coincidan con la búsqueda.
                        </td>
                    </tr>
                `;

                html += '</tbody></table>';
                lista.innerHTML = html;
                if (contador) contador.innerHTML = `Mostrando <strong>${items.length}</strong> de <strong>${catalogoCache.length}</strong> artículos`;

                lista.querySelectorAll('.btn-elegir-art').forEach(b => {
                    b.addEventListener('click', function() {
                        const cod = this.getAttribute('data-codigo');
                        const inp = document.getElementById('art_codigo');
                        const artObj = catalogoCache ? catalogoCache.find(x => x.codigo === cod) : null;
                        if (artObj) {
                            const inputCat = document.getElementById('art_categoria');
                            const inputFam = document.getElementById('art_familia');
                            const inputTfa = document.getElementById('art_tipo_familia');
                            if (inputCat) inputCat.value = artObj.categoria || '';
                            if (inputFam) inputFam.value = artObj.fam_nombre || '';
                            if (inputTfa) inputTfa.value = artObj.tfa_nombre || '';
                        }
                        if (inp) {
                            inp.value = cod;
                            inp.dispatchEvent(new Event('input', { bubbles: true }));
                            const inpStock = document.getElementById('stock_minimo');
                            if (inpStock) inpStock.focus();
                        }
                        Swal.close();
                    });
                });
            }

            // Búsqueda idéntica a la de index.php (de izquierda a derecha por código o palabra del nombre)
            function filtrar() {
                if (!catalogoCache) return;
                const q = (buscador ? buscador.value : '').toLowerCase().trim();
                let visibles = 0;

                const filasModal = lista.querySelectorAll('tbody tr:not(#swal-fila-vacia)');
                filasModal.forEach(fila => {
                    const c = (fila.dataset.codigo || '').toLowerCase().trim();
                    const n = (fila.dataset.nombre || '').toLowerCase().trim();
                    const m = (fila.dataset.meta || '').toLowerCase().trim();

                    const coincideCodigo = c.startsWith(q) || c.replace(/^0+/, '').startsWith(q);
                    const palabrasNombre = n.split(/\s+/);
                    const coincideNombre = n.startsWith(q) || palabrasNombre.some(p => p.startsWith(q));
                    const coincideMeta = m.includes(q);

                    if (q === '' || coincideCodigo || coincideNombre || coincideMeta) {
                        fila.style.display = '';
                        visibles++;
                    } else {
                        fila.style.display = 'none';
                    }
                });

                const filaVacia = document.getElementById('swal-fila-vacia');
                if (filaVacia) {
                    filaVacia.style.display = (visibles === 0 && q !== '') ? '' : 'none';
                }

                if (contador) {
                    contador.innerHTML = `Mostrando <strong>${visibles}</strong> de <strong>${catalogoCache.length}</strong> artículos`;
                }
            }

            if (buscador) {
                buscador.addEventListener('input', filtrar);
                buscador.addEventListener('search', filtrar);
                buscador.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') e.preventDefault();
                });
            }

            // Si el catálogo ya está en caché, renderizarlo inmediatamente con el artículo actual
            if (catalogoCache) {
                renderizar(catalogoCache);
                if (buscador) setTimeout(() => buscador.focus(), 80);
                return;
            }

            // Consultar catálogo en tiempo real
            fetch('consultar_articulo.php?catalogo=1')
                .then(r => r.json())
                .then(res => {
                    if (res && res.exito && Array.isArray(res.articulos)) {
                        catalogoCache = res.articulos;
                        renderizar(catalogoCache);
                        if (buscador) setTimeout(() => buscador.focus(), 80);
                    } else {
                        lista.innerHTML = '<div style="padding: 24px; text-align: center; color: #dc2626;">No se pudo cargar el catálogo.</div>';
                    }
                })
                .catch(err => {
                    lista.innerHTML = `<div style="padding: 24px; text-align: center; color: #dc2626;">Error: ${escapeModalText(err.message)}</div>`;
                });
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {

    // 0. Sincronizar el emoji del botón según el estado activo de la página
    const btnDark = document.getElementById('btn-toggle-dark');
    if (btnDark) {
        btnDark.textContent = document.documentElement.classList.contains('dark-mode') ? '🌞' : '🌚';
    }
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
        const sufijoUme = document.getElementById('sufijo-unidad-minimo');
        const labelUme = document.getElementById('label-unidad-minimo');

        const inputCat = document.getElementById('art_categoria');
        const inputFam = document.getElementById('art_familia');
        const inputTfa = document.getElementById('art_tipo_familia');

        if (!data || !infoContainer) {
            if (infoContainer) infoContainer.innerHTML = '';
            if (sufijoUme) sufijoUme.textContent = '';
            if (labelUme) labelUme.textContent = '';
            if (inputCat) inputCat.value = '';
            if (inputFam) inputFam.value = '';
            if (inputTfa) inputTfa.value = '';
            return;
        }

        if (!data.encontrado) {
            infoContainer.innerHTML = '<span class="badge badge-alerta" style="display: inline-block; font-size: 11px;">No encontrado</span>';
            if (sufijoUme) sufijoUme.textContent = '';
            if (labelUme) labelUme.textContent = '';
            if (inputCat) inputCat.value = '';
            if (inputFam) inputFam.value = '';
            if (inputTfa) inputTfa.value = '';
            return;
        }

        if (inputCat) inputCat.value = data.categoria || '';
        if (inputFam) inputFam.value = data.fam_nombre || '';
        if (inputTfa) inputTfa.value = data.tfa_nombre || '';

        const umeTexto = data.unidad_medida || '';
        if (sufijoUme) sufijoUme.textContent = umeTexto;
        if (labelUme) labelUme.textContent = umeTexto ? `(${umeTexto})` : '';

        const nombreHtml = `<span class="articulo-nombre-destacado">${escapeHtml(data.art_nombre || '')}</span>`;
        const stockTexto = data.stock_actual_formateado || (data.stock_actual !== null ? `${data.stock_actual} ${data.unidad_medida || ''}` : '0.000');
        const stockHtml = `<span class="articulo-stock-tag">Stock real: <strong>${escapeHtml(stockTexto)}</strong></span>`;

        let estadoBadge = '';
        if (data.registrado) {
            if (data.disponible === 1) {
                estadoBadge = '<span class="badge badge-alerta" style="font-size: 11px;">Ya está registrado</span>';
            } else {
                estadoBadge = '<span class="badge badge-inactivo" style="font-size: 11px;">Inactivo (se reactivará)</span>';
            }
        }

        infoContainer.innerHTML = `
            <div class="info-articulo-fila">
                ${estadoBadge}
                ${nombreHtml}
                ${stockHtml}
            </div>
        `;
    }

    function consultarArticulo(codigo) {
        codigo = codigo.trim();
        if (!codigo) {
            if (infoContainer) infoContainer.innerHTML = '';
            const inputCat = document.getElementById('art_categoria');
            const inputFam = document.getElementById('art_familia');
            const inputTfa = document.getElementById('art_tipo_familia');
            if (inputCat) inputCat.value = '';
            if (inputFam) inputFam.value = '';
            if (inputTfa) inputTfa.value = '';
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
                        icon: 'error',
                        title: 'Artículo no encontrado',
                        html: `El artículo <strong>${escapeHtml(artCodigo)}</strong> no existe en el catálogo.<br><br>No se puede registrar una configuración de stock mínimo para un artículo inexistente.<br>Utiliza el botón <strong>"Buscar en catálogo"</strong> para seleccionar un artículo válido.`,
                        confirmButtonColor: '#dc2626',
                        confirmButtonText: 'Entendido'
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

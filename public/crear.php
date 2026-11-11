<?php

declare(strict_types=1);

use Manue\SistemaNotificaciones\StockRepository;

require_once __DIR__ . '/seguridad.php';

$raizProyecto = dirname(__DIR__);

// Validar autorización y obtener el almacén activo
$almCodigo = requerirAlmacenAutorizado($_GET['alm'] ?? null);
$almNombre = getNombreAlmacen($almCodigo);
$empCodigo = '001';

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
    <script src="js/comun.js"></script>
    <link rel="stylesheet" href="css/estilos.css?v=<?= file_exists(__DIR__ . '/css/estilos.css') ? filemtime(__DIR__ . '/css/estilos.css') : time() ?>">
    <script src="js/sweetalert2.all.min.js"></script>
    <script src="js/formulario-articulo.js"></script>
</head>
<body>
<main class="contenedor contenedor-formulario">
    <header class="encabezado">
        <div class="encabezado-info">
            <h1>Nuevo registro - <?= h($almNombre) ?></h1>
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
            <div class="campo-con-sufijo">
                <input
                    type="number"
                    id="stock_minimo"
                    name="stock_minimo"
                    min="0"
                    max="999999999.99999"
                    step="0.00001"
                    required
                    placeholder="20.00000"
                    value="<?= h($stockMinimo) ?>"
                >
                <span id="sufijo-unidad-minimo" class="sufijo-unidad"></span>
            </div>
        </div>

        <!-- Disponibilidad -->
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
    swalEntendido('error', 'AtenciÃ³n', <?= json_encode($error, JSON_UNESCAPED_UNICODE) ?>);
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

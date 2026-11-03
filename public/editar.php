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
        || strlen($artOriginal) > 15
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
        if (strlen($artCodigo) > 15) {
            throw new RuntimeException(
                'El código de artículo admite máximo 15 caracteres.'
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
                art_codigo,
                stock_minimo,
                disponible
            FROM TECNOTEST.dbo.cfg_alerta_stock
            WHERE emp_codigo = ?
              AND art_codigo = ?
              AND alm_codigo = ?;
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

        // 10. Comparar cambios en código, stock mínimo y disponible.
        $articuloCambio =
            $artCodigo !== trim((string) $registroActual['art_codigo']);

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
                emp_codigo,
                art_codigo,
                alm_codigo,
                stock_minimo,
                disponible
            FROM TECNOTEST.dbo.cfg_alerta_stock
            WHERE emp_codigo = ?
              AND art_codigo = ?
              AND alm_codigo = ?;
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
    header('Location: index.php?ok=editado');
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

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Editar configuración</title>
    <link rel="stylesheet" href="css/estilos.css">
    <script src="js/sweetalert2.all.min.js"></script>
</head>
<body>
<main class="contenedor contenedor-formulario">
    <header class="encabezado">
        <div>
            <h1>Editar configuración</h1>
            <p>Modificar código artículo, stock mínimo y disponibilidad.</p>
        </div>
        <a href="index.php" class="btn btn-secundario">
            ← Volver
        </a>
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

            <!-- Empresa y Almacén en 2 columnas -->
            <div class="form-grid-2">
                <div class="campo">
                    <label>Emp_codigo</label>
                    <input
                        type="text"
                        value="001"
                        readonly
                    >
                </div>
                <div class="campo">
                    <label>Alm_codigo</label>
                    <input
                        type="text"
                        value="003"
                        readonly
                    >
                </div>
            </div>

            <!-- Artículo editable. -->
            <div class="campo">
                <label for="art_codigo">
                    Código Artículo
                </label>
                <input
                    type="text"
                    id="art_codigo"
                    name="art_codigo"
                    maxlength="15"
                    required
                    value="<?= h($artCodigo) ?>"
                >
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

    const form = document.querySelector('.formulario');
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

            const artInput = form.querySelector('#art_codigo');
            const artCodigo = artInput ? artInput.value.trim() : '';

            Swal.fire({
                title: '¿Guardar cambios?',
                html: `¿Estás seguro de que deseas actualizar la configuración para el artículo <strong>${artCodigo}</strong>?`,
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
            });
        });
    }
});
</script>
</body>
</html>

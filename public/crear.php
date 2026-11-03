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

        if (strlen($artCodigo) > 15) {
            throw new RuntimeException(
                'El código del artículo admite máximo 15 caracteres.'
            );
        }

        if (!preg_match('/^\d{1,9}(?:\.\d{1,5})?$/D', $stockMinimo)) {
            throw new RuntimeException(
                'El stock mínimo debe ser positivo o cero, con máximo 5 decimales.'
            );
        }

        // 4. Conectar con SQL Server.
        $conexion = getSqlServerConn();

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
            if ((int) ($existente['disponible'] ?? 0) === 1) {
                throw new RuntimeException(
                    'Este artículo ya tiene una configuración activa para la empresa 001 y el almacén 003.'
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
        header('Location: index.php?ok=creado');
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

        <!-- Artículo -->
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
                placeholder="020100149"
                value="<?= h($artCodigo) ?>"
            >
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
                title: '¿Guardar nuevo registro?',
                html: `¿Estás seguro de que deseas registrar esta configuración para el artículo <strong>${artCodigo}</strong>?`,
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
            });
        });
    }
});
</script>
</body>
</html>

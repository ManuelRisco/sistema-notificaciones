<?php

declare(strict_types=1);

require_once __DIR__ . '/seguridad.php';

$raizProyecto = dirname(__DIR__);

require_once $raizProyecto . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable($raizProyecto);
$dotenv->safeLoad();

require_once $raizProyecto . '/config/database.php';

$conexion = null;
$actualizado = false;
$error = null;
$accion = 'eliminar';

try {

    // Evitar solicitudes GET.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException(
            'Método de solicitud no permitido.'
        );
    }

    // 1. Validar token CSRF.
    validarTokenCsrf();

    // 2. Recuperar la clave primaria y la acción.
    $empCodigo = trim($_POST['emp_codigo'] ?? '');
    $artCodigo = trim($_POST['art_codigo'] ?? '');
    $almCodigo = trim($_POST['alm_codigo'] ?? '');
    $artNombre = trim($_POST['art_nombre'] ?? '');
    $accion = trim($_POST['accion'] ?? 'eliminar');

    // 3. Validar los valores.
    if (
        $empCodigo === ''
        || $artCodigo === ''
        || $almCodigo === ''
        || strlen($empCodigo) > 3
        || strlen($artCodigo) > 30
        || strlen($almCodigo) > 3
    ) {
        throw new RuntimeException(
            'La identificación del registro no es válida.'
        );
    }

    // 4. Conectar con SQL Server.
    $conexion = getSqlServerConn();

    // 4.1 Si la acción es reactivar, verificar que el artículo exista en el catálogo maestro
    if ($accion === 'reactivar') {
        $sqlVerifMae = "
            SELECT 1 FROM TECNOTEST.dbo.mae_articulo
            WHERE emp_codigo = ? AND art_codigo = ?;
        ";
        $stmtVerifMae = sqlsrv_query($conexion, $sqlVerifMae, [$empCodigo, $artCodigo]);
        $existeEnMae = false;
        if ($stmtVerifMae !== false) {
            $existeEnMae = (sqlsrv_fetch_array($stmtVerifMae, SQLSRV_FETCH_NUMERIC) !== null);
            sqlsrv_free_stmt($stmtVerifMae);
        }
        if (!$existeEnMae) {
            throw new RuntimeException(
                "No se puede reactivar la alerta porque el artículo '{$artCodigo}' no existe en el catálogo maestro. Puede editarlo para asignarle un código válido."
            );
        }
    }

    // 5. Borrado Lógico: en lugar de DELETE físico, actualizamos "disponible".
    // Si la acción es reactivar, disponible = 1; si es eliminar, disponible = 0.
    $nuevoEstado = ($accion === 'reactivar') ? 1 : 0;

    $sql = "
        UPDATE TECNOTEST.dbo.cfg_alerta_stock
        SET
            disponible = ?,
            fecha_actualizacion = SYSDATETIME()
        WHERE emp_codigo = ?
          AND art_codigo = ?
          AND alm_codigo = ?;
    ";

    $params = [
        $nuevoEstado,
        $empCodigo,
        $artCodigo,
        $almCodigo
    ];

    // 6. Ejecutar.
    $stmt = sqlsrv_query($conexion, $sql, $params);

    if ($stmt === false) {
        throw new RuntimeException(
            'No se pudo actualizar el estado del registro.'
        );
    }

    $filasAfectadas = sqlsrv_rows_affected($stmt);

    sqlsrv_free_stmt($stmt);

    if ($filasAfectadas !== 1) {
        throw new RuntimeException(
            'No se encontró el registro que intentas modificar.'
        );
    }

    // Obtener nombre del artículo si no se envió desde el formulario
    if ($artNombre === '' && $artCodigo !== '') {
        $sqlNom = "
            SELECT RTRIM(art_nombre) AS art_nombre
            FROM TECNOTEST.dbo.mae_articulo
            WHERE emp_codigo = ? AND art_codigo = ?;
        ";
        $stmtNom = sqlsrv_query($conexion, $sqlNom, [$empCodigo, $artCodigo]);
        if ($stmtNom !== false) {
            $filaNom = sqlsrv_fetch_array($stmtNom, SQLSRV_FETCH_ASSOC);
            if ($filaNom) {
                $artNombre = trim((string) ($filaNom['art_nombre'] ?? ''));
            }
            sqlsrv_free_stmt($stmtNom);
        }
    }

    $actualizado = true;

} catch (Throwable $e) {

    $error = $e->getMessage();

} finally {

    if ($conexion !== null && $conexion !== false) {
        sqlsrv_close($conexion);
    }
}

$esAjax = (
    (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_POST['formato']) && $_POST['formato'] === 'json')
);

if ($actualizado) {
    $detalleArticulo = $artNombre !== '' ? " ({$artNombre})" : '';

    if ($esAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'exito' => true,
            'accion' => $accion,
            'art_codigo' => $artCodigo,
            'art_nombre' => $artNombre,
            'mensaje' => ($accion === 'reactivar')
                ? "Configuración del artículo {$artCodigo}{$detalleArticulo} reactivada exitosamente."
                : "Configuración del artículo {$artCodigo}{$detalleArticulo} desactivada exitosamente."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $paramOk = ($accion === 'reactivar') ? 'reactivado' : 'eliminado';
    $urlRedir = "index.php?ok={$paramOk}&art=" . urlencode($artCodigo) . ($artNombre !== '' ? '&nom=' . urlencode($artNombre) : '');
    header("Location: {$urlRedir}");
    exit;
}

if ($esAjax && $error !== null) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode([
        'exito' => false,
        'error' => $error
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Si hubo error, mostrarlo
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Error</title>
    <link rel="stylesheet" href="css/estilos.css">
</head>
<body>
<main class="contenedor">
    <div class="alerta alerta-error">
        <?= htmlspecialchars((string) $error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </div>
    <a href="index.php" class="btn btn-primario">Volver al inicio</a>
</main>
</body>
</html>

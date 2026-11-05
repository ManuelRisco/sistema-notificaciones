<?php

declare(strict_types=1);

require_once __DIR__ . '/seguridad.php';

$raizProyecto = dirname(__DIR__);
require_once $raizProyecto . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable($raizProyecto);
$dotenv->safeLoad();

require_once $raizProyecto . '/config/database.php';

header('Content-Type: application/json; charset=utf-8');

$codigo = trim((string) ($_GET['codigo'] ?? $_POST['codigo'] ?? ''));
$emp = '001';
$alm = '003';

if ($codigo === '' || strlen($codigo) > 30) {
    echo json_encode([
        'encontrado' => false,
        'art_codigo' => $codigo,
        'art_nombre' => null,
        'registrado' => false,
        'disponible' => null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$conexion = null;

try {
    $conexion = getSqlServerConn();

    // 1. Consultar maestro de artículos
    $sqlMae = "
        SELECT RTRIM(art_nombre) AS art_nombre
        FROM TECNOTEST.dbo.mae_articulo
        WHERE emp_codigo = ? AND art_codigo = ?;
    ";
    $stmtMae = sqlsrv_query($conexion, $sqlMae, [$emp, $codigo]);
    $artNombre = null;
    $encontrado = false;

    if ($stmtMae !== false) {
        $filaMae = sqlsrv_fetch_array($stmtMae, SQLSRV_FETCH_ASSOC);
        if ($filaMae && isset($filaMae['art_nombre'])) {
            $artNombre = trim((string) $filaMae['art_nombre']);
            $encontrado = true;
        }
        sqlsrv_free_stmt($stmtMae);
    }

    // 2. Consultar si ya tiene configuración en cfg_alerta_stock
    $sqlCfg = "
        SELECT disponible
        FROM TECNOTEST.dbo.cfg_alerta_stock
        WHERE emp_codigo = ? AND alm_codigo = ? AND art_codigo = ?;
    ";
    $stmtCfg = sqlsrv_query($conexion, $sqlCfg, [$emp, $alm, $codigo]);
    $registrado = false;
    $disponible = null;

    if ($stmtCfg !== false) {
        $filaCfg = sqlsrv_fetch_array($stmtCfg, SQLSRV_FETCH_ASSOC);
        if ($filaCfg !== null && $filaCfg !== false) {
            $registrado = true;
            $disponible = (int) ($filaCfg['disponible'] ?? 1);
        }
        sqlsrv_free_stmt($stmtCfg);
    }

    echo json_encode([
        'encontrado' => $encontrado,
        'art_codigo' => $codigo,
        'art_nombre' => $artNombre,
        'registrado' => $registrado,
        'disponible' => $disponible
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Error al consultar artículo: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} finally {
    if ($conexion !== null && $conexion !== false) {
        sqlsrv_close($conexion);
    }
}


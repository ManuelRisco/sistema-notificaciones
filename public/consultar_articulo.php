<?php

declare(strict_types=1);

require_once __DIR__ . '/seguridad.php';

$raizProyecto = dirname(__DIR__);
require_once $raizProyecto . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable($raizProyecto);
$dotenv->safeLoad();

require_once $raizProyecto . '/config/database.php';

header('Content-Type: application/json; charset=utf-8');

$emp = '001';
$alm = '003';

// Si se solicita el catálogo de artículos para la ventana modal
if (isset($_GET['catalogo']) || isset($_GET['listar'])) {
    $conexion = null;
    $artActual = trim((string) ($_GET['art_actual'] ?? ''));

    try {
        $conexion = getSqlServerConn();
        $db = getDatabaseName();
        // Consulta basada en StockRepository: calcula el stock físico real para almacén 003
        // y une con los artículos que tienen movimientos o configuración en dicho almacén
        $sqlCat = "
            ;WITH StockCalculado AS
            (
                SELECT
                    a.emp_codigo,
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
                FROM [{$db}].dbo.log_detmov a
                INNER JOIN [{$db}].dbo.log_cabmov b
                    ON a.mov_id = b.mov_id
                    AND b.alm_codigo = ?
                WHERE a.emp_codigo = ?
                  AND b.mov_flag <> 'A'
                  AND b.mov_fecemi <= GETDATE()
                GROUP BY
                    a.emp_codigo,
                    a.art_codigo,
                    b.alm_codigo
            ),
            ArticulosObjetivo AS
            (
                SELECT emp_codigo, art_codigo, alm_codigo FROM StockCalculado
                UNION
                SELECT emp_codigo, art_codigo, alm_codigo FROM [{$db}].dbo.cfg_alerta_stock WHERE emp_codigo = ? AND alm_codigo = ?
            )
            SELECT 
                RTRIM(m.art_codigo) AS art_codigo, 
                RTRIM(m.art_nombre) AS art_nombre,
                RTRIM(d.ume_abrv) AS unidad_medida,
                RTRIM(l.lin_nombre) AS categoria,
                RTRIM(f.fam_nombre) AS fam_nombre,
                RTRIM(tf.tfa_nombre) AS tfa_nombre,
                s.stock_actual,
                c.disponible
            FROM ArticulosObjetivo obj
            INNER JOIN [{$db}].dbo.mae_articulo m
                ON m.emp_codigo = obj.emp_codigo
                AND m.art_codigo = obj.art_codigo
            LEFT JOIN [{$db}].dbo.mae_cabumedida d
                ON m.ume_codigo = d.ume_codigo
            LEFT JOIN [{$db}].dbo.mae_linea l
                ON l.lin_codigo = m.lin_codigo
            LEFT JOIN [{$db}].dbo.mae_familia f
                ON f.emp_codigo = m.emp_codigo
                AND f.tar_codigo = m.tar_codigo
                AND f.fam_codigo = m.fam_codigo
            LEFT JOIN [{$db}].dbo.mae_tpofamilia tf
                ON tf.emp_codigo = m.emp_codigo
                AND tf.tar_codigo = m.tar_codigo
                AND tf.fam_codigo = m.fam_codigo
                AND tf.tfa_codigo = m.tfa_codigo
            LEFT JOIN StockCalculado s
                ON s.emp_codigo = obj.emp_codigo
                AND s.art_codigo = obj.art_codigo
                AND s.alm_codigo = obj.alm_codigo
            LEFT JOIN [{$db}].dbo.cfg_alerta_stock c
                ON c.emp_codigo = obj.emp_codigo
                AND c.alm_codigo = obj.alm_codigo
                AND c.art_codigo = obj.art_codigo
            ORDER BY m.art_nombre ASC;
        ";

        $stmtCat = sqlsrv_query($conexion, $sqlCat, [$alm, $emp, $emp, $alm]);
        if ($stmtCat === false) {
            throw new RuntimeException('Error al consultar el catálogo de artículos.');
        }

        $articulos = [];
        while ($fila = sqlsrv_fetch_array($stmtCat, SQLSRV_FETCH_ASSOC)) {
            $codigo = (string) $fila['art_codigo'];
            $disponible = $fila['disponible'] !== null ? (int) $fila['disponible'] : null;
            $esArticuloActual = ($artActual !== '' && $codigo === $artActual);

            // Si el artículo ya está activo en cfg_alerta_stock y no es el artículo en edición, se omite
            if ($disponible === 1 && !$esArticuloActual) {
                continue;
            }

            $stock = $fila['stock_actual'] !== null ? (float) $fila['stock_actual'] : null;
            $ume = trim((string) ($fila['unidad_medida'] ?? ''));
            $stockFormateado = ($stock !== null)
                ? number_format($stock, 3, '.', ',') . ($ume !== '' ? ' ' . $ume : '')
                : trim('0.000 ' . $ume);

            $articulos[] = [
                'codigo' => $codigo,
                'nombre' => (string) $fila['art_nombre'],
                'unidad_medida' => $ume,
                'categoria' => trim((string) ($fila['categoria'] ?? '')),
                'fam_nombre' => trim((string) ($fila['fam_nombre'] ?? '')),
                'tfa_nombre' => trim((string) ($fila['tfa_nombre'] ?? '')),
                'stock_actual' => $stock,
                'stock_formateado' => $stockFormateado,
                'en_uso' => $esArticuloActual
            ];
        }

        sqlsrv_free_stmt($stmtCat);
        echo json_encode([
            'exito' => true,
            'total' => count($articulos),
            'articulos' => $articulos
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'exito' => false,
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    } finally {
        if ($conexion !== null && $conexion !== false) {
            sqlsrv_close($conexion);
        }
    }
    exit;
}

$codigo = trim((string) ($_GET['codigo'] ?? $_POST['codigo'] ?? ''));

if ($codigo === '' || strlen($codigo) > 30) {
    echo json_encode([
        'encontrado' => false,
        'art_codigo' => $codigo,
        'art_nombre' => null,
        'unidad_medida' => null,
        'categoria' => null,
        'fam_nombre' => null,
        'tfa_nombre' => null,
        'stock_actual' => null,
        'stock_actual_formateado' => null,
        'registrado' => false,
        'disponible' => null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$conexion = null;

try {
    $conexion = getSqlServerConn();
    $db = getDatabaseName();

    // 1. Consultar maestro de artículos y calcular su stock físico real en almacén 003
    $sqlMae = "
        ;WITH StockCalculado AS
        (
            SELECT
                SUM(CASE WHEN b.mov_tipo = 'I' THEN a.mov_ctdmov ELSE 0 END) -
                SUM(CASE WHEN b.mov_tipo = 'S' THEN a.mov_ctdmov ELSE 0 END) AS stock_actual
            FROM [{$db}].dbo.log_detmov a
            INNER JOIN [{$db}].dbo.log_cabmov b
                ON a.mov_id = b.mov_id
                AND b.alm_codigo = ?
            WHERE a.emp_codigo = ?
              AND a.art_codigo = ?
              AND b.mov_flag <> 'A'
              AND b.mov_fecemi <= GETDATE()
        )
        SELECT 
            RTRIM(m.art_nombre) AS art_nombre,
            RTRIM(d.ume_abrv) AS unidad_medida,
            RTRIM(l.lin_nombre) AS categoria,
            RTRIM(f.fam_nombre) AS fam_nombre,
            RTRIM(tf.tfa_nombre) AS tfa_nombre,
            s.stock_actual
        FROM [{$db}].dbo.mae_articulo m
        LEFT JOIN [{$db}].dbo.mae_cabumedida d
            ON m.ume_codigo = d.ume_codigo
        LEFT JOIN [{$db}].dbo.mae_linea l
            ON l.lin_codigo = m.lin_codigo
        LEFT JOIN [{$db}].dbo.mae_familia f
            ON f.emp_codigo = m.emp_codigo
            AND f.tar_codigo = m.tar_codigo
            AND f.fam_codigo = m.fam_codigo
        LEFT JOIN [{$db}].dbo.mae_tpofamilia tf
            ON tf.emp_codigo = m.emp_codigo
            AND tf.tar_codigo = m.tar_codigo
            AND tf.fam_codigo = m.fam_codigo
            AND tf.tfa_codigo = m.tfa_codigo
        LEFT JOIN StockCalculado s
            ON 1 = 1
        WHERE m.emp_codigo = ? AND m.art_codigo = ?;
    ";

    $stmtMae = sqlsrv_query($conexion, $sqlMae, [$alm, $emp, $codigo, $emp, $codigo]);
    $artNombre = null;
    $unidadMedida = null;
    $categoria = null;
    $famNombre = null;
    $tfaNombre = null;
    $stockActual = null;
    $stockFormateado = null;
    $encontrado = false;

    if ($stmtMae !== false) {
        $filaMae = sqlsrv_fetch_array($stmtMae, SQLSRV_FETCH_ASSOC);
        if ($filaMae && isset($filaMae['art_nombre'])) {
            $artNombre = trim((string) $filaMae['art_nombre']);
            $unidadMedida = trim((string) ($filaMae['unidad_medida'] ?? ''));
            $categoria = trim((string) ($filaMae['categoria'] ?? ''));
            $famNombre = trim((string) ($filaMae['fam_nombre'] ?? ''));
            $tfaNombre = trim((string) ($filaMae['tfa_nombre'] ?? ''));
            $stockActual = $filaMae['stock_actual'] !== null ? (float) $filaMae['stock_actual'] : null;
            $stockFormateado = ($stockActual !== null)
                ? number_format($stockActual, 3, '.', ',') . ($unidadMedida !== '' ? ' ' . $unidadMedida : '')
                : trim('0.000 ' . $unidadMedida);
            $encontrado = true;
        }
        sqlsrv_free_stmt($stmtMae);
    }

    // 2. Consultar si ya tiene configuración en cfg_alerta_stock
    $sqlCfg = "
        SELECT disponible
        FROM [{$db}].dbo.cfg_alerta_stock
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
        'unidad_medida' => $unidadMedida,
        'categoria' => $categoria,
        'fam_nombre' => $famNombre,
        'tfa_nombre' => $tfaNombre,
        'stock_actual' => $stockActual,
        'stock_actual_formateado' => $stockFormateado,
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


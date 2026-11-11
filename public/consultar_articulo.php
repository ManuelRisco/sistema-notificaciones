<?php

declare(strict_types=1);

use Manue\SistemaNotificaciones\StockRepository;

require_once __DIR__ . '/seguridad.php';

$raizProyecto = dirname(__DIR__);
header('Content-Type: application/json; charset=utf-8');

$emp = '001';
$almParam = trim((string) ($_GET['alm'] ?? ''));
$alm = requerirAlmacenAutorizado($almParam !== '' ? $almParam : null);

/**
 * Formatea el stock con 3 decimales y la unidad de medida (ej.: "1,250.000 KG").
 */
function formatearStock(?float $stock, string $unidadMedida): string
{
    return ($stock !== null)
        ? number_format($stock, 3, '.', ',') . ($unidadMedida !== '' ? ' ' . $unidadMedida : '')
        : trim('0.000 ' . $unidadMedida);
}

// Si se solicita el catálogo de artículos para la ventana modal
if (isset($_GET['catalogo']) || isset($_GET['listar'])) {
    $conexion = null;
    $artActual = trim((string) ($_GET['art_actual'] ?? ''));

    try {
        $conexion = getSqlServerConn();
        $repositorio = new StockRepository($conexion);

        $articulos = [];

        try {
            $filas = $repositorio->listarCatalogo($emp, $alm);
        } catch (Throwable $e) {
            throw new RuntimeException('Error al consultar el catálogo de artículos.');
        }

        foreach ($filas as $fila) {
            $codigo = (string) $fila['art_codigo'];
            $disponible = $fila['disponible'] !== null ? (int) $fila['disponible'] : null;
            $esArticuloActual = ($artActual !== '' && $codigo === $artActual);

            // Si el artículo ya está activo en cfg_alerta_stock y no es el artículo en edición, se omite
            if ($disponible === 1 && !$esArticuloActual) {
                continue;
            }

            $stock = $fila['stock_actual'] !== null ? (float) $fila['stock_actual'] : null;
            $ume = trim((string) ($fila['unidad_medida'] ?? ''));

            $articulos[] = [
                'codigo' => $codigo,
                'nombre' => (string) $fila['art_nombre'],
                'unidad_medida' => $ume,
                'categoria' => trim((string) ($fila['categoria'] ?? '')),
                'fam_nombre' => trim((string) ($fila['fam_nombre'] ?? '')),
                'tfa_nombre' => trim((string) ($fila['tfa_nombre'] ?? '')),
                'stock_actual' => $stock,
                'stock_formateado' => formatearStock($stock, $ume),
                'en_uso' => $esArticuloActual
            ];
        }

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
    $repositorio = new StockRepository($conexion);

    // 1. Consultar maestro de artículos y calcular su stock físico real en el almacén
    $filaMae = $repositorio->obtenerArticuloConStock($emp, $alm, $codigo);

    $artNombre = null;
    $unidadMedida = null;
    $categoria = null;
    $famNombre = null;
    $tfaNombre = null;
    $stockActual = null;
    $stockFormateado = null;
    $encontrado = false;

    if ($filaMae !== null) {
        $artNombre = trim((string) $filaMae['art_nombre']);
        $unidadMedida = trim((string) ($filaMae['unidad_medida'] ?? ''));
        $categoria = trim((string) ($filaMae['categoria'] ?? ''));
        $famNombre = trim((string) ($filaMae['fam_nombre'] ?? ''));
        $tfaNombre = trim((string) ($filaMae['tfa_nombre'] ?? ''));
        $stockActual = $filaMae['stock_actual'] !== null ? (float) $filaMae['stock_actual'] : null;
        $stockFormateado = formatearStock($stockActual, $unidadMedida);
        $encontrado = true;
    }

    // 2. Consultar si ya tiene configuración en cfg_alerta_stock
    $registrado = false;
    $disponible = null;

    try {
        $disponibleCfg = $repositorio->obtenerDisponibleConfiguracion($emp, $alm, $codigo);
        if ($disponibleCfg !== null) {
            $registrado = true;
            $disponible = $disponibleCfg;
        }
    } catch (Throwable $e) {
        // Si la verificación falla, se responde como no registrado.
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

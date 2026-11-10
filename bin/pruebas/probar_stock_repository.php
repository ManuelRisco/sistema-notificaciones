<?php

use Manue\SistemaNotificaciones\StockRepository;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/almacenes.php';

try {
    // Obtener la conexión existente
    $conexion = getSqlServerConn();

    // Crear el repositorio
    $repository = new StockRepository($conexion);

    // Permite filtrar por almacén vía CLI (ej: php probar_stock_repository.php 001) o mostrar todos
    $almFiltro = isset($argv[1]) && trim((string)$argv[1]) !== '' ? trim((string)$argv[1]) : null;

    // Obtener los artículos y sus existencias
    $articulos = $repository->obtenerStockConfigurado($almFiltro);

    $tituloAlmacen = $almFiltro !== null
        ? " (Almacén: {$almFiltro} - " . (function_exists('getNombreAlmacen') ? getNombreAlmacen($almFiltro) : '') . ")"
        : " (Todos los almacenes)";

    echo "RESULTADO DEL INVENTARIO{$tituloAlmacen}\n";
    echo "====================================\n";

    if (empty($articulos)) {
        echo "No hay artículos configurados.\n";
        exit(0);
    }

    foreach ($articulos as $articulo) {
        $almCod = $articulo['alm_codigo'] ?? '';
        $almNom = function_exists('getNombreAlmacen') ? getNombreAlmacen($almCod) : '';

        echo "Artículo: "
            . $articulo['art_codigo'] . "\n";

        echo "Descripción: "
            . $articulo['descripcion'] . "\n";

        echo "Almacén: "
            . $almCod . ($almNom !== '' ? " ({$almNom})" : '') . "\n";

        echo "Stock físico: "
            . number_format(
                (float) $articulo['stock_actual'],
                3
            ) . " "
            . $articulo['unidad_medida'] . "\n";

        echo "Stock mínimo: "
            . $articulo['stock_minimo'] . "\n";

        echo "Bandera stock: "
            . $articulo['band_stock'] . "\n";

        echo "Bandera correo: "
            . $articulo['band_correo'] . "\n";

        echo "------------------------------------\n";
    }

} catch (Throwable $e) {
    fwrite(
        STDERR,
        "ERROR: " . $e->getMessage() . PHP_EOL
    );
    exit(1);
}

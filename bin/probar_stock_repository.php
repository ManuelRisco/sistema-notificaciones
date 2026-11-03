<?php

use Manue\SistemaNotificaciones\StockRepository;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/config/database.php';

try {

    // Obtener la conexión existente
    $conexion = getSqlServerConn();

    // Crear el repositorio
    $repository = new StockRepository($conexion);

    // Obtener los artículos y sus existencias
    $articulos = $repository->obtenerStockConfigurado();

    echo "RESULTADO DEL INVENTARIO\n";
    echo "====================================\n";

    if (empty($articulos)) {
        echo "No hay artículos configurados.\n";
        exit(0);
    }

    foreach ($articulos as $articulo) {

        echo "Artículo: "
            . $articulo['art_codigo'] . "\n";

        echo "Descripción: "
            . $articulo['descripcion'] . "\n";

        echo "Almacén: "
            . $articulo['alm_codigo'] . "\n";

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

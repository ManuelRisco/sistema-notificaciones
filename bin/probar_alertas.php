<?php

declare(strict_types=1);

use Manue\SistemaNotificaciones\StockRepository;
use Manue\SistemaNotificaciones\StockAlertService;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/config/database.php';

try {

    $conexion = getSqlServerConn();

    $repository = new StockRepository($conexion);

    $alertService = new StockAlertService($repository);
    $resultados = $alertService->procesar(true);

    echo "ANALISIS DE ALERTAS DE STOCK\n";
    echo "========================================\n";

    foreach ($resultados as $articulo) {

        echo "Articulo: {$articulo['art_codigo']}\n";

        echo "Descripcion: {$articulo['descripcion']}\n";

        echo "Stock actual: {$articulo['stock_actual']}\n";

        echo "Stock minimo: {$articulo['stock_minimo']}\n";

        echo "Estado: {$articulo['estado']}\n";

        echo "Band stock anterior: "
            . $articulo['band_stock_anterior'] . "\n";

        echo "Band stock nuevo: "
            . $articulo['band_stock_nueva'] . "\n";

        echo "Band correo anterior: "
            . $articulo['band_correo_anterior'] . "\n";

        echo "Band correo nuevo: "
            . $articulo['band_correo_nueva'] . "\n";

        echo "Correo pendiente: "
            . ($articulo['correo_pendiente'] ? 'SI' : 'NO') . "\n";

        echo "========================================\n";
    }

} catch (Throwable $e) {

    fwrite(STDERR, "ERROR: " . $e->getMessage() . PHP_EOL);

    exit(1);
}

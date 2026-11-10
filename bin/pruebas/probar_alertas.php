<?php

declare(strict_types=1);

use Manue\SistemaNotificaciones\StockRepository;
use Manue\SistemaNotificaciones\StockAlertService;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/almacenes.php';

try {
    $conexion = getSqlServerConn();

    $repository = new StockRepository($conexion);

    $alertService = new StockAlertService($repository);

    // Permite filtrar por almacén vía CLI (ej: php probar_alertas.php 001) o analizar todos
    $almFiltro = isset($argv[1]) && trim((string)$argv[1]) !== '' ? trim((string)$argv[1]) : null;

    $resultados = $alertService->procesar(true, $almFiltro);

    $tituloAlmacen = $almFiltro !== null
        ? " (Almacén: {$almFiltro} - " . (function_exists('getNombreAlmacen') ? getNombreAlmacen($almFiltro) : '') . ")"
        : " (Todos los almacenes)";

    echo "ANALISIS DE ALERTAS DE STOCK{$tituloAlmacen}\n";
    echo "========================================\n";

    if (empty($resultados)) {
        echo "No hay artículos configurados para analizar.\n";
        exit(0);
    }

    foreach ($resultados as $articulo) {
        $almCod = $articulo['alm_codigo'] ?? '';
        $almNom = function_exists('getNombreAlmacen') ? getNombreAlmacen($almCod) : '';

        echo "Almacen: {$almCod}" . ($almNom !== '' ? " - {$almNom}" : '') . "\n";
        echo "Articulo: {$articulo['art_codigo']}\n";
        echo "Descripcion: {$articulo['descripcion']}\n";
        echo "Stock actual: {$articulo['stock_actual']}\n";
        echo "Stock minimo: {$articulo['stock_minimo']}\n";
        echo "Estado: {$articulo['estado']}\n";
        echo "Band stock anterior: {$articulo['band_stock_anterior']}\n";
        echo "Band stock nuevo: {$articulo['band_stock_nueva']}\n";
        echo "Band correo anterior: {$articulo['band_correo_anterior']}\n";
        echo "Band correo nuevo: {$articulo['band_correo_nueva']}\n";
        echo "Correo pendiente: " . ($articulo['correo_pendiente'] ? 'SI' : 'NO') . "\n";
        echo "========================================\n";
    }

} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

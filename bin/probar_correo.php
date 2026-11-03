<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Manue\SistemaNotificaciones\EmailService;

$raiz = dirname(__DIR__);

require_once $raiz . '/vendor/autoload.php';

if (file_exists($raiz . '/.env')) {
    $dotenv = Dotenv::createImmutable($raiz);
    $dotenv->safeLoad();
}

try {
    $emailService = new EmailService();

    // Artículo simulado en alerta para la prueba
    $articulo = [
        'art_codigo'    => '020100149',
        'descripcion'   => 'AMARILLO HUEVO 21',
        'alm_codigo'    => '003',
        'unidad_medida' => 'KGS',
        'stock_actual'  => 250,
        'stock_minimo'  => 260
    ];

    $destinatarios = $emailService->obtenerDestinatariosPredeterminados();

    echo "Destinatarios detectados en .env:\n";
    foreach ($destinatarios as $correo) {
        echo "  - [{$correo}]\n";
    }
    echo "Total: " . count($destinatarios) . " destinatario(s)\n";
    echo "----------------------------------------\n";
    echo "Iniciando envío de correo de prueba...\n";

    $emailService->enviarAlerta($articulo, $destinatarios);

    echo "========================================\n";
    echo "¡CORREO ENVIADO CORRECTAMENTE A TODOS LOS DESTINATARIOS!\n";
    echo "========================================\n";

} catch (Throwable $e) {
    fwrite(
        STDERR,
        "ERROR: " . $e->getMessage() . PHP_EOL
    );
    exit(1);
}

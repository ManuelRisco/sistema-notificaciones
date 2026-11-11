<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Manue\SistemaNotificaciones\EmailService;

$raiz = dirname(__DIR__, 2);

require_once $raiz . '/vendor/autoload.php';
require_once $raiz . '/config/almacenes.php';

if (file_exists($raiz . '/.env')) {
    $dotenv = Dotenv::createImmutable($raiz);
    $dotenv->safeLoad();
}

try {
    $emailService = new EmailService();

    $almacenesSoportados = function_exists('getAlmacenesSoportados') ? getAlmacenesSoportados() : [];
    $almCodigoDefecto = !empty($almacenesSoportados) ? (string) array_key_first($almacenesSoportados) : '';

    // Permite pasar el código de almacén por argumento (ej: php probar_correo.php 001) o usar el primero de la configuración
    $almCodigo = isset($argv[1]) && trim((string)$argv[1]) !== '' ? trim((string)$argv[1]) : $almCodigoDefecto;

    if ($almCodigo === '') {
        throw new RuntimeException('No hay almacenes configurados en config/almacenes.php');
    }

    $nombreAlmacen = function_exists('getNombreAlmacen') ? getNombreAlmacen($almCodigo) : "Almacén {$almCodigo}";

    // Artículo simulado en alerta para la prueba
    $articulo = [
        'art_codigo'    => 'PRUEBA-TEST',
        'descripcion'   => "ARTÍCULO DE PRUEBA ({$nombreAlmacen})",
        'alm_codigo'    => $almCodigo,
        'unidad_medida' => 'UND',
        'stock_actual'  => 10,
        'stock_minimo'  => 25
    ];

    $destinatarios = $emailService->obtenerDestinatariosPorAlmacen($almCodigo);

    echo "Almacén evaluado: [{$almCodigo}] {$nombreAlmacen}\n";
    echo "Destinatarios detectados en .env (SISTEMAS_MAIL_DESTINO_{$almCodigo}):\n";
    foreach ($destinatarios as $correo) {
        echo "  - [{$correo}]\n";
    }
    echo "Total: " . count($destinatarios) . " destinatario(s)\n";
    echo "----------------------------------------\n";
    echo "Iniciando envío de correo de prueba...\n";

    $emailService->enviarAlertasPorAlmacen($almCodigo, [$articulo], $destinatarios);

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

<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Manue\SistemaNotificaciones\EmailService;
use Manue\SistemaNotificaciones\StockAlertService;
use Manue\SistemaNotificaciones\StockNotificationService;
use Manue\SistemaNotificaciones\StockRepository;

$raizProyecto = dirname(__DIR__);

require_once $raizProyecto . '/vendor/autoload.php';
// Cargar variables de entorno antes de conectar a SQL Server.
$dotenv = Dotenv::createImmutable($raizProyecto);
$dotenv->safeLoad();

require_once $raizProyecto . '/config/database.php';

$zonaHoraria = !empty($_ENV['APP_TIMEZONE']) ? (string)$_ENV['APP_TIMEZONE'] : ((string)getenv('APP_TIMEZONE'));
date_default_timezone_set($zonaHoraria);

// Argumentos de consola:
// --simular : no actualiza BD ni envía correos
// --detalle : fuerza mostrar el desglose de todos los artículos aunque no haya alertas
$modoSimulacion = in_array('--simular', $argv ?? [], true);
$mostrarDetalleCompleto = in_array('--detalle', $argv ?? [], true);

// -----------------------------------------------------------------------------
// Control de Concurrencia (Mutex): Asegura que solo un proceso corra a la vez
// -----------------------------------------------------------------------------
$lockDir = $raizProyecto . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0777, true);
}
$lockFilePath = $lockDir . DIRECTORY_SEPARATOR . 'alerta_proceso.lock';
$lockHandle = fopen($lockFilePath, 'c+');

if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    $fechaHora = date('d/m/Y H:i:s');
    echo "[{$fechaHora}] AVISO: Ya existe otro proceso de alertas en ejecución. Se omite esta ejecución para evitar duplicados." . PHP_EOL;
    if ($lockHandle) {
        fclose($lockHandle);
    }
    exit(0);
}

// Registrar PID y momento de inicio en el archivo de bloqueo
@ftruncate($lockHandle, 0);
@fwrite($lockHandle, "PID: " . getmypid() . " | Inicio: " . date('Y-m-d H:i:s') . PHP_EOL);
@fflush($lockHandle);

$conexion = null;

try {

    // 1. Conexión con SQL Server.
    $conexion = getSqlServerConn();

    // 2. Crear el repositorio.
    $repository = new StockRepository($conexion);

    // 3. Crear los servicios.
    $stockAlertService = new StockAlertService($repository);
    $emailService = new EmailService();

    // 4. Crear el servicio coordinador.
    $notificationService = new StockNotificationService(
        $stockAlertService,
        $emailService,
        $repository
    );

    // 5. Ejecutar el procesamiento.
    $resultado = $notificationService->ejecutar($modoSimulacion);

    
$fechaHora = date('d/m/Y H:i:s');
$prefijoModo = $modoSimulacion ? '[SIMULACION] ' : '';

// Identificar todos los artículos que continúan en alerta.
$articulosEnAlerta = array_filter(
    $resultado['resultados'],
    fn(array $articulo): bool =>
        ($articulo['estado'] ?? '') === 'ALERTA'
);

$cantidadAlertasActivas = count($articulosEnAlerta);

$articulosPendientes = (int) (
    $resultado['articulos_pendientes'] ?? 0
);

$correosEnviados = (int) (
    $resultado['correos_enviados'] ?? 0
);

$correosFallidos = (int) (
    $resultado['correos_fallidos'] ?? 0
);

$erroresBanderas = (int) (
    $resultado['errores_actualizacion_banderas'] ?? 0
);

// Determinar si hubo una notificación pendiente o algún error.
$requiereDetalle =
    $articulosPendientes > 0
    || $correosEnviados > 0
    || $correosFallidos > 0
    || $erroresBanderas > 0;

// Si no existen novedades, registrar una sola línea.
if (!$requiereDetalle && !$mostrarDetalleCompleto) {

    if ($cantidadAlertasActivas > 0) {

        echo "[{$fechaHora}] {$prefijoModo}"
            . "{$resultado['total_articulos']} articulos revisados. "
            . "Alertas activas: {$cantidadAlertasActivas}. "
            . "Sin nuevos correos pendientes."
            . PHP_EOL;

    } else {

        echo "[{$fechaHora}] {$prefijoModo}"
            . "OK: {$resultado['total_articulos']} articulos revisados. "
            . "Stock suficiente. Sin alertas."
            . PHP_EOL;
    }

} else {

    // Mostrar información detallada cuando haya novedades o se solicite.
    echo PHP_EOL;
    echo "========================================" . PHP_EOL;
    echo " SISTEMA DE ALERTAS DE STOCK" . PHP_EOL;
    echo "========================================" . PHP_EOL;

    echo "Fecha: {$fechaHora}" . PHP_EOL;

    echo "Modo: "
        . ($modoSimulacion ? "SIMULACION" : "PRODUCCION")
        . PHP_EOL;

    echo "Articulos procesados: "
        . $resultado['total_articulos']
        . PHP_EOL;

    echo "Alertas activas: "
        . $cantidadAlertasActivas
        . PHP_EOL;

    echo "Articulos que requerian notificacion: "
        . $articulosPendientes
        . PHP_EOL;

    echo "Correos enviados: "
        . $correosEnviados
        . PHP_EOL;

    echo "Correos fallidos: "
        . $correosFallidos
        . PHP_EOL;

    echo "Errores al actualizar banderas: "
        . $erroresBanderas
        . PHP_EOL;

    echo "----------------------------------------" . PHP_EOL;

    foreach ($resultado['resultados'] as $articulo) {

        $errorCorreo = $articulo['error_correo'] ?? null;

        $errorBandera =
            $articulo['error_actualizacion_bandera'] ?? null;

        $esCritico =
            ($articulo['estado'] === 'ALERTA')
            || $articulo['correo_enviado']
            || ($errorCorreo !== null)
            || ($errorBandera !== null)
            || $articulo['correo_pendiente'];

        if ($esCritico || $mostrarDetalleCompleto) {

            echo "Articulo: "
                . $articulo['art_codigo']
                . " - "
                . $articulo['descripcion']
                . PHP_EOL;

            echo "Almacen: "
                . $articulo['alm_codigo']
                . PHP_EOL;

            echo "Stock actual: "
                . $articulo['stock_actual']
                . " "
                . ($articulo['unidad_medida'] ?? '')
                . PHP_EOL;

            echo "Stock minimo: "
                . $articulo['stock_minimo']
                . " "
                . ($articulo['unidad_medida'] ?? '')
                . PHP_EOL;

            echo "Estado: "
                . $articulo['estado']
                . PHP_EOL;

            echo "Correo: ";

            if ($errorCorreo !== null) {

                echo "ERROR: " . $errorCorreo;

            } elseif ($errorBandera !== null) {

                echo "ENVIADO, PERO NO SE ACTUALIZO SQL SERVER: "
                    . $errorBandera;

            } elseif ($articulo['correo_enviado']) {

                echo "INCLUIDO EN EL CORREO AGRUPADO";

            } elseif ($articulo['correo_pendiente']) {

                echo "PENDIENTE";

            } else {

                echo "SIN NUEVA NOTIFICACION";
            }

            echo PHP_EOL;
            echo "----------------------------------------" . PHP_EOL;
        }
    }
}

} catch (Throwable $e) {

    $fechaHora = date('d/m/Y H:i:s');
    fwrite(
        STDERR,
        "[{$fechaHora}] ERROR CRITICO: " . $e->getMessage() . PHP_EOL
    );

    exit(1);

} finally {

    if ($conexion !== null && $conexion !== false) {
        sqlsrv_close($conexion);
    }

    // Liberar candado de ejecución única
    if (isset($lockHandle) && is_resource($lockHandle)) {
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }
}

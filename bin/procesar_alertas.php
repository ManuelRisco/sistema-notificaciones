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
// --almacen=XXX : procesa únicamente el almacén indicado (ej: 003 o 001)
$modoSimulacion = in_array('--simular', $argv ?? [], true);
$mostrarDetalleCompleto = in_array('--detalle', $argv ?? [], true);

$almacenFiltro = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--almacen=')) {
        $candAlm = trim(substr($arg, 10));
        if (function_exists('esAlmacenSoportado') && !esAlmacenSoportado($candAlm)) {
            $fechaHora = date('d/m/Y H:i:s');
            fwrite(STDERR, "[{$fechaHora}] ERROR: El almacén '{$candAlm}' no está soportado en la configuración." . PHP_EOL);
            exit(1);
        }
        $almacenFiltro = $candAlm;
    }
}

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

/**
 * Registra una entrada en el log del almacén correspondiente según la estructura:
 * logs/<almacen>/<YYYY-MM>/procesamiento_<YYYY-MM-DD>.log
 */
function registrarLogAlmacen(string $raizProyecto, string $almCodigo, string $contenido): void
{
    $contenido = trim($contenido);
    if ($contenido === '') {
        return;
    }

    $fecha = date('Y-m-d');
    $anioMes = date('Y-m');
    $dirLog = $raizProyecto
        . DIRECTORY_SEPARATOR . 'logs'
        . DIRECTORY_SEPARATOR . $almCodigo
        . DIRECTORY_SEPARATOR . $anioMes;

    if (!is_dir($dirLog)) {
        @mkdir($dirLog, 0777, true);
    }

    $archivoLog = $dirLog . DIRECTORY_SEPARATOR . "procesamiento_{$fecha}.log";
    @file_put_contents(
        $archivoLog,
        $contenido . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

/**
 * Formatea la salida de un almacén para el log y consola.
 */
function formatearSalidaAlmacen(
    string $almCodigo,
    array $articulos,
    bool $modoSimulacion,
    bool $mostrarDetalleCompleto,
    string $fechaHora
): string {
    $total = count($articulos);
    $articulosEnAlerta = array_filter(
        $articulos,
        fn(array $a): bool => ($a['estado'] ?? '') === 'ALERTA'
    );
    $cantidadAlertasActivas = count($articulosEnAlerta);

    $articulosPendientes = count(array_filter($articulos, fn($a) => !empty($a['correo_pendiente'])));
    $correosEnviados = count(array_filter($articulos, fn($a) => !empty($a['correo_enviado'])));
    $fallidos = count(array_filter($articulos, fn($a) => !empty($a['error_correo'])));
    $erroresBanderas = count(array_filter($articulos, fn($a) => !empty($a['error_actualizacion_bandera'])));

    $prefijoModo = $modoSimulacion ? '[SIMULACION] ' : '';
    $nombreAlmacen = function_exists('getNombreAlmacen')
        ? getNombreAlmacen($almCodigo)
        : ($almCodigo === '001' ? 'Materia Prima' : ($almCodigo === '003' ? 'Tintas y Solventes' : "Almacén {$almCodigo}"));

    $requiereDetalle =
        $articulosPendientes > 0
        || $correosEnviados > 0
        || $fallidos > 0
        || $erroresBanderas > 0;

    if (!$requiereDetalle && !$mostrarDetalleCompleto) {
        if ($cantidadAlertasActivas > 0) {
            return "[{$fechaHora}] {$prefijoModo}[{$almCodigo} - {$nombreAlmacen}] "
                . "{$total} articulos revisados. Alertas activas: {$cantidadAlertasActivas}. "
                . "Sin nuevos correos pendientes.";
        }
        return "[{$fechaHora}] {$prefijoModo}[{$almCodigo} - {$nombreAlmacen}] "
            . "OK: {$total} articulos revisados. Stock suficiente. Sin alertas.";
    }

    $lineas = [];
    $lineas[] = "";
    $lineas[] = "========================================";
    $lineas[] = " SISTEMA DE ALERTAS - " . strtoupper($nombreAlmacen) . " ({$almCodigo})";
    $lineas[] = "========================================";
    $lineas[] = "Fecha: {$fechaHora}";
    $lineas[] = "Modo: " . ($modoSimulacion ? "SIMULACION" : "PRODUCCION");
    $lineas[] = "Articulos procesados: {$total}";
    $lineas[] = "Alertas activas: {$cantidadAlertasActivas}";
    $lineas[] = "Articulos que requerian notificacion: {$articulosPendientes}";
    $lineas[] = "Correos enviados: {$correosEnviados}";
    $lineas[] = "Correos fallidos: {$fallidos}";
    $lineas[] = "Errores al actualizar banderas: {$erroresBanderas}";
    $lineas[] = "----------------------------------------";

    foreach ($articulos as $articulo) {
        $errorCorreo = $articulo['error_correo'] ?? null;
        $errorBandera = $articulo['error_actualizacion_bandera'] ?? null;

        $esCritico =
            ($articulo['estado'] === 'ALERTA')
            || !empty($articulo['correo_enviado'])
            || ($errorCorreo !== null)
            || ($errorBandera !== null)
            || !empty($articulo['correo_pendiente']);

        if ($esCritico || $mostrarDetalleCompleto) {
            $lineas[] = "Articulo: " . $articulo['art_codigo'] . " - " . $articulo['descripcion'];
            $lineas[] = "Almacen: " . $articulo['alm_codigo'] . " - " . $nombreAlmacen;
            $lineas[] = "Stock actual: " . $articulo['stock_actual'] . " " . ($articulo['unidad_medida'] ?? '');
            $lineas[] = "Stock minimo: " . $articulo['stock_minimo'] . " " . ($articulo['unidad_medida'] ?? '');
            $lineas[] = "Estado: " . $articulo['estado'];

            if ($errorCorreo !== null) {
                $lineas[] = "Correo: ERROR: " . $errorCorreo;
            } elseif ($errorBandera !== null) {
                $lineas[] = "Correo: ENVIADO, PERO NO SE ACTUALIZO SQL SERVER: " . $errorBandera;
            } elseif (!empty($articulo['correo_enviado'])) {
                $lineas[] = "Correo: INCLUIDO EN EL CORREO AGRUPADO";
            } elseif (!empty($articulo['correo_pendiente'])) {
                $lineas[] = "Correo: PENDIENTE";
            } else {
                $lineas[] = "Correo: SIN NUEVA NOTIFICACION";
            }

            $lineas[] = "----------------------------------------";
        }
    }

    return implode(PHP_EOL, $lineas);
}

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
    $resultado = $notificationService->ejecutar($modoSimulacion, $almacenFiltro);

    $fechaHora = date('d/m/Y H:i:s');

    // Agrupar los resultados por almacén
    $porAlmacen = [];
    foreach ($resultado['resultados'] as $art) {
        $alm = trim((string) ($art['alm_codigo'] ?? '003'));
        $porAlmacen[$alm][] = $art;
    }

    $salidasFormateadas = [];

    foreach ($porAlmacen as $almCodigo => $articulosAlm) {
        $textoAlmacen = formatearSalidaAlmacen(
            $almCodigo,
            $articulosAlm,
            $modoSimulacion,
            $mostrarDetalleCompleto,
            $fechaHora
        );

        // Guardar en el log correspondiente a este almacén:
        // logs/<almacen>/<YYYY-MM>/procesamiento_<YYYY-MM-DD>.log
        if (!$modoSimulacion) {
            registrarLogAlmacen($raizProyecto, $almCodigo, $textoAlmacen);
        }

        $salidasFormateadas[] = $textoAlmacen;
    }

    // Salida por consola / stdout (capturada por cron o ejecución manual)
    $salidaConsola = implode(PHP_EOL, $salidasFormateadas);
    echo $salidaConsola . PHP_EOL;

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

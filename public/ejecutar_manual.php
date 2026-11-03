<?php

declare(strict_types=1);

require_once __DIR__ . '/seguridad.php';

$raizProyecto = dirname(__DIR__);

require_once $raizProyecto . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable($raizProyecto);
$dotenv->safeLoad();

require_once $raizProyecto . '/config/database.php';

// Permitir únicamente solicitudes POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

try {
    // 1. Validar el token de seguridad CSRF
    validarTokenCsrf();

    $phpExe = 'C:\xampp\php\php.exe';
    if (!file_exists($phpExe)) {
        $phpExe = PHP_BINARY ?: 'php';
    }

    $scriptProcesar = $raizProyecto . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'procesar_alertas.php';

    $salida = '';
    $codigoSalida = 0;

    // 2. Ejecutar mediante el CLI de PHP (misma funcionalidad de ejecutar_alertas.bat)
    if (function_exists('exec')) {
        $comando = "\"{$phpExe}\" \"{$scriptProcesar}\" 2>&1";
        $lineas = [];
        exec($comando, $lineas, $codigoSalida);
        $salida = implode(PHP_EOL, $lineas);
    }

    // Fallback: Si exec no estuviera disponible, ejecutar en el mismo hilo de PHP
    if (empty($salida) && $codigoSalida === 0) {
        ob_start();
        require $scriptProcesar;
        $salida = (string) ob_get_clean();
    }

    // 3. Registrar la salida en el archivo de log diario
    $fecha = date('Y-m-d');
    $logDir = $raizProyecto . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    $logFile = $logDir . DIRECTORY_SEPARATOR . "procesamiento_{$fecha}.log";

    if (!empty($salida)) {
        @file_put_contents(
            $logFile,
            trim($salida) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    if ($codigoSalida !== 0) {
        throw new RuntimeException(
            "El proceso finalizó con código {$codigoSalida}. " . substr($salida, 0, 300)
        );
    }

    // Extraer la última línea de resumen relevante para la interfaz web
    $lineasSalida = array_values(array_filter(array_map('trim', explode("\n", $salida))));
    $resumen = !empty($lineasSalida) ? end($lineasSalida) : 'Revisión finalizada correctamente.';

    // Detectar si la solicitud proviene de AJAX / Fetch
    $esAjax = (
        (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_POST['formato']) && $_POST['formato'] === 'json')
    );

    if ($esAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'exito' => true,
            'mensaje' => 'Proceso de alertas ejecutado correctamente.',
            'resumen' => $resumen,
            'salida_completa' => $salida
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $_SESSION['flash_ejecucion'] = $resumen;
    header('Location: index.php?ok=ejecutado');
    exit;

} catch (Throwable $e) {
    $esAjax = (
        (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_POST['formato']) && $_POST['formato'] === 'json')
    );

    if ($esAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode([
            'exito' => false,
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $_SESSION['flash_error'] = 'Error al ejecutar alertas: ' . $e->getMessage();
    header('Location: index.php?error=ejecucion');
    exit;
}


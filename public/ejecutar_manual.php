<?php

declare(strict_types=1);

require_once __DIR__ . '/seguridad.php';

$raizProyecto = dirname(__DIR__);

// Proteger endpoint: requiere sesión activa y almacén activo autorizado
$almActivo = requerirAlmacenAutorizado();
$usuario = obtenerUsuarioAutenticado();
$esAdminGlobal = usuarioEsAdministradorGlobal($usuario);

// Permitir únicamente solicitudes POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

/**
 * Decodifica el JSON emitido por bin/procesar_alertas.php --json.
 */
function decodificarResultadoProceso(string $salida): ?array
{
    $recortada = trim($salida);
    if ($recortada === '') {
        return null;
    }

    $decodificado = json_decode($recortada, true);
    if (is_array($decodificado) && array_key_exists('exito', $decodificado)) {
        return $decodificado;
    }

    // Si hubiera avisos previos en stdout/stderr, buscar la última línea JSON válida.
    $lineas = array_reverse(explode("\n", $recortada));
    foreach ($lineas as $linea) {
        $linea = trim($linea);
        if ($linea !== '' && str_starts_with($linea, '{')) {
            $cand = json_decode($linea, true);
            if (is_array($cand) && array_key_exists('exito', $cand)) {
                return $cand;
            }
        }
    }

    return null;
}

/**
 * Construye el estado estructurado del envío de correo a partir del resultado del proceso.
 */
function construirEstadoCorreo(array $datosProceso): array
{
    $correosEnviados = (int) ($datosProceso['correos_enviados'] ?? 0);
    $correosFallidos = (int) ($datosProceso['correos_fallidos'] ?? 0);
    $erroresBanderas = (int) ($datosProceso['errores_actualizacion_banderas'] ?? 0);

    if ($correosFallidos > 0) {
        return [
            'estado_correo' => 'error',
            'correo_estado_mensaje' => 'Hubo un error al enviar el correo de notificación.',
        ];
    }

    if ($correosEnviados > 0 && $erroresBanderas > 0) {
        return [
            'estado_correo' => 'error',
            'correo_estado_mensaje' => 'Hubo un error al actualizar el estado de la notificación.',
        ];
    }

    if ($correosEnviados > 0) {
        return [
            'estado_correo' => 'enviado',
            'correo_estado_mensaje' => 'El correo de notificación se envió correctamente.',
        ];
    }

    return [
        'estado_correo' => 'ninguno',
        'correo_estado_mensaje' => '',
    ];
}

try {
    // 1. Validar el token de seguridad CSRF
    validarTokenCsrf();

    // 2. Determinar alcance de ejecución:
    // La ejecución manual desde la interfaz web procesa ÚNICAMENTE el almacén activo en el que se encuentra el usuario.
    $almacenParam = null;
    if (isset($_POST['almacen']) && trim((string)$_POST['almacen']) !== '') {
        $almacenParam = trim((string)$_POST['almacen']);
    }

    if (!$esAdminGlobal) {
        // Usuario normal: si intenta enviar otro almacén en el parámetro POST, rechazar
        if ($almacenParam !== null && $almacenParam !== $almActivo) {
            throw new RuntimeException('No tiene autorización para ejecutar el procesamiento de otro almacén.');
        }
        $almacenAProcesar = $almActivo;
    } else {
        // Perfil administrador: por defecto procesa el almacén activo donde está navegando;
        // si se especificó otro almacén soportado, se procesa ese almacén individual.
        if ($almacenParam !== null) {
            if (!esAlmacenSoportado($almacenParam)) {
                throw new RuntimeException('Almacén especificado no válido.');
            }
            $almacenAProcesar = $almacenParam;
        } else {
            $almacenAProcesar = $almActivo;
        }
    }

    // 3. Liberar de inmediato el archivo de sesión de PHP
    // Si un usuario abre dos pestañas o ventanas en el mismo navegador,
    // PHP por defecto bloquea la segunda hasta que termine la primera.
    // Al cerrar la sesión aquí, permitimos concurrencia real para que
    // el candado de alertas (flock) pueda detectar procesos simultáneos.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // Ruta del ejecutable PHP: configurable con PHP_EXE en .env (por defecto, el de XAMPP)
    $phpExe = trim((string) ($_ENV['PHP_EXE'] ?? '')) ?: 'C:\xampp\php\php.exe';
    if (!file_exists($phpExe)) {
        $phpExe = PHP_BINARY ?: 'php';
    }

    $scriptProcesar = $raizProyecto . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'procesar_alertas.php';
    $argAlmacen = $almacenAProcesar !== null ? " \"--almacen={$almacenAProcesar}\"" : '';

    $salida = '';
    $codigoSalida = 0;

    // 4. Ejecutar mediante el CLI de PHP (misma funcionalidad de ejecutar_alertas.bat)
    if (function_exists('exec')) {
        $comando = "\"{$phpExe}\" \"{$scriptProcesar}\"{$argAlmacen} --json 2>&1";
        $lineas = [];
        exec($comando, $lineas, $codigoSalida);
        $salida = implode(PHP_EOL, $lineas);
    }

    // Fallback: Si exec no estuviera disponible, ejecutar en el mismo hilo de PHP
    if (empty($salida) && $codigoSalida === 0) {
        $argv = ['procesar_alertas.php', '--json'];
        if ($almacenAProcesar !== null) {
            $argv[] = "--almacen={$almacenAProcesar}";
        }
        ob_start();
        require $scriptProcesar;
        $salida = (string) ob_get_clean();
    }

    // 5. El registro en el log correspondiente (logs/<base_de_datos>/<almacen>/<YYYY-MM>/procesamiento_<YYYY-MM-DD>.log)
    // es gestionado automáticamente por bin/procesar_alertas.php según el almacén procesado.

    if ($codigoSalida !== 0) {
        throw new RuntimeException(
            "El proceso finalizó con código {$codigoSalida}. " . substr($salida, 0, 300)
        );
    }

    $datosProceso = decodificarResultadoProceso($salida);

    // Detectar si el proceso se omitió porque ya había otro en curso
    $enCurso = ($datosProceso !== null && !empty($datosProceso['en_curso']))
        || strpos($salida, 'AVISO: Ya existe otro proceso de alertas en ejecución') !== false;

    $estadoCorreo = ($datosProceso !== null && !$enCurso)
        ? construirEstadoCorreo($datosProceso)
        : [
            'estado_correo' => $enCurso ? 'en_curso' : 'ninguno',
            'correo_estado_mensaje' => '',
        ];

    // Detectar si la solicitud proviene de AJAX / Fetch
    $esAjax = esSolicitudAjax();

    if ($esAjax) {
        header('Content-Type: application/json; charset=utf-8');
        $respuestaAjax = array_merge([
            'exito' => true,
            'en_curso' => $enCurso,
        ], $estadoCorreo);
        if ($enCurso) {
            $respuestaAjax['mensaje'] = 'Ya existe una revisión de stock en ejecución en este momento.';
        }
        echo json_encode($respuestaAjax, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    if ($enCurso) {
        $_SESSION['flash_ejecucion'] = 'Ya hay una revisión de alertas ejecutándose en segundo plano.';
        $_SESSION['flash_ejecucion_tipo'] = 'info';
    } elseif ($estadoCorreo['estado_correo'] === 'error') {
        $_SESSION['flash_ejecucion'] = htmlspecialchars(
            $estadoCorreo['correo_estado_mensaje'],
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $_SESSION['flash_ejecucion_tipo'] = 'warning';
    } elseif ($estadoCorreo['estado_correo'] === 'enviado') {
        $_SESSION['flash_ejecucion'] = 'Stock revisado correctamente.<br>' . htmlspecialchars(
            $estadoCorreo['correo_estado_mensaje'],
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $_SESSION['flash_ejecucion_tipo'] = 'success';
    } else {
        $_SESSION['flash_ejecucion'] = 'Stock revisado correctamente.';
        $_SESSION['flash_ejecucion_tipo'] = 'success';
    }
    header('Location: index.php?ok=ejecutado');
    exit;

} catch (Throwable $e) {
    $esAjax = esSolicitudAjax();

    if ($esAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode([
            'exito' => false,
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    $_SESSION['flash_error'] = 'Error al ejecutar alertas: ' . $e->getMessage();
    header('Location: index.php?error=ejecucion');
    exit;
}


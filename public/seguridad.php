<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/autenticacion.php';

// Si no es una página pública (como login.php o logout.php), exigir sesión
if (!defined('PAGINA_PUBLICA') || PAGINA_PUBLICA !== true) {
    requerirAutenticacion();
}

/**
 * Genera un token de seguridad para los formularios.
 */
function generarTokenCsrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Comprueba que el formulario tenga un token válido.
 */
function validarTokenCsrf(): void
{
    $tokenRecibido = $_POST['csrf_token'] ?? '';
    $tokenGuardado = $_SESSION['csrf_token'] ?? '';

    if (
        !is_string($tokenRecibido)
        || !is_string($tokenGuardado)
        || $tokenGuardado === ''
        || !hash_equals($tokenGuardado, $tokenRecibido)
    ) {
        throw new RuntimeException(
            'Solicitud no válida. Recarga la página e inténtalo nuevamente.'
        );
    }
}

/**
 * Escapa texto para mostrarlo de forma segura en HTML.
 */
if (!function_exists('h')) {
    function h(mixed $valor): string
    {
        return htmlspecialchars(
            (string) $valor,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}

/**
 * Indica si la solicitud actual espera una respuesta JSON (AJAX / fetch).
 */
function esSolicitudAjax(): bool
{
    return (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_POST['formato']) && $_POST['formato'] === 'json');
}

/**
 * Lee la cookie de modo oscuro y procesa el alternado (?toggle_dark_mode=1).
 * Al alternar guarda la cookie global y redirige a la misma URL sin ese parámetro.
 *
 * @return bool true si el modo oscuro está activo.
 */
function aplicarModoOscuro(): bool
{
    $darkMode = isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'true';

    if (!isset($_GET['toggle_dark_mode'])) {
        return $darkMode;
    }

    $darkMode = !$darkMode;

    // Guardar ÚNICAMENTE en la raíz "/" para compartir con todas las páginas
    setcookie('dark_mode', $darkMode ? 'true' : 'false', time() + (86400 * 30), '/');
    // Limpiar posibles cookies antiguas en subrutas
    setcookie('dark_mode', '', time() - 3600, dirname($_SERVER['SCRIPT_NAME']));
    setcookie('dark_mode', '', time() - 3600);

    $params = $_GET;
    unset($params['toggle_dark_mode']);
    $urlLimpia = strtok($_SERVER['REQUEST_URI'], '?');
    if (!empty($params)) {
        $urlLimpia .= '?' . http_build_query($params);
    }
    header('Location: ' . $urlLimpia);
    exit;
}

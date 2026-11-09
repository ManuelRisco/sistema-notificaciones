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

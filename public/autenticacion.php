<?php

declare(strict_types=1);

$raizProyecto = dirname(__DIR__);

if (!class_exists(\Dotenv\Dotenv::class) && file_exists($raizProyecto . '/vendor/autoload.php')) {
    require_once $raizProyecto . '/vendor/autoload.php';
}

if (class_exists(\Dotenv\Dotenv::class) && file_exists($raizProyecto . '/.env') && empty($_ENV['DB_SERVER'])) {
    $dotenv = \Dotenv\Dotenv::createImmutable($raizProyecto);
    $dotenv->safeLoad();
}

require_once $raizProyecto . '/config/database.php';

/**
 * Codifica una contraseña utilizando el algoritmo de ERPTECNOFILM.
 * Codificación compatible con Windows-1252 (CP1252).
 */
function encodePassword(string $text): string
{
    if ($text === '') {
        return '';
    }

    $raw = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    $len = strlen($raw);

    if ($len === 0) {
        return '';
    }

    $firstByte = ord($raw[0]);
    $shift = intdiv($firstByte, 2);

    $encoded = '';

    for ($i = 0; $i < $len; $i++) {
        $byte = ord($raw[$i]);
        $encoded .= chr(($byte + $shift) % 256);
    }

    return $encoded;
}

/**
 * Verifica una contraseña contra el valor almacenado en el ERP.
 */
function verifyPassword(string $plainPassword, string $storedPassword): bool
{
    $dbClaveRaw = rtrim($storedPassword);
    $inputClaveRaw = encodePassword($plainPassword);

    $inputClaveUtf8 = mb_convert_encoding(
        $inputClaveRaw,
        'UTF-8',
        'Windows-1252'
    );

    return hash_equals($dbClaveRaw, $inputClaveRaw)
        || hash_equals($dbClaveRaw, $inputClaveUtf8);
}

/**
 * Retorna la clave secreta utilizada para la firma del token de autenticación.
 */
function obtenerSecretoAuth(): string
{
    $secreto = $_ENV['AUTH_SECRET'] ?? getenv('AUTH_SECRET');
    if (is_string($secreto) && trim($secreto) !== '') {
        return trim($secreto);
    }

    return hash('sha256', 'sistema-notificaciones-ERPTECNOFILM-auth-secret-key-2026');
}

/**
 * Genera un token firmado con HMAC-SHA256 para el usuario autenticado.
 */
function generarTokenAuth(array $usuario): string
{
    $payloadData = [
        'usr_codigo' => trim((string) ($usuario['usr_codigo'] ?? '')),
        'usr_nombre' => trim((string) ($usuario['usr_nombre'] ?? '')),
        'pus_codigo' => trim((string) ($usuario['pus_codigo'] ?? '')),
        'creado_en'  => time(),
    ];

    $json = (string) json_encode($payloadData, JSON_UNESCAPED_UNICODE);
    $payloadBase64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    $firma = hash_hmac('sha256', $payloadBase64, obtenerSecretoAuth());

    return $payloadBase64 . '.' . $firma;
}

/**
 * Valida la firma del token y recupera los datos del usuario.
 */
function validarTokenAuth(string $token): ?array
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }

    $partes = explode('.', $token, 2);
    if (count($partes) !== 2) {
        return null;
    }

    [$payloadBase64, $firma] = $partes;
    if ($payloadBase64 === '' || $firma === '') {
        return null;
    }

    $firmaEsperada = hash_hmac('sha256', $payloadBase64, obtenerSecretoAuth());
    if (!hash_equals($firmaEsperada, $firma)) {
        return null;
    }

    $resto = strlen($payloadBase64) % 4;
    if ($resto > 0) {
        $payloadBase64 .= str_repeat('=', 4 - $resto);
    }

    $json = base64_decode(strtr($payloadBase64, '-_', '+/'), true);
    if ($json === false) {
        return null;
    }

    $datos = json_decode($json, true);
    if (!is_array($datos) || empty($datos['usr_codigo'])) {
        return null;
    }

    // Expiración configurable (por defecto 30 días)
    $creadoEn = (int) ($datos['creado_en'] ?? 0);
    $tiempoMaximo = 86400 * 30;
    if ($creadoEn <= 0 || (time() - $creadoEn) > $tiempoMaximo) {
        return null;
    }

    return [
        'usr_codigo' => trim((string) $datos['usr_codigo']),
        'usr_nombre' => trim((string) ($datos['usr_nombre'] ?? '')),
        'pus_codigo' => trim((string) ($datos['pus_codigo'] ?? '')),
    ];
}

/**
 * Consulta la base de datos (ERPTECNOFILM.dbo.adm_usuario) para verificar
 * el usuario, su contraseña y si está asignado como encargado del almacén 003.
 *
 * @throws RuntimeException Si el usuario está inactivo o no es encargado del almacén.
 */
function autenticarUsuario(string $usuario, string $clave, string $almCodigo = '003'): ?array
{
    $usuario = strtoupper(trim($usuario));
    if ($usuario === '' || $clave === '') {
        return null;
    }

    $conn = getSqlServerConn();
    $sql = "
       SELECT TOP 1
            RTRIM(u.usr_codigo) AS usr_codigo,
            RTRIM(u.pus_codigo) AS pus_codigo,
            RTRIM(u.usr_nombre) AS usr_nombre,
            u.usr_clave,
            u.usr_status,
            CASE
                WHEN EXISTS (
                    SELECT 1
                    FROM ERPTECNOFILM.dbo.log_almusr a
                    WHERE RTRIM(a.usr_codigo) = RTRIM(u.usr_codigo)
                      AND RTRIM(a.alm_codigo) = ?
                ) THEN 1
                ELSE 0
            END AS es_encargado_almacen
        FROM ERPTECNOFILM.dbo.adm_usuario u
        WHERE UPPER(RTRIM(u.usr_codigo)) = ?;
    ";

    $stmt = sqlsrv_query($conn, $sql, [$almCodigo, $usuario]);
    if ($stmt === false) {
        return null;
    }

    $fila = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$fila) {
        return null;
    }

    // 1. Comprobar estado activo del usuario
    if (isset($fila['usr_status']) && (int) $fila['usr_status'] !== 1) {
        throw new RuntimeException('El usuario se encuentra inactivo.');
    }

    // 2. Verificar contraseña con el algoritmo de ERPTECNOFILM
    if (!verifyPassword($clave, (string) ($fila['usr_clave'] ?? ''))) {
        return null;
    }

    // 3. Validar si está asignado como encargado del almacén
    if ((int) ($fila['es_encargado_almacen'] ?? 0) !== 1) {
        throw new RuntimeException('El usuario no es encargado del almacén.');
    }

    return [
        'usr_codigo' => trim((string) $fila['usr_codigo']),
        'pus_codigo' => trim((string) $fila['pus_codigo']),
        'usr_nombre' => trim((string) $fila['usr_nombre']),
    ];
}

/**
 * Registra la sesión y envía la cookie con el token hasheado al navegador.
 */
function iniciarSesionUsuario(array $usuario, bool $recordar = true): string
{
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        session_start();
    }

    $token = generarTokenAuth($usuario);

    $_SESSION['usuario'] = $usuario;
    $_SESSION['auth_token'] = $token;

    // Cookie persistente en el navegador
    $duracion = $recordar ? (time() + 86400 * 30) : 0;
    if (!headers_sent()) {
        setcookie('auth_token', $token, [
            'expires'  => $duracion,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    return $token;
}

/**
 * Obtiene los datos del usuario actual desde la sesión o a través del token del navegador.
 */
function obtenerUsuarioAutenticado(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        session_start();
    }

    // 1. Verificar sesión activa en servidor
    if (!empty($_SESSION['usuario']) && is_array($_SESSION['usuario'])) {
        return $_SESSION['usuario'];
    }

    // 2. Verificar token hasheado enviado por el navegador
    $tokenCookie = $_COOKIE['auth_token'] ?? '';
    if (is_string($tokenCookie) && $tokenCookie !== '') {
        $usuario = validarTokenAuth($tokenCookie);
        if ($usuario !== null) {
            $_SESSION['usuario'] = $usuario;
            $_SESSION['auth_token'] = $tokenCookie;
            return $usuario;
        }
    }

    return null;
}

/**
 * Comprueba si el usuario se encuentra autenticado.
 */
function estaAutenticado(): bool
{
    return obtenerUsuarioAutenticado() !== null;
}

/**
 * Cierra la sesión activa y elimina las cookies del navegador.
 */
function cerrarSesionUsuario(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        session_start();
    }

    unset($_SESSION['usuario'], $_SESSION['auth_token']);

    if (isset($_COOKIE['auth_token']) && !headers_sent()) {
        setcookie('auth_token', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        unset($_COOKIE['auth_token']);
    }
}

/**
 * Protege la ruta actual: si no está autenticado, redirige al login o devuelve 401 en AJAX.
 */
function requerirAutenticacion(): void
{
    if (estaAutenticado()) {
        return;
    }

    $esAjax = (
        (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_POST['formato']) && $_POST['formato'] === 'json')
    );

    if ($esAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode([
            'exito' => false,
            'error' => 'No autorizado. Por favor inicie sesión.',
            'redirigir' => 'login.php'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Location: login.php');
    exit;
}

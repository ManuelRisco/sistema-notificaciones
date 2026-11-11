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
 *
 * @throws RuntimeException Si falta configurar la variable AUTH_SECRET en .env o está vacía.
 */
function obtenerSecretoAuth(): string
{
    $secreto = $_ENV['AUTH_SECRET'] ?? $_SERVER['AUTH_SECRET'] ?? getenv('AUTH_SECRET');
    if (!is_string($secreto) || trim($secreto) === '') {
        throw new RuntimeException(
            'Falta configurar la variable AUTH_SECRET en el archivo .env.'
        );
    }

    $secreto = trim($secreto);
    if (strlen($secreto) < 32) {
        throw new RuntimeException(
            'La variable AUTH_SECRET configurada en .env debe tener al menos 32 caracteres.'
        );
    }

    return $secreto;
}

/**
 * Determina si el usuario tiene un perfil de administrador global.
 * Un administrador global puede ejecutar el procesamiento de todos los almacenes.
 */
function usuarioEsAdministradorGlobal(?array $usuario = null): bool
{
    if ($usuario === null) {
        $usuario = obtenerUsuarioAutenticado();
    }
    if ($usuario === null) {
        return false;
    }

    $usrCodigo = strtoupper(trim((string) ($usuario['usr_codigo'] ?? '')));
    $pusCodigo = strtoupper(trim((string) ($usuario['pus_codigo'] ?? '')));

    return $usrCodigo === 'ADMINISTRA' || in_array($pusCodigo, ['SISTEMAS', 'ADMINISTRACION'], true);
}

/**
 * Genera un token firmado con HMAC-SHA256 para el usuario autenticado.
 */
function generarTokenAuth(array $usuario): string
{
    $payloadData = [
        'emp_codigo' => trim((string) ($usuario['emp_codigo'] ?? '001')),
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
        'emp_codigo' => trim((string) ($datos['emp_codigo'] ?? '001')),
        'usr_codigo' => trim((string) $datos['usr_codigo']),
        'usr_nombre' => trim((string) ($datos['usr_nombre'] ?? '')),
        'pus_codigo' => trim((string) ($datos['pus_codigo'] ?? '')),
    ];
}

/**
 * Consulta la base de datos (adm_usuario) para verificar
 * el usuario, su contraseña y sus permisos de almacén en log_almusr.
 *
 * @throws RuntimeException Si el usuario está inactivo o no tiene almacenes autorizados.
 */
function autenticarUsuario(string $usuario, string $clave, ?string $almCodigo = null): ?array
{
    $usuario = strtoupper(trim($usuario));
    if ($usuario === '' || $clave === '') {
        return null;
    }

    $conn = getSqlServerConn();
    $db = getDatabaseName();
    $sql = "
       SELECT TOP 1
            RTRIM(u.emp_codigo) AS emp_codigo,
            RTRIM(u.usr_codigo) AS usr_codigo,
            RTRIM(u.pus_codigo) AS pus_codigo,
            RTRIM(u.usr_nombre) AS usr_nombre,
            u.usr_clave,
            u.usr_status
        FROM [{$db}].dbo.adm_usuario u
        WHERE UPPER(RTRIM(u.usr_codigo)) = ?;
    ";

    $stmt = sqlsrv_query($conn, $sql, [$usuario]);
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

    $usrCodigo = trim((string) $fila['usr_codigo']);
    $empCodigo = trim((string) ($fila['emp_codigo'] ?? '001'));
    if ($empCodigo === '') {
        $empCodigo = '001';
    }

    // 3. Obtener almacenes asignados filtrando por usuario y empresa
    $almacenesAsignados = obtenerAlmacenesAutorizadosUsuario($usrCodigo, $empCodigo);

    if (empty($almacenesAsignados)) {
        throw new RuntimeException('No tiene autorización para ingresar a este almacén. Solo el personal encargado de este almacén puede acceder.');
    }

    // Si se especificó un almacén concreto para validar acceso directo:
    if ($almCodigo !== null && !in_array($almCodigo, $almacenesAsignados, true)) {
        throw new RuntimeException('No tiene autorización para ingresar a este almacén. Solo el personal encargado de este almacén puede acceder.');
    }

    return [
        'emp_codigo' => $empCodigo,
        'usr_codigo' => $usrCodigo,
        'pus_codigo' => trim((string) $fila['pus_codigo']),
        'usr_nombre' => trim((string) $fila['usr_nombre']),
        'almacenes'  => $almacenesAsignados,
    ];
}

/**
 * Consulta la base de datos (log_almusr) para obtener los códigos de almacenes
 * autorizados para el usuario, filtrando por la empresa (emp_codigo) y por los almacenes
 * soportados en el sistema (003, 001).
 *
 * NOTA DE SEGURIDAD Y ANÁLISIS DE ERP:
 * El campo `usr_admalm` en la tabla `log_almusr` de este ERP tiene valor 0 en todos los
 * registros existentes (incluso para el usuario ADMINISTRA). Por tanto, la autorización
 * se basa en la existencia del registro en log_almusr para (usr_codigo, emp_codigo),
 * sin imponer usr_admalm = 1 para no bloquear a los encargados reales de almacén.
 */
function obtenerAlmacenesAutorizadosUsuario(string $usrCodigo, string $empCodigo = '001'): array
{
    $usrCodigo = strtoupper(trim($usrCodigo));
    $empCodigo = trim($empCodigo);
    if ($usrCodigo === '') {
        return [];
    }

    if ($empCodigo === '') {
        $empCodigo = '001';
    }

    $conn = getSqlServerConn();
    $db = getDatabaseName();

    $sql = "
        SELECT DISTINCT RTRIM(a.alm_codigo) AS alm_codigo
        FROM [{$db}].dbo.log_almusr a
        WHERE UPPER(RTRIM(a.usr_codigo)) = ?
          AND RTRIM(a.emp_codigo) = ?;
    ";

    $stmt = sqlsrv_query($conn, $sql, [$usrCodigo, $empCodigo]);
    if ($stmt === false) {
        return [];
    }

    $almacenes = [];
    $soportados = getAlmacenesSoportados();

    while ($fila = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $alm = trim((string) ($fila['alm_codigo'] ?? ''));
        if ($alm !== '' && isset($soportados[$alm])) {
            $almacenes[] = $alm;
        }
    }
    sqlsrv_free_stmt($stmt);

    return array_values(array_unique($almacenes));
}

/**
 * Verifica en tiempo real con SQL Server si un usuario tiene permiso asignado para un almacén específico.
 */
function usuarioTienePermisoAlmacen(string $usrCodigo, string $almCodigo, string $empCodigo = '001'): bool
{
    $autorizados = obtenerAlmacenesAutorizadosUsuario($usrCodigo, $empCodigo);
    return in_array(trim($almCodigo), $autorizados, true);
}

/**
 * Obtiene el almacén seleccionado actualmente en la sesión del usuario.
 */
function obtenerAlmacenActivo(): ?string
{
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        session_start();
    }

    $alm = $_SESSION['almacen_activo'] ?? null;
    return is_string($alm) && trim($alm) !== '' ? trim($alm) : null;
}

/**
 * Establece el almacén activo en la sesión verificando previamente los permisos del usuario.
 *
 * @throws RuntimeException Si el usuario no tiene permisos para el almacén solicitado.
 */
function establecerAlmacenActivo(string $almCodigo): void
{
    $usuario = obtenerUsuarioAutenticado();
    if ($usuario === null) {
        throw new RuntimeException('Debe iniciar sesión para seleccionar un almacén.');
    }

    $almCodigo = trim($almCodigo);
    $empCodigo = (string) ($usuario['emp_codigo'] ?? '001');
    if (!esAlmacenSoportado($almCodigo) || !usuarioTienePermisoAlmacen($usuario['usr_codigo'], $almCodigo, $empCodigo)) {
        throw new RuntimeException('No tiene autorización para ingresar a este almacén. Solo el personal encargado de este almacén puede acceder.');
    }

    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        session_start();
    }

    $_SESSION['almacen_activo'] = $almCodigo;
}

/**
 * Valida la autenticación y que el usuario tenga autorización sobre el almacén indicado
 * (o el almacén activo en sesión). Si no está autorizado o no ha seleccionado almacén,
 * redirige o emite error 403 con mensaje claro.
 */
function requerirAlmacenAutorizado(?string $almCodigo = null): string
{
    requerirAutenticacion();
    $usuario = obtenerUsuarioAutenticado();

    $alm = $almCodigo !== null && trim($almCodigo) !== ''
        ? trim($almCodigo)
        : obtenerAlmacenActivo();

    $mensajeNoAutorizado = 'No tiene autorización para ingresar a este almacén. Solo el personal encargado de este almacén puede acceder.';

    // Si aún no ha seleccionado ningún almacén en sesión, enviar a la pantalla de selección
    if ($alm === null || $alm === '') {
        header('Location: seleccionar_almacen.php');
        exit;
    }

    // Verificar si el almacén es soportado y si el usuario está autorizado
    $empCodigo = (string) ($usuario['emp_codigo'] ?? '001');
    if (!esAlmacenSoportado($alm) || !usuarioTienePermisoAlmacen($usuario['usr_codigo'], $alm, $empCodigo)) {
        $esAjax = esSolicitudAjax();

        if ($esAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode([
                'exito' => false,
                'error' => $mensajeNoAutorizado,
                'redirigir' => 'seleccionar_almacen.php'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        renderizarErrorAutorizacion($mensajeNoAutorizado);
        exit;
    }

    // Almacenar en sesión el almacén válido consultado
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        session_start();
    }
    $_SESSION['almacen_activo'] = $alm;

    return $alm;
}

/**
 * Renderiza una vista de error amigable cuando el usuario intenta forzar el acceso a un almacén no autorizado.
 */
function renderizarErrorAutorizacion(string $mensaje): void
{
    $darkMode = isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'true';
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="es" class="<?= $darkMode ? 'dark-mode' : '' ?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Acceso Denegado - Tecnofilm</title>
        <link rel="stylesheet" href="css/estilos.css">
        <script src="js/sweetalert2.all.min.js"></script>
    </head>
    <body style="display:flex;align-items:center;justify-content:center;min-height:100vh;background-color:var(--color-fondo,#f1f5f9);padding:20px;">
        <div style="max-width:520px;width:100%;background:var(--color-tarjeta,#ffffff);border-radius:12px;padding:36px;box-shadow:var(--sombra-tarjeta,0 4px 6px -1px rgba(0,0,0,0.1));text-align:center;border:1px solid var(--color-borde,#e2e8f0);">
            <div style="font-size:48px;margin-bottom:16px;">🚫</div>
            <h2 style="color:var(--color-peligro,#dc2626);font-size:22px;margin:0 0 12px 0;">Acceso No Autorizado</h2>
            <p style="color:var(--color-texto,#334155);font-size:15px;line-height:1.6;margin-bottom:28px;">
                <?= htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') ?>
            </p>
            <div style="display:flex;gap:12px;justify-content:center;">
                <a href="seleccionar_almacen.php" class="btn btn-primario" style="text-decoration:none;">
                    Ir a Selección de Almacén
                </a>
                <a href="logout.php" class="btn btn-secundario" style="text-decoration:none;">
                    Cerrar Sesión
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
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
        $datosToken = validarTokenAuth($tokenCookie);
        if ($datosToken !== null) {
            $usrCodigo = strtoupper(trim((string) ($datosToken['usr_codigo'] ?? '')));
            if ($usrCodigo !== '') {
                try {
                    $conn = getSqlServerConn();
                    $db = getDatabaseName();
                    $sql = "
                        SELECT TOP 1
                            RTRIM(u.emp_codigo) AS emp_codigo,
                            RTRIM(u.usr_codigo) AS usr_codigo,
                            RTRIM(u.pus_codigo) AS pus_codigo,
                            RTRIM(u.usr_nombre) AS usr_nombre,
                            u.usr_status
                        FROM [{$db}].dbo.adm_usuario u
                        WHERE UPPER(RTRIM(u.usr_codigo)) = ?;
                    ";
                    $stmt = sqlsrv_query($conn, $sql, [$usrCodigo]);
                    if ($stmt !== false) {
                        $fila = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                        sqlsrv_free_stmt($stmt);

                        if ($fila && (!isset($fila['usr_status']) || (int) $fila['usr_status'] === 1)) {
                            $empCodigo = trim((string) ($fila['emp_codigo'] ?? '001'));
                            $almacenes = obtenerAlmacenesAutorizadosUsuario($usrCodigo, $empCodigo);

                            if (!empty($almacenes)) {
                                $usuario = [
                                    'emp_codigo' => $empCodigo !== '' ? $empCodigo : '001',
                                    'usr_codigo' => trim((string) $fila['usr_codigo']),
                                    'pus_codigo' => trim((string) $fila['pus_codigo']),
                                    'usr_nombre' => trim((string) $fila['usr_nombre']),
                                    'almacenes'  => $almacenes,
                                ];

                                $_SESSION['usuario'] = $usuario;
                                $_SESSION['auth_token'] = $tokenCookie;

                                // Si el almacén previamente seleccionado en sesión ya no está autorizado, se limpia
                                if (!empty($_SESSION['almacen_activo']) && !in_array($_SESSION['almacen_activo'], $almacenes, true)) {
                                    unset($_SESSION['almacen_activo']);
                                }

                                return $usuario;
                            }
                        }
                    }
                } catch (Throwable) {
                    // En caso de error de BD, no restaurar sesión insegura
                }
            }

            // Si el token es inválido, el usuario no existe, está inactivo o sin permisos, cerrar sesión
            cerrarSesionUsuario();
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

    unset($_SESSION['usuario'], $_SESSION['auth_token'], $_SESSION['almacen_activo']);

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

    $esAjax = esSolicitudAjax();

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

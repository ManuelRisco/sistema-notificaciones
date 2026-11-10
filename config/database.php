<?php

$raizProyecto = dirname(__DIR__);

// Asegurar carga de autoload si aún no está disponible
if (!class_exists(\Dotenv\Dotenv::class) && file_exists($raizProyecto . '/vendor/autoload.php')) {
    require_once $raizProyecto . '/vendor/autoload.php';
}

// Cargar variables de entorno si Dotenv existe y aún no se han cargado
if (class_exists(\Dotenv\Dotenv::class) && file_exists($raizProyecto . '/.env') && empty($_ENV['DB_SERVER'])) {
    $dotenv = \Dotenv\Dotenv::createImmutable($raizProyecto);
    $dotenv->safeLoad();
}

// Configurar zona horaria de la aplicación con fallback seguro a America/Lima
$zonaHoraria = !empty($_ENV['APP_TIMEZONE'])
    ? (string)$_ENV['APP_TIMEZONE']
    : ((string)getenv('APP_TIMEZONE') ?: 'America/Lima');

if (trim($zonaHoraria) === '') {
    $zonaHoraria = 'America/Lima';
}

date_default_timezone_set($zonaHoraria);

/**
 * Retorna el nombre de la base de datos configurada en las variables de entorno (.env).
 *
 * @throws RuntimeException Si DB_DATABASE no está configurada o contiene caracteres no permitidos.
 */
function getDatabaseName(): string
{
    $db = $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? getenv('DB_DATABASE');

    if (!is_string($db) || trim($db) === '') {
        throw new RuntimeException(
            'Falta configurar la variable DB_DATABASE en el archivo .env.'
        );
    }

    $db = trim($db);

    if (!preg_match('/\A[A-Za-z0-9_]+\z/', $db)) {
        throw new RuntimeException(
            "El nombre de la base de datos '{$db}' configurado en DB_DATABASE contiene caracteres no permitidos. Solo se permiten letras, números y guiones bajos."
        );
    }

    return $db;
}

function getSqlServerConn(): mixed
{
    static $conn = null;

    if ($conn !== null && is_resource($conn)) {
        return $conn;
    }

    if (!function_exists('sqlsrv_connect')) {
        throw new RuntimeException(
            'La extensión SQLSRV no está instalada o habilitada en PHP.'
        );
    }

    // Configuración del driver
    if (function_exists('sqlsrv_configure')) {
        sqlsrv_configure('WarningsReturnAsErrors', 0);
    }

    $serverName = $_ENV['DB_SERVER'] ?? $_SERVER['DB_SERVER'] ?? getenv('DB_SERVER');
    $database   = getDatabaseName();
    $username   = $_ENV['DB_USERNAME'] ?? $_SERVER['DB_USERNAME'] ?? getenv('DB_USERNAME');
    $password   = $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? getenv('DB_PASSWORD');

    if (
        $serverName === '' ||
        $username === '' ||
        $password === ''
    ) {
        throw new RuntimeException(
            'Faltan datos de configuración de SQL Server.'
        );
    }

    $connectionInfo = [
        'Database' => $database,
        'UID' => $username,
        'PWD' => $password,
        'CharacterSet' => 'UTF-8',
        'TrustServerCertificate' => true
    ];

    $conn = sqlsrv_connect($serverName, $connectionInfo);

    if ($conn === false) {

        $errors = sqlsrv_errors(SQLSRV_ERR_ALL) ?: [];

        $details = [];

        foreach ($errors as $error) {
            $details[] = sprintf(
                'Código: %s - %s',
                $error['code'] ?? 'N/A',
                $error['message'] ?? 'Error desconocido'
            );
        }

        $conn = null;

        throw new RuntimeException(
            'Error de conexión: ' . implode(PHP_EOL, $details)
        );
    }

    return $conn;
}

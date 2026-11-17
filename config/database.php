<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Valida y limpia el identificador de la base de datos.
 *
 * @throws RuntimeException Si el nombre está vacío o contiene caracteres no permitidos.
 */
function validarNombreBaseDatos(mixed $db): string
{
    if (!is_string($db) || trim($db) === '') {
        throw new RuntimeException(
            'Falta configurar la variable DB_DATABASE en el archivo .env.'
        );
    }

    $limpia = trim($db);

    if (!preg_match('/\A[A-Za-z0-9_]+\z/', $limpia)) {
        throw new RuntimeException(
            "El nombre de la base de datos '{$limpia}' configurado en DB_DATABASE contiene caracteres no permitidos. Solo se permiten letras, números y guiones bajos."
        );
    }

    return $limpia;
}

/**
 * Retorna el nombre de la base de datos configurada en las variables de entorno (.env).
 *
 * @throws RuntimeException Si DB_DATABASE no está configurada o contiene caracteres no permitidos.
 */
function getDatabaseName(): string
{
    if (!function_exists('obtenerVariableEntorno')) {
        require_once __DIR__ . '/bootstrap.php';
    }

    return validarNombreBaseDatos(obtenerVariableEntorno('DB_DATABASE'));
}

/**
 * Devuelve una conexión reutilizable con SQL Server mediante la extensión sqlsrv.
 */
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

    if (function_exists('sqlsrv_configure')) {
        sqlsrv_configure('WarningsReturnAsErrors', 0);
    }

    if (!function_exists('obtenerVariableEntorno')) {
        require_once __DIR__ . '/bootstrap.php';
    }

    $serverName = obtenerVariableEntorno('DB_SERVER');
    $database   = getDatabaseName();
    $username   = obtenerVariableEntorno('DB_USERNAME');
    // No aplicar trim a la contraseña: debe conservarse exactamente.
    $password   = obtenerVariableEntorno('DB_PASSWORD', false);

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

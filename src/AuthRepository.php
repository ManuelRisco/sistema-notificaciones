<?php

declare(strict_types=1);

namespace Manue\SistemaNotificaciones;

use RuntimeException;

/**
 * Repositorio de autenticación y permisos de almacén sobre las tablas del ERP.
 */
class AuthRepository
{
    private mixed $connection;
    private string $database;

    public function __construct(mixed $connection, ?string $database = null)
    {
        $this->connection = $connection;

        if ($database !== null && trim($database) !== '') {
            $this->database = $this->validarDatabase($database);
            return;
        }

        if (!function_exists('getDatabaseName')) {
            $archivoBootstrap = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'bootstrap.php';
            if (!is_file($archivoBootstrap)) {
                throw new RuntimeException(
                    'No se encontró config/bootstrap.php para cargar la configuración .env.'
                );
            }
            require_once $archivoBootstrap;
        }

        $this->database = $this->validarDatabase(getDatabaseName());
    }

    private function validarDatabase(string $db): string
    {
        if (function_exists('validarNombreBaseDatos')) {
            return validarNombreBaseDatos($db);
        }

        $cleaned = trim($db);
        if (!preg_match('/\A[A-Za-z0-9_]+\z/', $cleaned)) {
            throw new RuntimeException("El nombre de la base de datos '{$cleaned}' contiene caracteres no permitidos.");
        }

        return $cleaned;
    }

    /**
     * Codifica una contraseña utilizando el algoritmo de ERPTECNOFILM (CP1252).
     */
    public static function encodePassword(string $text): string
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
    public static function verifyPassword(string $plainPassword, string $storedPassword): bool
    {
        $dbClaveRaw = rtrim($storedPassword);
        $inputClaveRaw = self::encodePassword($plainPassword);

        $inputClaveUtf8 = mb_convert_encoding(
            $inputClaveRaw,
            'UTF-8',
            'Windows-1252'
        );

        return hash_equals($dbClaveRaw, $inputClaveRaw)
            || hash_equals($dbClaveRaw, $inputClaveUtf8);
    }

    /**
     * Busca un usuario en adm_usuario por su código.
     */
    public function buscarUsuarioPorCodigo(string $usrCodigo, bool $incluirClave = false): ?array
    {
        $codigo = strtoupper(trim($usrCodigo));
        if ($codigo === '') {
            return null;
        }

        $colClave = $incluirClave ? 'u.usr_clave,' : '';

        $sql = "
            SELECT TOP 1
                RTRIM(u.emp_codigo) AS emp_codigo,
                RTRIM(u.usr_codigo) AS usr_codigo,
                RTRIM(u.pus_codigo) AS pus_codigo,
                RTRIM(u.usr_nombre) AS usr_nombre,
                {$colClave}
                u.usr_status
            FROM [{$this->database}].dbo.adm_usuario u
            WHERE UPPER(RTRIM(u.usr_codigo)) = ?;
        ";

        $stmt = sqlsrv_query($this->connection, $sql, [$codigo]);
        if ($stmt === false) {
            return null;
        }

        try {
            $fila = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        } finally {
            sqlsrv_free_stmt($stmt);
        }

        return is_array($fila) ? $fila : null;
    }

    /**
     * Obtiene los códigos de almacén autorizados para un usuario y empresa en log_almusr,
     * filtrados por los almacenes soportados en el sistema.
     */
    public function obtenerAlmacenesAutorizadosUsuario(string $usrCodigo, string $empCodigo): array
    {
        $codigo = strtoupper(trim($usrCodigo));
        $empresa = trim($empCodigo);

        if ($codigo === '' || $empresa === '') {
            return [];
        }

        $sql = "
            SELECT DISTINCT RTRIM(a.alm_codigo) AS alm_codigo
            FROM [{$this->database}].dbo.log_almusr a
            WHERE UPPER(RTRIM(a.usr_codigo)) = ?
              AND RTRIM(a.emp_codigo) = ?;
        ";

        $stmt = sqlsrv_query($this->connection, $sql, [$codigo, $empresa]);
        if ($stmt === false) {
            return [];
        }

        $almacenes = [];
        $soportados = function_exists('getAlmacenesSoportados') ? getAlmacenesSoportados() : [];

        try {
            while ($fila = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $alm = trim((string) ($fila['alm_codigo'] ?? ''));
                if ($alm !== '' && isset($soportados[$alm])) {
                    $almacenes[] = $alm;
                }
            }
        } finally {
            sqlsrv_free_stmt($stmt);
        }

        return array_values(array_unique($almacenes));
    }
}


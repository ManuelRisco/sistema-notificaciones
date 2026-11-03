<?php

require_once dirname(__DIR__) . '/config/database.php';

try {

    $conexion = getSqlServerConn();

    $sql = "
        SELECT
            DB_NAME() AS base_datos,
            SUSER_SNAME() AS usuario,
            SYSDATETIME() AS fecha
    ";

    $stmt = sqlsrv_query($conexion, $sql);

    if ($stmt === false) {
        throw new RuntimeException(
            print_r(sqlsrv_errors(SQLSRV_ERR_ALL), true)
        );
    }

    $resultado = sqlsrv_fetch_array(
        $stmt,
        SQLSRV_FETCH_ASSOC
    );

    echo "CONEXION EXITOSA\n";
    echo "-------------------------\n";
    echo "Base de datos: {$resultado['base_datos']}\n";
    echo "Usuario: {$resultado['usuario']}\n";

    sqlsrv_free_stmt($stmt);

} catch (Throwable $e) {

    fwrite(
        STDERR,
        "ERROR: " . $e->getMessage() . PHP_EOL
    );

    exit(1);
}

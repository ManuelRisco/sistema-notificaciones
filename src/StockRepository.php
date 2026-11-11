<?php

declare(strict_types=1);

namespace Manue\SistemaNotificaciones;

use RuntimeException;

class StockRepository
{
    private mixed $connection;
    private string $database;

    /**
     * Recibe la conexión existente de SQL Server y opcionalmente el nombre de la BD.
     */
    public function __construct(mixed $connection, ?string $database = null)
    {
        $this->connection = $connection;
        if ($database !== null && trim($database) !== '') {
            $cleaned = trim($database);
            if (!preg_match('/\A[A-Za-z0-9_]+\z/', $cleaned)) {
                throw new RuntimeException("El nombre de la base de datos '{$cleaned}' contiene caracteres no permitidos.");
            }
            $this->database = $cleaned;
        } elseif (function_exists('getDatabaseName')) {
            $this->database = getDatabaseName();
        } else {
            $rawDb = $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? getenv('DB_DATABASE');
            if (!is_string($rawDb) || trim($rawDb) === '') {
                throw new RuntimeException('Falta configurar la variable DB_DATABASE en el archivo .env.');
            }
            $cleaned = trim($rawDb);
            if (!preg_match('/\A[A-Za-z0-9_]+\z/', $cleaned)) {
                throw new RuntimeException("El nombre de la base de datos '{$cleaned}' contiene caracteres no permitidos.");
            }
            $this->database = $cleaned;
        }
    }

    /**
     * Obtiene el stock físico total de los artículos configurados y DISPONIBLES (disponible = 1).
     * Suma todos los lotes de cada artículo y almacén.
     * Puede filtrar por un almacén específico o retornar todos los configurados si $almCodigo es null.
     */
    public function obtenerStockConfigurado(?string $almCodigo = null): array
    {
        $condicionAlmCte = '';
        $condicionAlmMain = '';
        $params = [];

        if ($almCodigo !== null && trim($almCodigo) !== '') {
            $almLimpio = trim($almCodigo);
            $condicionAlmCte = 'AND cfg.alm_codigo = ?';
            $condicionAlmMain = 'AND cfg.alm_codigo = ?';
            $params = [$almLimpio, $almLimpio];
        }

        $sql = "
            ;WITH StockCalculado AS
            (
                SELECT
                    cfg.emp_codigo,
                    a.art_codigo,
                    b.alm_codigo,

                    SUM(
                        CASE
                            WHEN b.mov_tipo = 'I'
                                THEN a.mov_ctdmov
                            ELSE 0
                        END
                    ) -
                    SUM(
                        CASE
                            WHEN b.mov_tipo = 'S'
                                THEN a.mov_ctdmov
                            ELSE 0
                        END
                    ) AS stock_actual

                FROM [{$this->database}].dbo.cfg_alerta_stock cfg

                INNER JOIN [{$this->database}].dbo.log_detmov a
                    ON a.emp_codigo = cfg.emp_codigo
                    AND a.art_codigo = cfg.art_codigo

                INNER JOIN [{$this->database}].dbo.log_cabmov b
                    ON a.mov_id = b.mov_id
                    AND b.alm_codigo = cfg.alm_codigo

                WHERE b.mov_flag <> 'A'
                  AND b.mov_fecemi <= GETDATE()
                  AND cfg.disponible = 1
                  {$condicionAlmCte}

                GROUP BY
                    cfg.emp_codigo,
                    a.art_codigo,
                    b.alm_codigo
            )

            SELECT
                cfg.emp_codigo,
                cfg.art_codigo,
                RTRIM(c.art_nombre) AS descripcion,
                cfg.alm_codigo,
                RTRIM(d.ume_abrv) AS unidad_medida,

                RTRIM(l.lin_nombre) AS categoria,
                RTRIM(f.fam_nombre) AS fam_nombre,
                RTRIM(tf.tfa_nombre) AS tfa_nombre,

                ISNULL(s.stock_actual, 0) AS stock_actual,

                cfg.stock_minimo,
                cfg.band_stock,
                cfg.band_correo,
                cfg.disponible

            FROM [{$this->database}].dbo.cfg_alerta_stock cfg

            INNER JOIN [{$this->database}].dbo.mae_articulo c
                ON c.emp_codigo = cfg.emp_codigo
                AND c.art_codigo = cfg.art_codigo

            LEFT JOIN [{$this->database}].dbo.mae_cabumedida d
                ON c.ume_codigo = d.ume_codigo

            LEFT JOIN [{$this->database}].dbo.mae_linea l
                ON l.lin_codigo = c.lin_codigo

            LEFT JOIN [{$this->database}].dbo.mae_familia f
                ON f.emp_codigo = c.emp_codigo
                AND f.tar_codigo = c.tar_codigo
                AND f.fam_codigo = c.fam_codigo

            LEFT JOIN [{$this->database}].dbo.mae_tpofamilia tf
                ON tf.emp_codigo = c.emp_codigo
                AND tf.tar_codigo = c.tar_codigo
                AND tf.fam_codigo = c.fam_codigo
                AND tf.tfa_codigo = c.tfa_codigo

            LEFT JOIN StockCalculado s
                ON s.emp_codigo = cfg.emp_codigo
                AND s.art_codigo = cfg.art_codigo
                AND s.alm_codigo = cfg.alm_codigo

            WHERE cfg.disponible = 1
              {$condicionAlmMain}

            ORDER BY cfg.alm_codigo, cfg.art_codigo;
        ";

        $stmt = sqlsrv_query($this->connection, $sql, $params);

        if ($stmt === false) {
            throw $this->crearErrorSql(
                'No se pudo obtener el stock configurado'
            );
        }

        $resultados = [];

        try {

            while (true) {

                $fila = sqlsrv_fetch_array(
                    $stmt,
                    SQLSRV_FETCH_ASSOC
                );

                if ($fila === null) {
                    break;
                }

                if ($fila === false) {
                    throw $this->crearErrorSql(
                        'Error al recuperar los resultados'
                    );
                }

                $resultados[] = $fila;
            }

        } finally {
            sqlsrv_free_stmt($stmt);
        }

        return $resultados;
    }

    /**
     * Actualiza las banderas de un artículo.
     */
    public function actualizarBanderas(
        string $empresa,
        string $articulo,
        string $almacen,
        int $bandStock,
        int $bandCorreo
    ): void {

        if (!in_array($bandStock, [0, 1], true)
            || !in_array($bandCorreo, [0, 1], true)) {
            throw new RuntimeException(
                'Las banderas solamente aceptan 0 o 1.'
            );
        }

        $sql = "
            UPDATE [{$this->database}].dbo.cfg_alerta_stock
            SET
                band_stock = ?,
                band_correo = ?,
                fecha_actualizacion = SYSDATETIME()
            WHERE
                emp_codigo = ?
                AND art_codigo = ?
                AND alm_codigo = ?
                AND (
                    band_stock <> ?
                    OR band_correo <> ?
                );
        ";

        $params = [
            $bandStock,
            $bandCorreo,
            $empresa,
            $articulo,
            $almacen,
            $bandStock,
            $bandCorreo
        ];

        $stmt = sqlsrv_query(
            $this->connection,
            $sql,
            $params
        );

        if ($stmt === false) {
            throw $this->crearErrorSql(
                'No se pudieron actualizar las banderas'
            );
        }

        sqlsrv_free_stmt($stmt);
    }

    /**
     * Marca una alerta como notificada.
     */
    public function marcarCorreoEnviado(
        string $empresa,
        string $articulo,
        string $almacen
    ): void {

        $sql = "
            UPDATE [{$this->database}].dbo.cfg_alerta_stock
            SET
                band_correo = 1,
                fecha_actualizacion = SYSDATETIME()
            WHERE
                emp_codigo = ?
                AND art_codigo = ?
                AND alm_codigo = ?
                AND band_stock = 1
                AND band_correo = 0;
        ";

        $params = [
            $empresa,
            $articulo,
            $almacen
        ];

        $stmt = sqlsrv_query(
            $this->connection,
            $sql,
            $params
        );

        if ($stmt === false) {
            throw $this->crearErrorSql(
                'No se pudo marcar el correo como enviado'
            );
        }

        sqlsrv_free_stmt($stmt);
    }

    /**
     * Construye una excepción con los errores de SQL Server.
     */
    private function crearErrorSql(string $mensaje): RuntimeException
    {
        $errores = sqlsrv_errors(SQLSRV_ERR_ALL) ?: [];

        $detalles = [];

        foreach ($errores as $error) {
            $detalles[] = sprintf(
                '[%s] %s',
                $error['code'] ?? 'N/A',
                $error['message'] ?? 'Error desconocido'
            );
        }

        return new RuntimeException(
            $mensaje . ': ' . implode(' | ', $detalles)
        );
    }
}

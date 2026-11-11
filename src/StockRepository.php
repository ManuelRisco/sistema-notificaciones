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

        $stock = $this->expresionStock();

        $sql = "
            ;WITH StockCalculado AS
            (
                SELECT
                    cfg.emp_codigo,
                    a.art_codigo,
                    b.alm_codigo,

                    {$stock} AS stock_actual

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

            {$this->joinsMaestro('c')}

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

    // ------------------------------------------------------------------
    // Consultas usadas por la interfaz web (index, crear, editar, etc.)
    // ------------------------------------------------------------------

    /**
     * Columnas del maestro de artículos (alias: m) con sus catálogos relacionados.
     * Requiere incluir joinsMaestro().
     */
    private function columnasMaestro(): string
    {
        return "
            RTRIM(m.art_nombre) AS art_nombre,
            RTRIM(d.ume_abrv) AS unidad_medida,
            RTRIM(l.lin_nombre) AS categoria,
            RTRIM(f.fam_nombre) AS fam_nombre,
            RTRIM(tf.tfa_nombre) AS tfa_nombre
        ";
    }

    /**
     * JOINs del maestro de artículos hacia unidad de medida, línea, familia y tipo de familia.
     *
     * @param string $alias Alias de la tabla mae_articulo en la consulta.
     */
    private function joinsMaestro(string $alias = 'm'): string
    {
        $db = $this->database;

        return "
            LEFT JOIN [{$db}].dbo.mae_cabumedida d
                ON d.ume_codigo = {$alias}.ume_codigo

            LEFT JOIN [{$db}].dbo.mae_linea l
                ON l.lin_codigo = {$alias}.lin_codigo

            LEFT JOIN [{$db}].dbo.mae_familia f
                ON f.emp_codigo = {$alias}.emp_codigo
                AND f.tar_codigo = {$alias}.tar_codigo
                AND f.fam_codigo = {$alias}.fam_codigo

            LEFT JOIN [{$db}].dbo.mae_tpofamilia tf
                ON tf.emp_codigo = {$alias}.emp_codigo
                AND tf.tar_codigo = {$alias}.tar_codigo
                AND tf.fam_codigo = {$alias}.fam_codigo
                AND tf.tfa_codigo = {$alias}.tfa_codigo
        ";
    }

    /**
     * Expresión que calcula el stock físico (ingresos - salidas) de los movimientos.
     * Requiere los alias a = log_detmov y b = log_cabmov.
     */
    private function expresionStock(): string
    {
        return "
            SUM(CASE WHEN b.mov_tipo = 'I' THEN a.mov_ctdmov ELSE 0 END)
            - SUM(CASE WHEN b.mov_tipo = 'S' THEN a.mov_ctdmov ELSE 0 END)
        ";
    }

    /**
     * CTE StockCalculado: stock físico de UN artículo en UN almacén.
     * Parámetros esperados (en orden): almacén, empresa, artículo.
     */
    private function cteStockArticulo(): string
    {
        $db = $this->database;
        $stock = $this->expresionStock();

        return "
            ;WITH StockCalculado AS
            (
                SELECT
                    a.emp_codigo,
                    a.art_codigo,
                    b.alm_codigo,
                    {$stock} AS stock_actual
                FROM [{$db}].dbo.log_detmov a
                INNER JOIN [{$db}].dbo.log_cabmov b
                    ON a.mov_id = b.mov_id
                    AND b.alm_codigo = ?
                WHERE a.emp_codigo = ?
                  AND a.art_codigo = ?
                  AND b.mov_flag <> 'A'
                  AND b.mov_fecemi <= GETDATE()
                GROUP BY
                    a.emp_codigo,
                    a.art_codigo,
                    b.alm_codigo
            )
        ";
    }

    /**
     * Ejecuta una consulta y devuelve todas las filas (arreglo asociativo).
     *
     * @throws RuntimeException Si la consulta falla.
     */
    private function consultar(string $sql, array $params, string $mensajeError): array
    {
        $stmt = sqlsrv_query($this->connection, $sql, $params);

        if ($stmt === false) {
            throw new RuntimeException(
                $mensajeError . ': ' . print_r(sqlsrv_errors(SQLSRV_ERR_ALL), true)
            );
        }

        $filas = [];

        while ($fila = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $filas[] = $fila;
        }

        sqlsrv_free_stmt($stmt);

        return $filas;
    }

    /**
     * Ejecuta una sentencia de escritura y devuelve las filas afectadas.
     *
     * @throws RuntimeException Si la sentencia falla (con el código de error de SQL Server).
     */
    private function ejecutar(string $sql, array $params, string $mensajeError): int
    {
        $stmt = sqlsrv_query($this->connection, $sql, $params);

        if ($stmt === false) {
            $errores = sqlsrv_errors(SQLSRV_ERR_ERRORS) ?: [];
            $codigo = 0;

            foreach ($errores as $errorSql) {
                $actual = (int) ($errorSql['code'] ?? 0);

                // Priorizar el error de clave duplicada.
                if ($actual === 2601 || $actual === 2627) {
                    $codigo = $actual;
                    break;
                }

                if ($codigo === 0) {
                    $codigo = $actual;
                }
            }

            throw new RuntimeException($mensajeError, $codigo);
        }

        $filas = (int) sqlsrv_rows_affected($stmt);

        sqlsrv_free_stmt($stmt);

        return $filas;
    }

    /**
     * Totales de la configuración de un almacén (para las tarjetas del index).
     * Los artículos no encontrados en el maestro se excluyen de las métricas de stock y correo.
     */
    public function obtenerTotalesConfiguracion(string $almCodigo): array
    {
        $totales = [
            'total' => 0,
            'disponibles' => 0,
            'no_disponibles' => 0,
            'con_stock' => 0,
            'sin_stock' => 0,
            'correo_enviado' => 0,
            'correo_no_enviado' => 0,
            'no_encontrados' => 0
        ];

        $db = $this->database;

        $sql = "
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN cfg.disponible = 1 THEN 1 ELSE 0 END) AS disponibles,
                SUM(CASE WHEN cfg.disponible = 0 THEN 1 ELSE 0 END) AS no_disponibles,
                SUM(CASE WHEN m.art_codigo IS NOT NULL AND cfg.band_stock = 0 THEN 1 ELSE 0 END) AS con_stock,
                SUM(CASE WHEN m.art_codigo IS NOT NULL AND cfg.band_stock = 1 THEN 1 ELSE 0 END) AS sin_stock,
                SUM(CASE WHEN m.art_codigo IS NOT NULL AND cfg.band_correo = 1 THEN 1 ELSE 0 END) AS correo_enviado,
                SUM(CASE WHEN m.art_codigo IS NOT NULL AND cfg.band_correo = 0 THEN 1 ELSE 0 END) AS correo_no_enviado,
                SUM(CASE WHEN m.art_codigo IS NULL THEN 1 ELSE 0 END) AS no_encontrados
            FROM [{$db}].dbo.cfg_alerta_stock cfg
            LEFT JOIN [{$db}].dbo.mae_articulo m
                ON m.emp_codigo = cfg.emp_codigo
                AND m.art_codigo = cfg.art_codigo
            WHERE cfg.alm_codigo = ?;
        ";

        $stmt = sqlsrv_query($this->connection, $sql, [$almCodigo]);

        if ($stmt === false) {
            return $totales;
        }

        $fila = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if ($fila) {
            foreach (array_keys($totales) as $clave) {
                $totales[$clave] = (int) ($fila[$clave] ?? 0);
            }
        }

        return $totales;
    }

    /**
     * Lista la configuración de un almacén con su stock físico, aplicando filtros y orden.
     *
     * @param array $filtros Claves opcionales (cadena vacía = sin filtro):
     *                       disponible, catalogo ('no_encontrado'), stock, correo,
     *                       categoria, familia, tfa.
     * @param string $orden  stock_desc | stock_asc | minimo_desc | minimo_asc | '' (por defecto)
     */
    public function listarConfiguracion(string $almCodigo, array $filtros = [], string $orden = ''): array
    {
        $db = $this->database;
        $stock = $this->expresionStock();

        $condiciones = ['cfg.alm_codigo = ?'];
        $params = [$almCodigo];

        $disponible = (string) ($filtros['disponible'] ?? '');
        $catalogo = (string) ($filtros['catalogo'] ?? '');
        $bandStock = (string) ($filtros['stock'] ?? '');
        $bandCorreo = (string) ($filtros['correo'] ?? '');
        $categoria = (string) ($filtros['categoria'] ?? '');
        $familia = (string) ($filtros['familia'] ?? '');
        $tfa = (string) ($filtros['tfa'] ?? '');

        if ($disponible !== '') {
            $condiciones[] = 'cfg.disponible = ?';
            $params[] = (int) $disponible;
        }

        if ($catalogo === 'no_encontrado') {
            $condiciones[] = 'm.art_codigo IS NULL';
        }

        if ($bandStock !== '') {
            $condiciones[] = 'cfg.band_stock = ? AND m.art_codigo IS NOT NULL';
            $params[] = (int) $bandStock;
        }

        if ($bandCorreo !== '') {
            $condiciones[] = 'cfg.band_correo = ? AND m.art_codigo IS NOT NULL';
            $params[] = (int) $bandCorreo;
        }

        if ($categoria !== '') {
            $condiciones[] = 'RTRIM(l.lin_nombre) = ?';
            $params[] = $categoria;
        }

        if ($familia !== '') {
            $condiciones[] = 'RTRIM(f.fam_nombre) = ?';
            $params[] = $familia;
        }

        if ($tfa !== '') {
            $condiciones[] = 'RTRIM(tf.tfa_nombre) = ?';
            $params[] = $tfa;
        }

        $where = 'WHERE ' . implode(' AND ', $condiciones);

        $orderBy = match ($orden) {
            'stock_desc'  => 'ORDER BY ISNULL(s.stock_actual, 0) DESC, cfg.disponible DESC, cfg.art_codigo ASC',
            'stock_asc'   => 'ORDER BY ISNULL(s.stock_actual, 0) ASC, cfg.disponible DESC, cfg.art_codigo ASC',
            'minimo_desc' => 'ORDER BY ISNULL(cfg.stock_minimo, 0) DESC, cfg.disponible DESC, cfg.art_codigo ASC',
            'minimo_asc'  => 'ORDER BY ISNULL(cfg.stock_minimo, 0) ASC, cfg.disponible DESC, cfg.art_codigo ASC',
            default       => 'ORDER BY cfg.disponible DESC, cfg.emp_codigo, cfg.art_codigo, cfg.alm_codigo',
        };

        $sql = "
            ;WITH StockCalculado AS
            (
                SELECT
                    cfg.emp_codigo,
                    a.art_codigo,
                    b.alm_codigo,
                    {$stock} AS stock_actual
                FROM [{$db}].dbo.cfg_alerta_stock cfg
                INNER JOIN [{$db}].dbo.log_detmov a
                    ON a.emp_codigo = cfg.emp_codigo
                    AND a.art_codigo = cfg.art_codigo
                INNER JOIN [{$db}].dbo.log_cabmov b
                    ON a.mov_id = b.mov_id
                    AND b.alm_codigo = cfg.alm_codigo
                WHERE b.mov_flag <> 'A'
                  AND b.mov_fecemi <= GETDATE()
                  AND cfg.alm_codigo = ?
                GROUP BY
                    cfg.emp_codigo,
                    a.art_codigo,
                    b.alm_codigo
            )

            SELECT
                cfg.emp_codigo,
                cfg.art_codigo,
                cfg.alm_codigo,
                cfg.stock_minimo,
                cfg.band_stock,
                cfg.band_correo,
                cfg.disponible,

                m.art_codigo AS mae_art_codigo,
                {$this->columnasMaestro()},

                s.stock_actual AS stock_actual,
                cfg.fecha_actualizacion

            FROM [{$db}].dbo.cfg_alerta_stock cfg

            LEFT JOIN StockCalculado s
                ON s.emp_codigo = cfg.emp_codigo
                AND s.art_codigo = cfg.art_codigo
                AND s.alm_codigo = cfg.alm_codigo

            LEFT JOIN [{$db}].dbo.mae_articulo m
                ON m.emp_codigo = cfg.emp_codigo
                AND m.art_codigo = cfg.art_codigo

            {$this->joinsMaestro('m')}

            {$where}

            {$orderBy};
        ";

        return $this->consultar(
            $sql,
            array_merge([$almCodigo], $params),
            'No se pudo consultar la configuración de stock'
        );
    }

    /**
     * Opciones distintas de clasificación (categoría, familia, tipo de familia)
     * de los artículos configurados en un almacén, ordenadas alfabéticamente.
     *
     * @return array{categorias: string[], familias: string[], tipos_familia: string[]}
     */
    public function obtenerOpcionesClasificacion(string $almCodigo): array
    {
        $db = $this->database;

        $sql = "
            SELECT DISTINCT
                RTRIM(l.lin_nombre) AS categoria,
                RTRIM(f.fam_nombre) AS fam_nombre,
                RTRIM(tf.tfa_nombre) AS tfa_nombre
            FROM [{$db}].dbo.cfg_alerta_stock cfg
            INNER JOIN [{$db}].dbo.mae_articulo m
                ON m.emp_codigo = cfg.emp_codigo
                AND m.art_codigo = cfg.art_codigo
            {$this->joinsMaestro('m')}
            WHERE cfg.alm_codigo = ?;
        ";

        $categorias = [];
        $familias = [];
        $tipos = [];

        $stmt = sqlsrv_query($this->connection, $sql, [$almCodigo]);

        if ($stmt !== false) {
            while ($fila = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $cat = trim((string) ($fila['categoria'] ?? ''));
                $fam = trim((string) ($fila['fam_nombre'] ?? ''));
                $tfa = trim((string) ($fila['tfa_nombre'] ?? ''));

                if ($cat !== '') {
                    $categorias[$cat] = true;
                }
                if ($fam !== '') {
                    $familias[$fam] = true;
                }
                if ($tfa !== '') {
                    $tipos[$tfa] = true;
                }
            }
            sqlsrv_free_stmt($stmt);
        }

        $categorias = array_keys($categorias);
        $familias = array_keys($familias);
        $tipos = array_keys($tipos);

        sort($categorias, SORT_NATURAL | SORT_FLAG_CASE);
        sort($familias, SORT_NATURAL | SORT_FLAG_CASE);
        sort($tipos, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'categorias' => $categorias,
            'familias' => $familias,
            'tipos_familia' => $tipos
        ];
    }

    /**
     * Registro de configuración (cfg_alerta_stock) con los datos del maestro y su stock físico.
     * Devuelve null si el registro no existe.
     */
    public function obtenerConfiguracion(string $empresa, string $articulo, string $almacen): ?array
    {
        $db = $this->database;

        $sql = "
            {$this->cteStockArticulo()}
            SELECT
                cfg.emp_codigo,
                cfg.art_codigo,
                cfg.alm_codigo,
                cfg.stock_minimo,
                cfg.disponible,
                {$this->columnasMaestro()},
                s.stock_actual
            FROM [{$db}].dbo.cfg_alerta_stock cfg
            LEFT JOIN [{$db}].dbo.mae_articulo m
                ON m.emp_codigo = cfg.emp_codigo
                AND m.art_codigo = cfg.art_codigo
            {$this->joinsMaestro('m')}
            LEFT JOIN StockCalculado s
                ON s.emp_codigo = cfg.emp_codigo
                AND s.art_codigo = cfg.art_codigo
                AND s.alm_codigo = cfg.alm_codigo
            WHERE cfg.emp_codigo = ?
              AND cfg.art_codigo = ?
              AND cfg.alm_codigo = ?;
        ";

        $filas = $this->consultar(
            $sql,
            [$almacen, $empresa, $articulo, $empresa, $articulo, $almacen],
            'No se pudo consultar el registro'
        );

        return $filas[0] ?? null;
    }

    /**
     * Indica si el artículo existe en el maestro (mae_articulo).
     */
    public function existeArticulo(string $empresa, string $articulo): bool
    {
        $sql = "
            SELECT 1
            FROM [{$this->database}].dbo.mae_articulo
            WHERE emp_codigo = ? AND art_codigo = ?;
        ";

        $stmt = sqlsrv_query($this->connection, $sql, [$empresa, $articulo]);

        if ($stmt === false) {
            return false;
        }

        $existe = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC) !== null;
        sqlsrv_free_stmt($stmt);

        return $existe;
    }

    /**
     * Nombre del artículo en el maestro. Devuelve '' si no existe.
     */
    public function obtenerNombreArticulo(string $empresa, string $articulo): string
    {
        $sql = "
            SELECT RTRIM(art_nombre) AS art_nombre
            FROM [{$this->database}].dbo.mae_articulo
            WHERE emp_codigo = ? AND art_codigo = ?;
        ";

        $stmt = sqlsrv_query($this->connection, $sql, [$empresa, $articulo]);

        if ($stmt === false) {
            return '';
        }

        $fila = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return $fila ? trim((string) ($fila['art_nombre'] ?? '')) : '';
    }

    /**
     * Datos del maestro de un artículo con su stock físico en un almacén.
     * Devuelve null si el artículo no existe en el maestro.
     */
    public function obtenerArticuloConStock(string $empresa, string $almacen, string $articulo): ?array
    {
        $db = $this->database;

        $sql = "
            {$this->cteStockArticulo()}
            SELECT
                {$this->columnasMaestro()},
                s.stock_actual
            FROM [{$db}].dbo.mae_articulo m
            {$this->joinsMaestro('m')}
            LEFT JOIN StockCalculado s
                ON s.emp_codigo = m.emp_codigo
                AND s.art_codigo = m.art_codigo
            WHERE m.emp_codigo = ? AND m.art_codigo = ?;
        ";

        $stmt = sqlsrv_query(
            $this->connection,
            $sql,
            [$almacen, $empresa, $articulo, $empresa, $articulo]
        );

        if ($stmt === false) {
            return null;
        }

        $fila = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return ($fila && isset($fila['art_nombre'])) ? $fila : null;
    }

    /**
     * Catálogo de artículos con movimientos o configuración en un almacén,
     * con su stock físico y su disponibilidad actual (null si no está configurado).
     *
     * @throws RuntimeException Si la consulta falla.
     */
    public function listarCatalogo(string $empresa, string $almacen): array
    {
        $db = $this->database;
        $stock = $this->expresionStock();

        $sql = "
            ;WITH StockCalculado AS
            (
                SELECT
                    a.emp_codigo,
                    a.art_codigo,
                    b.alm_codigo,
                    {$stock} AS stock_actual
                FROM [{$db}].dbo.log_detmov a
                INNER JOIN [{$db}].dbo.log_cabmov b
                    ON a.mov_id = b.mov_id
                    AND b.alm_codigo = ?
                WHERE a.emp_codigo = ?
                  AND b.mov_flag <> 'A'
                  AND b.mov_fecemi <= GETDATE()
                GROUP BY
                    a.emp_codigo,
                    a.art_codigo,
                    b.alm_codigo
            ),
            ArticulosObjetivo AS
            (
                SELECT emp_codigo, art_codigo, alm_codigo FROM StockCalculado
                UNION
                SELECT emp_codigo, art_codigo, alm_codigo
                FROM [{$db}].dbo.cfg_alerta_stock
                WHERE emp_codigo = ? AND alm_codigo = ?
            )
            SELECT
                RTRIM(m.art_codigo) AS art_codigo,
                {$this->columnasMaestro()},
                s.stock_actual,
                c.disponible
            FROM ArticulosObjetivo obj
            INNER JOIN [{$db}].dbo.mae_articulo m
                ON m.emp_codigo = obj.emp_codigo
                AND m.art_codigo = obj.art_codigo
            {$this->joinsMaestro('m')}
            LEFT JOIN StockCalculado s
                ON s.emp_codigo = obj.emp_codigo
                AND s.art_codigo = obj.art_codigo
                AND s.alm_codigo = obj.alm_codigo
            LEFT JOIN [{$db}].dbo.cfg_alerta_stock c
                ON c.emp_codigo = obj.emp_codigo
                AND c.alm_codigo = obj.alm_codigo
                AND c.art_codigo = obj.art_codigo
            ORDER BY m.art_codigo ASC;
        ";

        return $this->consultar(
            $sql,
            [$almacen, $empresa, $empresa, $almacen],
            'Error al consultar el catálogo de artículos'
        );
    }

    /**
     * Disponibilidad (1/0) de la configuración de un artículo en un almacén.
     * Devuelve null si el artículo no está configurado.
     *
     * @throws RuntimeException Si la consulta falla.
     */
    public function obtenerDisponibleConfiguracion(string $empresa, string $almacen, string $articulo): ?int
    {
        $sql = "
            SELECT disponible
            FROM [{$this->database}].dbo.cfg_alerta_stock
            WHERE emp_codigo = ?
              AND art_codigo = ?
              AND alm_codigo = ?;
        ";

        $stmt = sqlsrv_query($this->connection, $sql, [$empresa, $articulo, $almacen]);

        if ($stmt === false) {
            throw new RuntimeException('No se pudo verificar el registro existente.');
        }

        $fila = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if ($fila === null || $fila === false) {
            return null;
        }

        return (int) ($fila['disponible'] ?? 0);
    }

    /**
     * Inserta una nueva configuración de stock mínimo.
     */
    public function insertarConfiguracion(
        string $empresa,
        string $articulo,
        string $almacen,
        string $stockMinimo,
        int $disponible
    ): void {
        $sql = "
            INSERT INTO [{$this->database}].dbo.cfg_alerta_stock
            (
                emp_codigo,
                art_codigo,
                alm_codigo,
                stock_minimo,
                disponible
            )
            VALUES
            (
                ?,
                ?,
                ?,
                CAST(? AS NUMERIC(14,5)),
                ?
            );
        ";

        $this->ejecutar(
            $sql,
            [$empresa, $articulo, $almacen, $stockMinimo, $disponible],
            'No se pudo registrar la configuración.'
        );
    }

    /**
     * Actualiza stock mínimo y disponibilidad de una configuración existente
     * (por ejemplo, al reactivar un registro con borrado lógico).
     */
    public function actualizarStockYDisponible(
        string $empresa,
        string $articulo,
        string $almacen,
        string $stockMinimo,
        int $disponible
    ): void {
        $sql = "
            UPDATE [{$this->database}].dbo.cfg_alerta_stock
            SET stock_minimo = CAST(? AS NUMERIC(14,5)),
                disponible = ?,
                fecha_actualizacion = SYSDATETIME()
            WHERE emp_codigo = ?
              AND art_codigo = ?
              AND alm_codigo = ?;
        ";

        $this->ejecutar(
            $sql,
            [$stockMinimo, $disponible, $empresa, $articulo, $almacen],
            'No se pudo actualizar la configuración del artículo.'
        );
    }

    /**
     * Actualiza una configuración desde la pantalla de edición.
     * Las banderas se conservan salvo que cambie el código de artículo.
     *
     * @throws RuntimeException Si la clave nueva ya existe o no se actualizó exactamente un registro.
     */
    public function actualizarConfiguracion(
        string $empresa,
        string $articuloOriginal,
        string $almacen,
        string $articuloNuevo,
        string $stockMinimo,
        int $disponible,
        bool $reiniciarBanderas,
        string $almacenNombre = ''
    ): void {
        $sql = "
            UPDATE [{$this->database}].dbo.cfg_alerta_stock
            SET
                art_codigo = ?,
                stock_minimo = CAST(? AS NUMERIC(14,5)),
                disponible = ?,

                band_stock = CASE
                    WHEN ? = 1 THEN 0
                    ELSE band_stock
                END,

                band_correo = CASE
                    WHEN ? = 1 THEN 0
                    ELSE band_correo
                END,

                fecha_actualizacion = SYSDATETIME()

            WHERE emp_codigo = ?
              AND art_codigo = ?
              AND alm_codigo = ?;
        ";

        $reiniciar = $reiniciarBanderas ? 1 : 0;

        try {
            $filas = $this->ejecutar(
                $sql,
                [
                    $articuloNuevo,
                    $stockMinimo,
                    $disponible,
                    $reiniciar,
                    $reiniciar,
                    $empresa,
                    $articuloOriginal,
                    $almacen
                ],
                'No se pudo actualizar la configuración.'
            );
        } catch (RuntimeException $e) {
            // Clave primaria duplicada.
            if ($e->getCode() === 2601 || $e->getCode() === 2627) {
                throw new RuntimeException(
                    "El nuevo código de artículo ya tiene una configuración registrada para la empresa {$empresa} y el almacén {$almacen} ({$almacenNombre})."
                );
            }

            throw $e;
        }

        if ($filas !== 1) {
            throw new RuntimeException('No se pudo completar la actualización.');
        }
    }

    /**
     * Cambia la disponibilidad (1 = activo, 0 = inactivo / borrado lógico).
     * Conserva las banderas de stock y correo. Devuelve las filas afectadas.
     */
    public function actualizarDisponible(
        string $empresa,
        string $articulo,
        string $almacen,
        int $disponible
    ): int {
        if (!in_array($disponible, [0, 1], true)) {
            throw new RuntimeException(
                'El valor de disponible solo puede ser 0 o 1.'
            );
        }

        $sql = "
            UPDATE [{$this->database}].dbo.cfg_alerta_stock
            SET
                disponible = ?,
                fecha_actualizacion = SYSDATETIME()
            WHERE emp_codigo = ?
              AND art_codigo = ?
              AND alm_codigo = ?;
        ";

        return $this->ejecutar(
            $sql,
            [$disponible, $empresa, $articulo, $almacen],
            'No se pudo actualizar el estado del registro.'
        );
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

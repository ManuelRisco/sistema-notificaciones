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

        $cte = $this->cteStockConfigurado("AND cfg.disponible = 1\n                  {$condicionAlmCte}");

        $sql = "
            {$cte}

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

        return $this->consultar(
            $sql,
            $params,
            'No se pudo obtener el stock configurado'
        );
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

        $this->ejecutar(
            $sql,
            [
                $bandStock,
                $bandCorreo,
                $empresa,
                $articulo,
                $almacen,
                $bandStock,
                $bandCorreo
            ],
            'No se pudieron actualizar las banderas',
            true
        );
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

        $this->ejecutar(
            $sql,
            [$empresa, $articulo, $almacen],
            'No se pudo marcar el correo como enviado',
            true
        );
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
     * CTE reutilizable para calcular el stock físico de los artículos configurados.
     */
    private function cteStockConfigurado(string $condicionExtra = ''): string
    {
        $db = $this->database;
        $stock = $this->expresionStock();

        return "
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
                  {$condicionExtra}
                GROUP BY
                    cfg.emp_codigo,
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
            throw $this->crearErrorSql($mensajeError);
        }

        $filas = [];

        try {
            while (true) {
                $fila = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

                if ($fila === null) {
                    break;
                }

                if ($fila === false) {
                    throw $this->crearErrorSql('Error al recuperar los resultados');
                }

                $filas[] = $fila;
            }
        } finally {
            sqlsrv_free_stmt($stmt);
        }

        return $filas;
    }

    /**
     * Ejecuta una consulta y devuelve la primera fila (o null si no hay resultados).
     * Si $mensajeError es null y la consulta falla, devuelve null sin lanzar excepción.
     *
     * @throws RuntimeException Si $mensajeError no es null y la consulta falla.
     */
    private function consultarFila(string $sql, array $params, ?string $mensajeError = null): ?array
    {
        $stmt = sqlsrv_query($this->connection, $sql, $params);

        if ($stmt === false) {
            if ($mensajeError !== null) {
                throw new RuntimeException($mensajeError);
            }

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
     * Ejecuta una sentencia de escritura y devuelve las filas afectadas.
     *
     * @throws RuntimeException Si la sentencia falla (con el código de error de SQL Server).
     */
    private function ejecutar(
        string $sql,
        array $params,
        string $mensajeError,
        bool $detallarSql = false
    ): int {
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

            if ($detallarSql) {
                throw $this->crearErrorSql($mensajeError, $codigo);
            }

            throw new RuntimeException($mensajeError, $codigo);
        }

        try {
            return (int) sqlsrv_rows_affected($stmt);
        } finally {
            sqlsrv_free_stmt($stmt);
        }
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

        $fila = $this->consultarFila($sql, [$almCodigo]);

        if ($fila !== null) {
            foreach (array_keys($totales) as $clave) {
                $totales[$clave] = (int) ($fila[$clave] ?? 0);
            }
        }

        return $totales;
    }

    /**
     * Construye condiciones LIKE parametrizadas para buscar por palabras dentro de varias columnas.
     *
     * @param string   $busqueda            Texto ingresado por el usuario.
     * @param string[] $expresionesColumnas Expresiones SQL a evaluar con OR por cada token.
     * @param array    $params              Arreglo de parámetros por referencia.
     */
    private function construirClausulasBusqueda(string $busqueda, array $expresionesColumnas, array &$params): ?string
    {
        $limpio = trim($busqueda);
        if ($limpio === '' || empty($expresionesColumnas)) {
            return null;
        }

        $tokens = preg_split('/\s+/', $limpio) ?: [];
        $tokens = array_values(array_filter(array_slice($tokens, 0, 5), static fn(string $t): bool => $t !== ''));
        if (empty($tokens)) {
            return null;
        }

        $clausulasToken = [];
        foreach ($tokens as $token) {
            $escapado = strtr($token, [
                '\\' => '\\\\',
                '%'  => '\%',
                '_'  => '\_',
                '['  => '\[',
            ]);
            $patron = '%' . $escapado . '%';

            $subcondiciones = [];
            foreach ($expresionesColumnas as $expr) {
                $subcondiciones[] = "{$expr} LIKE ? ESCAPE '\\'";
                $params[] = $patron;
            }

            $clausulasToken[] = '(' . implode(' OR ', $subcondiciones) . ')';
        }

        return implode(' AND ', $clausulasToken);
    }

    /**
     * Ejecuta una consulta paginada (conteo + OFFSET/FETCH) asegurando página dentro de rango.
     *
     * @return array{items: array<int, array>, total: int, pagina: int, por_pagina: int, total_paginas: int}
     */
    private function ejecutarConsultaPaginada(
        string $sqlConteo,
        string $sqlDatos,
        array $paramsBase,
        int $pagina,
        int $porPagina,
        string $mensajeError
    ): array {
        $porPagina = max(1, min(100, $porPagina));
        $pagina = max(1, $pagina);

        $filaConteo = $this->consultarFila($sqlConteo, $paramsBase, $mensajeError);
        $total = (int) ($filaConteo['total'] ?? 0);
        $totalPaginas = max(1, (int) ceil($total / $porPagina));
        $paginaActual = min($pagina, $totalPaginas);

        if ($total === 0) {
            return [
                'items' => [],
                'total' => 0,
                'pagina' => 1,
                'por_pagina' => $porPagina,
                'total_paginas' => 1,
            ];
        }

        $offset = ($paginaActual - 1) * $porPagina;
        $items = $this->consultar(
            $sqlDatos,
            array_merge($paramsBase, [$offset, $porPagina]),
            $mensajeError
        );

        return [
            'items' => $items,
            'total' => $total,
            'pagina' => $paginaActual,
            'por_pagina' => $porPagina,
            'total_paginas' => $totalPaginas,
        ];
    }

    /**
     * Construye CTE, FROM/WHERE, ORDER BY y parámetros compartidos para listar configuraciones.
     *
     * @return array{cte: string, fromWhere: string, orderBy: string, params: array}
     */
    private function construirConsultaConfiguracion(string $almCodigo, array $filtros = [], string $orden = ''): array
    {
        $db = $this->database;

        $condiciones = ['cfg.alm_codigo = ?'];
        $whereParams = [$almCodigo];

        $disponible = (string) ($filtros['disponible'] ?? '');
        $catalogo = (string) ($filtros['catalogo'] ?? '');
        $bandStock = (string) ($filtros['stock'] ?? '');
        $bandCorreo = (string) ($filtros['correo'] ?? '');
        $categoria = (string) ($filtros['categoria'] ?? '');
        $familia = (string) ($filtros['familia'] ?? '');
        $tfa = (string) ($filtros['tfa'] ?? '');
        $busqueda = (string) ($filtros['busqueda'] ?? '');

        if ($disponible !== '') {
            $condiciones[] = 'cfg.disponible = ?';
            $whereParams[] = (int) $disponible;
        }

        if ($catalogo === 'no_encontrado') {
            $condiciones[] = 'm.art_codigo IS NULL';
        }

        if ($bandStock !== '') {
            $condiciones[] = 'cfg.band_stock = ? AND m.art_codigo IS NOT NULL';
            $whereParams[] = (int) $bandStock;
        }

        if ($bandCorreo !== '') {
            $condiciones[] = 'cfg.band_correo = ? AND m.art_codigo IS NOT NULL';
            $whereParams[] = (int) $bandCorreo;
        }

        if ($categoria !== '') {
            $condiciones[] = 'RTRIM(l.lin_nombre) = ?';
            $whereParams[] = $categoria;
        }

        if ($familia !== '') {
            $condiciones[] = 'RTRIM(f.fam_nombre) = ?';
            $whereParams[] = $familia;
        }

        if ($tfa !== '') {
            $condiciones[] = 'RTRIM(tf.tfa_nombre) = ?';
            $whereParams[] = $tfa;
        }

        $clausulaBusqueda = $this->construirClausulasBusqueda(
            $busqueda,
            ['RTRIM(cfg.art_codigo)', "ISNULL(RTRIM(m.art_nombre), '')"],
            $whereParams
        );
        if ($clausulaBusqueda !== null) {
            $condiciones[] = $clausulaBusqueda;
        }

        $where = 'WHERE ' . implode(' AND ', $condiciones);

        $orderBy = match ($orden) {
            'stock_desc'  => 'ORDER BY ISNULL(s.stock_actual, 0) DESC, cfg.disponible DESC, cfg.art_codigo ASC',
            'stock_asc'   => 'ORDER BY ISNULL(s.stock_actual, 0) ASC, cfg.disponible DESC, cfg.art_codigo ASC',
            'minimo_desc' => 'ORDER BY ISNULL(cfg.stock_minimo, 0) DESC, cfg.disponible DESC, cfg.art_codigo ASC',
            'minimo_asc'  => 'ORDER BY ISNULL(cfg.stock_minimo, 0) ASC, cfg.disponible DESC, cfg.art_codigo ASC',
            default       => 'ORDER BY cfg.disponible DESC, cfg.emp_codigo, cfg.art_codigo, cfg.alm_codigo',
        };

        $cte = $this->cteStockConfigurado('AND cfg.alm_codigo = ?');

        $fromWhere = "
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
        ";

        return [
            'cte' => $cte,
            'fromWhere' => $fromWhere,
            'orderBy' => $orderBy,
            'params' => array_merge([$almCodigo], $whereParams),
        ];
    }

    /**
     * Lista la configuración de un almacén con su stock físico, aplicando filtros y orden.
     *
     * @param array $filtros Claves opcionales (cadena vacía = sin filtro):
     *                       disponible, catalogo ('no_encontrado'), stock, correo,
     *                       categoria, familia, tfa, busqueda.
     * @param string $orden  stock_desc | stock_asc | minimo_desc | minimo_asc | '' (por defecto)
     */
    public function listarConfiguracion(string $almCodigo, array $filtros = [], string $orden = ''): array
    {
        $partes = $this->construirConsultaConfiguracion($almCodigo, $filtros, $orden);

        $sql = "
            {$partes['cte']}

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

            {$partes['fromWhere']}

            {$partes['orderBy']};
        ";

        return $this->consultar(
            $sql,
            $partes['params'],
            'No se pudo consultar la configuración de stock'
        );
    }

    /**
     * Lista la configuración de un almacén de forma paginada aplicando filtros, búsqueda y orden.
     *
     * @return array{items: array<int, array>, total: int, pagina: int, por_pagina: int, total_paginas: int}
     */
    public function listarConfiguracionPaginada(
        string $almCodigo,
        array $filtros = [],
        string $orden = '',
        int $pagina = 1,
        int $porPagina = 10
    ): array {
        $partes = $this->construirConsultaConfiguracion($almCodigo, $filtros, $orden);

        $sqlConteo = "
            {$partes['cte']}
            SELECT COUNT(*) AS total
            {$partes['fromWhere']};
        ";

        $sqlDatos = "
            {$partes['cte']}

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

                s.stock_actual AS stock_actual

            {$partes['fromWhere']}

            {$partes['orderBy']}
            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY;
        ";

        return $this->ejecutarConsultaPaginada(
            $sqlConteo,
            $sqlDatos,
            $partes['params'],
            $pagina,
            $porPagina,
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

        try {
            $filas = $this->consultar($sql, [$almCodigo], 'No se pudieron consultar las clasificaciones');
        } catch (RuntimeException) {
            $filas = [];
        }

        foreach ($filas as $fila) {
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

        return $this->consultarFila(
            $sql,
            [$almacen, $empresa, $articulo, $empresa, $articulo, $almacen],
            'No se pudo consultar el registro'
        );
    }

    /**
     * Indica si el artículo existe en el maestro (mae_articulo).
     */
    public function existeArticulo(string $empresa, string $articulo): bool
    {
        $sql = "
            SELECT 1 AS existe
            FROM [{$this->database}].dbo.mae_articulo
            WHERE emp_codigo = ? AND art_codigo = ?;
        ";

        return $this->consultarFila($sql, [$empresa, $articulo]) !== null;
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

        $fila = $this->consultarFila($sql, [$empresa, $articulo]);

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

        $fila = $this->consultarFila(
            $sql,
            [$almacen, $empresa, $articulo, $empresa, $articulo]
        );

        return ($fila && isset($fila['art_nombre'])) ? $fila : null;
    }

    /**
     * Construye CTE y FROM/JOIN compartidos para consultas del catálogo de artículos por almacén.
     *
     * @return array{cte: string, fromJoins: string}
     */
    private function construirBaseCatalogo(): array
    {
        $db = $this->database;
        $stock = $this->expresionStock();

        $cte = "
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
        ";

        $fromJoins = "
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
        ";

        return [
            'cte' => $cte,
            'fromJoins' => $fromJoins,
        ];
    }

    /**
     * Catálogo de artículos con movimientos o configuración en un almacén,
     * con su stock físico y su disponibilidad actual (null si no está configurado).
     *
     * @throws RuntimeException Si la consulta falla.
     */
    public function listarCatalogo(string $empresa, string $almacen): array
    {
        $base = $this->construirBaseCatalogo();

        $sql = "
            {$base['cte']}
            SELECT
                RTRIM(m.art_codigo) AS art_codigo,
                {$this->columnasMaestro()},
                s.stock_actual,
                c.disponible
            {$base['fromJoins']}
            ORDER BY m.art_codigo ASC;
        ";

        return $this->consultar(
            $sql,
            [$almacen, $empresa, $empresa, $almacen],
            'Error al consultar el catálogo de artículos'
        );
    }

    /**
     * Catálogo paginado de artículos disponibles para configurar en un almacén,
     * con búsqueda por código/nombre/clasificación, filtro opcional solo con stock y ordenamiento.
     *
     * @param array $filtros Claves opcionales: busqueda (string), solo_stock (bool), art_actual (string).
     * @param string $orden  defecto | desc | asc
     * @return array{items: array<int, array>, total: int, pagina: int, por_pagina: int, total_paginas: int}
     * @throws RuntimeException Si la consulta falla.
     */
    public function listarCatalogoPaginado(
        string $empresa,
        string $almacen,
        array $filtros = [],
        string $orden = 'defecto',
        int $pagina = 1,
        int $porPagina = 10
    ): array {
        $base = $this->construirBaseCatalogo();
        $params = [$almacen, $empresa, $empresa, $almacen];
        $condiciones = [];

        $artActual = trim((string) ($filtros['art_actual'] ?? ''));
        $soloStock = !empty($filtros['solo_stock']);
        $busqueda = (string) ($filtros['busqueda'] ?? '');

        if ($artActual !== '') {
            $condiciones[] = '(c.disponible IS NULL OR c.disponible <> 1 OR RTRIM(m.art_codigo) = ?)';
            $params[] = $artActual;
        } else {
            $condiciones[] = '(c.disponible IS NULL OR c.disponible <> 1)';
        }

        if ($soloStock) {
            $condiciones[] = 'ISNULL(s.stock_actual, 0) > 0';
        }

        $clausulaBusqueda = $this->construirClausulasBusqueda(
            $busqueda,
            [
                'RTRIM(m.art_codigo)',
                "ISNULL(RTRIM(m.art_nombre), '')",
                "ISNULL(RTRIM(l.lin_nombre), '')",
                "ISNULL(RTRIM(f.fam_nombre), '')",
                "ISNULL(RTRIM(tf.tfa_nombre), '')",
            ],
            $params
        );
        if ($clausulaBusqueda !== null) {
            $condiciones[] = $clausulaBusqueda;
        }

        $where = 'WHERE ' . implode(' AND ', $condiciones);

        $orderBy = match ($orden) {
            'desc' => 'ORDER BY ISNULL(s.stock_actual, 0) DESC, m.art_codigo ASC',
            'asc'  => 'ORDER BY ISNULL(s.stock_actual, 0) ASC, m.art_codigo ASC',
            default => 'ORDER BY m.art_codigo ASC',
        };

        $sqlConteo = "
            {$base['cte']}
            SELECT COUNT(*) AS total
            {$base['fromJoins']}
            {$where};
        ";

        $sqlDatos = "
            {$base['cte']}
            SELECT
                RTRIM(m.art_codigo) AS art_codigo,
                {$this->columnasMaestro()},
                s.stock_actual,
                c.disponible
            {$base['fromJoins']}
            {$where}
            {$orderBy}
            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY;
        ";

        return $this->ejecutarConsultaPaginada(
            $sqlConteo,
            $sqlDatos,
            $params,
            $pagina,
            $porPagina,
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

        $fila = $this->consultarFila(
            $sql,
            [$empresa, $articulo, $almacen],
            'No se pudo verificar el registro existente.'
        );

        return $fila === null ? null : (int) ($fila['disponible'] ?? 0);
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
    private function crearErrorSql(string $mensaje, int $codigo = 0): RuntimeException
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
            $mensaje . ': ' . implode(' | ', $detalles),
            $codigo
        );
    }
}

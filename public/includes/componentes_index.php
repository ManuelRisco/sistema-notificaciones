<?php

declare(strict_types=1);

/**
 * Renderiza una cabecera de tabla con menú desplegable de filtro u ordenación.
 *
 * @param array<int, array{href: string, seleccionada: bool, texto: string, title?: ?string}> $opciones
 */
function renderThFiltro(
    string $titulo,
    string $menuId,
    bool $activo,
    string $tituloBoton,
    array $opciones
): void {
    ?>
    <th class="th-filtrable">
        <div class="th-contenido">
            <span><?= h($titulo) ?></span>
            <button
                type="button"
                class="btn-filtro-columna <?= $activo ? 'filtro-activo' : '' ?>"
                title="<?= h($tituloBoton) ?>"
                onclick="toggleMenuFiltro(event, '<?= h($menuId) ?>')"
            >
                <svg class="icono-filtro" viewBox="0 0 16 16" width="12" height="12" fill="currentColor">
                    <path d="M1.5 2.5a.5.5 0 0 1 .5-.5h12a.5.5 0 0 1 .38.82L9 8.24V13.5a.5.5 0 0 1-.76.42l-2-1.25A.5.5 0 0 1 6 12.25V8.24L1.62 2.82a.5.5 0 0 1-.12-.32z"/>
                </svg>
            </button>
        </div>
        <div id="<?= h($menuId) ?>" class="menu-filtro">
            <?php foreach ($opciones as $opcion): ?>
                <?php
                $seleccionada = !empty($opcion['seleccionada']);
                $tituloOpcion = $opcion['title'] ?? null;
                ?>
                <a
                    href="<?= h($opcion['href']) ?>"
                    class="opcion-filtro <?= $seleccionada ? 'seleccionada' : '' ?>"
                    <?= $tituloOpcion !== null && $tituloOpcion !== '' ? 'title="' . h($tituloOpcion) . '"' : '' ?>
                >
                    <span class="indicador-check"><?= $seleccionada ? '✓' : '' ?></span> <?= h($opcion['texto']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </th>
    <?php
}

/**
 * Renderiza el formulario POST para desactivar o reactivar un artículo configurado.
 */
function renderFormCambioEstado(array $registro, bool $activar): void
{
    $accion = $activar ? 'reactivar' : 'eliminar';
    $claseBtn = $activar ? 'btn-reactivar' : 'btn-eliminar';
    $textoBtn = $activar ? 'Reactivar' : 'Desactivar';
    ?>
    <form action="cambiar_estado.php" method="POST" class="form-eliminar">
        <input type="hidden" name="emp_codigo" value="<?= h(trim((string) $registro['emp_codigo'])) ?>">
        <input type="hidden" name="art_codigo" value="<?= h(trim((string) $registro['art_codigo'])) ?>">
        <input type="hidden" name="alm_codigo" value="<?= h(trim((string) $registro['alm_codigo'])) ?>">
        <input type="hidden" name="art_nombre" value="<?= h(trim((string) ($registro['art_nombre'] ?? ''))) ?>">
        <input type="hidden" name="categoria" value="<?= h(trim((string) ($registro['categoria'] ?? ''))) ?>">
        <input type="hidden" name="fam_nombre" value="<?= h(trim((string) ($registro['fam_nombre'] ?? ''))) ?>">
        <input type="hidden" name="tfa_nombre" value="<?= h(trim((string) ($registro['tfa_nombre'] ?? ''))) ?>">
        <input type="hidden" name="accion" value="<?= h($accion) ?>">
        <input type="hidden" name="csrf_token" value="<?= h(generarTokenCsrf()) ?>">
        <button type="submit" class="btn <?= h($claseBtn) ?>">
            <?= h($textoBtn) ?>
        </button>
    </form>
    <?php
}

/**
 * Calcula una secuencia compacta de páginas con elipsis ('...') para la paginación del index.
 *
 * @return array<int, int|string>
 */
function calcularPaginasVisiblesIndex(int $paginaActual, int $totalPaginas): array
{
    if ($totalPaginas <= 7) {
        return range(1, max(1, $totalPaginas));
    }

    $paginas = [1];
    $inicio = max(2, $paginaActual - 1);
    $fin = min($totalPaginas - 1, $paginaActual + 1);

    if ($inicio > 2) {
        $paginas[] = '...';
    }
    for ($p = $inicio; $p <= $fin; $p++) {
        $paginas[] = $p;
    }
    if ($fin < $totalPaginas - 1) {
        $paginas[] = '...';
    }

    $paginas[] = $totalPaginas;
    return $paginas;
}

/**
 * Renderiza el texto de estado de filtros/búsqueda en base de datos para index.php.
 */
function renderResumenFiltrosIndex(bool $hayFiltrosActivos, string $busqueda, int $totalRegistros): void
{
    if (!$hayFiltrosActivos) {
        return;
    }

    $etiqueta = $totalRegistros === 1 ? 'registro encontrado' : 'registros encontrados';
    if ($busqueda !== '') {
        ?>
        Resultados para <strong>"<?= h($busqueda) ?>"</strong> — <strong><?= $totalRegistros ?></strong> <?= $etiqueta ?>.
        <a href="index.php" class="busqueda-limpiar-enlace">Quitar todos los filtros</a>
        <?php
        return;
    }
    ?>
    Filtros activos en base de datos — <strong><?= $totalRegistros ?></strong> <?= $etiqueta ?>.
    <a href="index.php" class="busqueda-limpiar-enlace">Quitar todos los filtros</a>
    <?php
}

/**
 * Renderiza las filas <tr> del cuerpo de tabla de configuraciones en index.php.
 */
function renderFilasTablaConfiguracion(array $registros, bool $hayFiltrosActivos): void
{
    if (empty($registros)) {
        ?>
        <tr>
            <td colspan="10" class="tabla-vacia">
                <?= $hayFiltrosActivos
                    ? 'No existen configuraciones que coincidan con la búsqueda o filtros aplicados en la base de datos.'
                    : 'No existen configuraciones registradas.' ?>
            </td>
        </tr>
        <?php
        return;
    }

    foreach ($registros as $registro) {
        $estaDisponible = ((int) ($registro['disponible'] ?? 1) === 1);
        $artCodigo = trim((string) ($registro['art_codigo'] ?? ''));
        $artNombre = trim((string) ($registro['art_nombre'] ?? ''));
        $artEncontrado = !empty($registro['mae_art_codigo']);
        ?>
        <tr class="<?= $estaDisponible ? '' : 'fila-inactiva' ?>"
            data-codigo="<?= h($artCodigo) ?>"
            data-nombre="<?= h($artNombre) ?>">
            <td>
                <strong>
                    <?= $artEncontrado ? h($artNombre) : '<span class="badge badge-alerta" title="El artículo no existe en el catálogo maestro (mae_articulo)">No encontrado</span>' ?>
                </strong>
                <span class="articulo-titulo">
                    <?= h($artCodigo) ?>
                </span>
            </td>
            <td>
                <?php if ($artEncontrado && !empty($registro['categoria'])): ?>
                    <span class="texto-clasificacion"><?= h($registro['categoria']) ?></span>
                <?php else: ?>
                    <span class="badge badge-sin-datos" title="Sin categoría">—</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($artEncontrado && !empty($registro['fam_nombre'])): ?>
                    <span class="texto-clasificacion"><?= h($registro['fam_nombre']) ?></span>
                <?php else: ?>
                    <span class="badge badge-sin-datos" title="Sin familia">—</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($artEncontrado && !empty($registro['tfa_nombre'])): ?>
                    <span class="texto-clasificacion"><?= h($registro['tfa_nombre']) ?></span>
                <?php else: ?>
                    <span class="badge badge-sin-datos" title="Sin tipo de familia">—</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if (!$artEncontrado): ?>
                    <span class="badge badge-sin-datos" title="No existe en el catálogo maestro">—</span>
                <?php elseif ($registro['stock_actual'] === null): ?>
                    <span class="badge badge-sin-datos">
                        NULL
                    </span>
                <?php else: ?>
                    <strong>
                        <?= h(
                            number_format(
                                (float) $registro['stock_actual'],
                                5,
                                '.',
                                ','
                            )
                        ) . (!empty($registro['unidad_medida']) ? ' ' . h($registro['unidad_medida']) : '') ?>
                    </strong>
                <?php endif; ?>
            </td>
            <td>
                <?= h(
                    number_format(
                        (float) $registro['stock_minimo'],
                        5,
                        '.',
                        ','
                    )
                ) . (!empty($registro['unidad_medida']) ? ' ' . h($registro['unidad_medida']) : '') ?>
            </td>
            <td>
                <?php if (!$artEncontrado): ?>
                    <span class="badge badge-sin-datos" title="No existe en el catálogo maestro">—</span>
                <?php elseif ($registro['band_stock']): ?>
                    <span class="badge badge-alerta">Alerta de stock</span>
                <?php else: ?>
                    <span class="badge badge-activo">Con Stock</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if (!$artEncontrado): ?>
                    <span class="badge badge-sin-datos" title="No existe en el catálogo maestro">—</span>
                <?php elseif ($registro['band_correo']): ?>
                    <span class="badge badge-alerta">Correo enviado</span>
                <?php else: ?>
                    <span class="badge badge-inactivo">Correo no enviado</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($estaDisponible): ?>
                    <span class="badge badge-activo">Disponible</span>
                <?php else: ?>
                    <span class="badge badge-inactivo">No disponible</span>
                <?php endif; ?>
            </td>
            <td>
                <div class="acciones-celda">
                    <a
                        href="editar.php?emp=<?= urlencode(trim((string) $registro['emp_codigo'])) ?>&art=<?= urlencode($artCodigo) ?>&alm=<?= urlencode(trim((string) $registro['alm_codigo'])) ?>"
                        class="btn btn-editar"
                    >
                        Editar
                    </a>

                    <?php renderFormCambioEstado($registro, !$estaDisponible); ?>
                </div>
            </td>
        </tr>
        <?php
    }
}

/**
 * Renderiza la barra de paginación para el listado principal en index.php.
 *
 * @param callable(int): string $urlBuilder Función que genera el href para una página dada.
 */
function renderPaginacionIndex(
    int $paginaActual,
    int $totalPaginas,
    int $totalRegistros,
    int $porPagina,
    callable $urlBuilder
): void {
    $inicio = $totalRegistros === 0 ? 0 : (($paginaActual - 1) * $porPagina) + 1;
    $fin = $totalRegistros === 0 ? 0 : min($totalRegistros, $paginaActual * $porPagina);
    $opcionesTamano = [10, 15, 25, 50];
    $secuencia = calcularPaginasVisiblesIndex($paginaActual, $totalPaginas);
    ?>
    <div class="paginacion-contenedor">
        <div class="paginacion-info">
            <span>
                <?php if ($totalRegistros === 0): ?>
                    0 registros
                <?php else: ?>
                    Mostrando <strong><?= $inicio ?>–<?= $fin ?></strong> de <strong><?= $totalRegistros ?></strong> <?= $totalRegistros === 1 ? 'registro' : 'registros' ?>
                <?php endif; ?>
            </span>
            <label class="paginacion-tamano" for="por-pagina-index">
                <span>Por pág.:</span>
                <select id="por-pagina-index" class="paginacion-select" aria-label="Registros por página">
                    <?php foreach ($opcionesTamano as $tam): ?>
                        <option value="<?= $tam ?>" <?= $tam === $porPagina ? 'selected' : '' ?>><?= $tam ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <nav class="paginacion-controles" aria-label="Paginación de registros">
            <?php if ($paginaActual <= 1): ?>
                <span class="paginacion-btn deshabilitado" aria-disabled="true">‹ Anterior</span>
            <?php else: ?>
                <a href="<?= h($urlBuilder($paginaActual - 1)) ?>" class="paginacion-btn" data-pagina="<?= $paginaActual - 1 ?>">‹ Anterior</a>
            <?php endif; ?>

            <?php foreach ($secuencia as $item): ?>
                <?php if ($item === '...'): ?>
                    <span class="paginacion-ellipsis">…</span>
                <?php elseif ($item === $paginaActual): ?>
                    <span class="paginacion-btn activo" aria-current="page"><?= $item ?></span>
                <?php else: ?>
                    <a href="<?= h($urlBuilder((int) $item)) ?>" class="paginacion-btn" data-pagina="<?= (int) $item ?>"><?= (int) $item ?></a>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if ($paginaActual >= $totalPaginas): ?>
                <span class="paginacion-btn deshabilitado" aria-disabled="true">Siguiente ›</span>
            <?php else: ?>
                <a href="<?= h($urlBuilder($paginaActual + 1)) ?>" class="paginacion-btn" data-pagina="<?= $paginaActual + 1 ?>">Siguiente ›</a>
            <?php endif; ?>
        </nav>
    </div>
    <?php
}


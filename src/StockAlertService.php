<?php

declare(strict_types=1);

namespace Manue\SistemaNotificaciones;

class StockAlertService
{
    private StockRepository $repository;

    public function __construct(StockRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Analiza los artículos y actualiza las banderas
     * solamente cuando se solicita.
     *
     * @param bool $aplicarCambios
     * false = solamente simular
     * true  = actualizar SQL Server
     * @param string|null $almCodigo
     * null = procesa todos los almacenes configurados
     * string = procesa únicamente el almacén indicado
     */
    public function procesar(bool $aplicarCambios = false, ?string $almCodigo = null): array
    {
        $articulos = $this->repository->obtenerStockConfigurado($almCodigo);

        $resultados = [];

        foreach ($articulos as $articulo) {

            // Datos actuales del artículo
            $stockActual = (float) $articulo['stock_actual'];
            $stockMinimo = (float) $articulo['stock_minimo'];

            $bandStockAnterior = (int) $articulo['band_stock'];
            $bandCorreoAnterior = (int) $articulo['band_correo'];

            // Nuevos valores
            if ($stockActual <= $stockMinimo) {

                // El artículo está en alerta
                $nuevaBandStock = 1;

                // Si acaba de entrar en alerta,
                // el correo queda pendiente.
                if ($bandStockAnterior === 0) {
                    $nuevaBandCorreo = 0;
                } else {
                    // Si ya estaba en alerta,
                    // conservamos el estado del correo.
                    $nuevaBandCorreo = $bandCorreoAnterior;
                }

                $estado = 'ALERTA';

            } else {

                // El artículo tiene stock suficiente
                $nuevaBandStock = 0;
                $nuevaBandCorreo = 0;

                $estado = 'STOCK SUFICIENTE';
            }

            // Comprobar si realmente cambiaron las banderas
            $hayCambios =
                $bandStockAnterior !== $nuevaBandStock
                || $bandCorreoAnterior !== $nuevaBandCorreo;

            // Actualizar SQL Server solamente si corresponde
            if ($hayCambios && $aplicarCambios) {

                $this->repository->actualizarBanderas(
                    $articulo['emp_codigo'],
                    $articulo['art_codigo'],
                    $articulo['alm_codigo'],
                    $nuevaBandStock,
                    $nuevaBandCorreo
                );
            }

            // Preparar el resultado del procesamiento
            $resultados[] = [
                'emp_codigo' => $articulo['emp_codigo'],
                'unidad_medida' => $articulo['unidad_medida'],
                'art_codigo' => trim($articulo['art_codigo']),
                'descripcion' => $articulo['descripcion'],
                'alm_codigo' => $articulo['alm_codigo'],

                'categoria' => trim((string) ($articulo['categoria'] ?? '')),
                'fam_nombre' => trim((string) ($articulo['fam_nombre'] ?? '')),
                'tfa_nombre' => trim((string) ($articulo['tfa_nombre'] ?? '')),

                'stock_actual' => $stockActual,
                'stock_minimo' => $stockMinimo,

                'band_stock_anterior' => $bandStockAnterior,
                'band_stock_nueva' => $nuevaBandStock,

                'band_correo_anterior' => $bandCorreoAnterior,
                'band_correo_nueva' => $nuevaBandCorreo,

                'estado' => $estado,
                'correo_pendiente' => $nuevaBandStock === 1
                    && $nuevaBandCorreo === 0,

                'actualizado' => $hayCambios && $aplicarCambios
            ];
        }

        return $resultados;
    }
}
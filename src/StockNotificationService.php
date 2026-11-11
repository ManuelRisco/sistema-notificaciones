<?php

declare(strict_types=1);

namespace Manue\SistemaNotificaciones;

use RuntimeException;
use Throwable;

class StockNotificationService
{
    private StockAlertService $stockAlertService;
    private EmailService $emailService;
    private StockRepository $repository;

    public function __construct(
        StockAlertService $stockAlertService,
        EmailService $emailService,
        StockRepository $repository
    ) {
        $this->stockAlertService = $stockAlertService;
        $this->emailService = $emailService;
        $this->repository = $repository;
    }

    /**
     * Procesa el inventario y agrupa las alertas
     * en un solo correo por cada almacén independiente.
     *
     * @param bool $modoSimulacion
     * true  = no modifica SQL ni envía correos.
     * false = actualiza SQL y envía el correo agrupado.
     * @param string|null $almacenFiltro
     * null = procesa todos los almacenes configurados.
     * string = procesa exclusivamente el almacén indicado.
     */
    public function ejecutar(bool $modoSimulacion = false, ?string $almacenFiltro = null): array
    {
        if ($almacenFiltro !== null && trim($almacenFiltro) !== '') {
            $almacenFiltro = trim($almacenFiltro);
            if (function_exists('esAlmacenSoportado') && !esAlmacenSoportado($almacenFiltro)) {
                throw new RuntimeException("El almacén '{$almacenFiltro}' no está soportado en la configuración.");
            }
        } else {
            $almacenFiltro = null;
        }

        // Analizar los artículos y actualizar banderas
        // solamente cuando no estamos simulando.
        $resultados = $this->stockAlertService->procesar(
            !$modoSimulacion,
            $almacenFiltro
        );

        $articulosPendientes = 0;
        $pendientesPorAlmacen = [];

        // Preparar los artículos que necesitan notificación agrupados por almacén.
        foreach ($resultados as $indice => $articulo) {

            $resultados[$indice]['correo_enviado'] = false;
            $resultados[$indice]['error_correo'] = null;
            $resultados[$indice]['error_actualizacion_bandera'] = null;
            $resultados[$indice]['band_correo_actualizada'] = false;

            if ($articulo['correo_pendiente']) {
                $alm = trim((string) ($articulo['alm_codigo'] ?? ''));
                if ($alm !== '') {
                    $pendientesPorAlmacen[$alm][] = [
                        'indice' => $indice,
                        'articulo' => $articulo
                    ];
                }
                $articulosPendientes++;
            }
        }

        $correosEnviados = 0;
        $correosFallidos = 0;
        $articulosNotificados = 0;
        $erroresActualizacionBanderas = 0;

        // En producción, enviar un correo agrupado por cada almacén que tenga artículos pendientes.
        if (!$modoSimulacion && !empty($pendientesPorAlmacen)) {

            foreach ($pendientesPorAlmacen as $almCodigo => $itemsAlmacen) {

                $articulosDelAlmacen = array_column($itemsAlmacen, 'articulo');
                $indicesDelAlmacen = array_column($itemsAlmacen, 'indice');

                try {

                    $enviado = $this->emailService->enviarAlertasPorAlmacen(
                        $almCodigo,
                        $articulosDelAlmacen
                    );

                    if (!$enviado) {
                        throw new RuntimeException(
                            "No se confirmó el envío del correo agrupado para el almacén {$almCodigo}."
                        );
                    }

                    $correosEnviados++;
                    $articulosNotificados += count($articulosDelAlmacen);

                    // Marcar individualmente los artículos de este almacén como notificados
                    foreach ($indicesDelAlmacen as $indice) {

                        $articulo = $resultados[$indice];

                        $resultados[$indice]['correo_enviado'] = true;
                        $resultados[$indice]['correo_pendiente'] = false;

                        try {

                            $this->repository->marcarCorreoEnviado(
                                $articulo['emp_codigo'],
                                $articulo['art_codigo'],
                                $articulo['alm_codigo']
                            );

                            $resultados[$indice]['band_correo_actualizada'] = true;

                        } catch (Throwable $e) {

                            $resultados[$indice]['error_actualizacion_bandera']
                                = $e->getMessage();

                            $erroresActualizacionBanderas++;
                        }
                    }

                } catch (Throwable $e) {

                    $correosFallidos++;

                    foreach ($indicesDelAlmacen as $indice) {
                        $resultados[$indice]['error_correo'] =
                            $e->getMessage();
                    }
                }
            }
        }

        return [
            'modo_simulacion' => $modoSimulacion,
            'total_articulos' => count($resultados),
            'articulos_pendientes' => $articulosPendientes,
            'articulos_notificados' => $articulosNotificados,
            'correos_enviados' => $correosEnviados,
            'correos_fallidos' => $correosFallidos,
            'errores_actualizacion_banderas' =>
                $erroresActualizacionBanderas,
            'resultados' => $resultados
        ];
    }
}

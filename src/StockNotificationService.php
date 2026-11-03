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
     * en un solo correo.
     *
     * @param bool $modoSimulacion
     * true  = no modifica SQL ni envía correos.
     * false = actualiza SQL y envía el correo agrupado.
     */
    public function ejecutar(bool $modoSimulacion = false): array
    {
        // Analizar los artículos y actualizar banderas
        // solamente cuando no estamos simulando.
        $resultados = $this->stockAlertService->procesar(
            !$modoSimulacion
        );

        $articulosPendientes = [];
        $indicesPendientes = [];

        // Preparar los artículos que necesitan notificación.
        foreach ($resultados as $indice => $articulo) {

            $resultados[$indice]['correo_enviado'] = false;
            $resultados[$indice]['error_correo'] = null;
            $resultados[$indice]['error_actualizacion_bandera'] = null;
            $resultados[$indice]['band_correo_actualizada'] = false;

            if ($articulo['correo_pendiente']) {
                $articulosPendientes[] = $articulo;
                $indicesPendientes[] = $indice;
            }
        }

        $correosEnviados = 0;
        $correosFallidos = 0;
        $articulosNotificados = 0;
        $erroresActualizacionBanderas = 0;

        // En producción, enviar un solo correo con todos los pendientes.
        if (!$modoSimulacion && !empty($articulosPendientes)) {

            try {

                $enviado = $this->emailService->enviarAlertas(
                    $articulosPendientes
                );

                if (!$enviado) {
                    throw new RuntimeException(
                        'No se confirmó el envío del correo agrupado.'
                    );
                }

                // El correo agrupado fue aceptado por el servidor SMTP.
                $correosEnviados = 1;
                $articulosNotificados = count($articulosPendientes);

                // Marcar individualmente los artículos como notificados.
                foreach ($indicesPendientes as $indice) {

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

                        // El correo sí se envió, pero no se pudo
                        // actualizar la bandera en SQL Server.
                        $resultados[$indice]['error_actualizacion_bandera']
                            = $e->getMessage();

                        $erroresActualizacionBanderas++;
                    }
                }

            } catch (Throwable $e) {

                // Si falla el envío agrupado, ninguno de los artículos
                // se marca como correo enviado.
                $correosFallidos = 1;

                foreach ($indicesPendientes as $indice) {

                    $resultados[$indice]['error_correo'] =
                        $e->getMessage();
                }
            }
        }

        return [
            'modo_simulacion' => $modoSimulacion,
            'total_articulos' => count($resultados),
            'articulos_pendientes' => count($articulosPendientes),
            'articulos_notificados' => $articulosNotificados,
            'correos_enviados' => $correosEnviados,
            'correos_fallidos' => $correosFallidos,
            'errores_actualizacion_banderas' =>
                $erroresActualizacionBanderas,
            'resultados' => $resultados
        ];
    }
}

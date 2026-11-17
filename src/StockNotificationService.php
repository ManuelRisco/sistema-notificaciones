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
        $almacenNormalizado = $this->validarAlmacenFiltro($almacenFiltro);

        // Analizar los artículos y actualizar banderas solamente cuando no estamos simulando.
        $resultados = $this->stockAlertService->procesar(
            !$modoSimulacion,
            $almacenNormalizado
        );

        $resumen = $this->prepararPendientes($resultados);
        $resultados = $resumen['resultados'];
        $pendientesPorAlmacen = $resumen['pendientes_por_almacen'];

        $metricasEnvio = [
            'correos_enviados' => 0,
            'correos_fallidos' => 0,
            'articulos_notificados' => 0,
            'errores_actualizacion_banderas' => 0,
        ];

        // En producción, enviar un correo agrupado por cada almacén que tenga artículos pendientes.
        if (!$modoSimulacion && !empty($pendientesPorAlmacen)) {
            $metricasEnvio = $this->despacharCorreosPorAlmacen($pendientesPorAlmacen, $resultados);
        }

        return [
            'modo_simulacion' => $modoSimulacion,
            'total_articulos' => count($resultados),
            'articulos_pendientes' => $resumen['articulos_pendientes'],
            'articulos_notificados' => $metricasEnvio['articulos_notificados'],
            'correos_enviados' => $metricasEnvio['correos_enviados'],
            'correos_fallidos' => $metricasEnvio['correos_fallidos'],
            'errores_actualizacion_banderas' => $metricasEnvio['errores_actualizacion_banderas'],
            'resultados' => $resultados
        ];
    }

    private function validarAlmacenFiltro(?string $almacenFiltro): ?string
    {
        if ($almacenFiltro === null || trim($almacenFiltro) === '') {
            return null;
        }

        $almacen = trim($almacenFiltro);
        if (function_exists('esAlmacenSoportado') && !esAlmacenSoportado($almacen)) {
            throw new RuntimeException("El almacén '{$almacen}' no está soportado en la configuración.");
        }

        return $almacen;
    }

    private function prepararPendientes(array $resultados): array
    {
        $articulosPendientes = 0;
        $pendientesPorAlmacen = [];

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

        return [
            'resultados' => $resultados,
            'pendientes_por_almacen' => $pendientesPorAlmacen,
            'articulos_pendientes' => $articulosPendientes,
        ];
    }

    private function despacharCorreosPorAlmacen(array $pendientesPorAlmacen, array &$resultados): array
    {
        $correosEnviados = 0;
        $correosFallidos = 0;
        $articulosNotificados = 0;
        $erroresBanderas = 0;

        foreach ($pendientesPorAlmacen as $almCodigo => $itemsAlmacen) {
            $articulosDelAlmacen = array_column($itemsAlmacen, 'articulo');
            $indicesDelAlmacen = array_column($itemsAlmacen, 'indice');

            try {
                $enviado = $this->emailService->enviarAlertasPorAlmacen($almCodigo, $articulosDelAlmacen);
                if (!$enviado) {
                    throw new RuntimeException(
                        "No se confirmó el envío del correo agrupado para el almacén {$almCodigo}."
                    );
                }

                $correosEnviados++;
                $articulosNotificados += count($articulosDelAlmacen);
                $erroresBanderas += $this->marcarArticulosNotificados($indicesDelAlmacen, $resultados);
            } catch (Throwable $e) {
                $correosFallidos++;
                foreach ($indicesDelAlmacen as $indice) {
                    $resultados[$indice]['error_correo'] = $e->getMessage();
                }
            }
        }

        return [
            'correos_enviados' => $correosEnviados,
            'correos_fallidos' => $correosFallidos,
            'articulos_notificados' => $articulosNotificados,
            'errores_actualizacion_banderas' => $erroresBanderas,
        ];
    }

    private function marcarArticulosNotificados(array $indicesDelAlmacen, array &$resultados): int
    {
        $errores = 0;

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
                $resultados[$indice]['error_actualizacion_bandera'] = $e->getMessage();
                $errores++;
            }
        }

        return $errores;
    }
}

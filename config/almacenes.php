<?php

declare(strict_types=1);

/**
 * Catálogo de almacenes soportados en el Sistema de Alertas de Stock.
 */
function getAlmacenesSoportados(): array
{
    return [
        '003' => [
            'codigo' => '003',
            'nombre' => 'Tintas y Solventes',
            'nombre_erp' => 'ALMACEN TINTAS SOLVENTES Y ADHESIVOS',
            'descripcion' => 'Monitoreo de stock mínimo de tintas, solventes y adhesivos.'
        ],
        '001' => [
            'codigo' => '001',
            'nombre' => 'Materia Prima',
            'nombre_erp' => 'ALMACEN MATERIAS PRIMAS',
            'descripcion' => 'Monitoreo de stock mínimo de materias primas e insumos.'
        ]
    ];
}

/**
 * Retorna el nombre amigable de un almacén dado su código.
 */
function getNombreAlmacen(string $almCodigo): string
{
    $codigo = trim($almCodigo);
    $alms = getAlmacenesSoportados();

    if (isset($alms[$codigo])) {
        return $alms[$codigo]['nombre'];
    }

    return "Almacén {$codigo}";
}

/**
 * Comprueba si un código de almacén es soportado por el sistema.
 */
function esAlmacenSoportado(string $almCodigo): bool
{
    $codigo = trim($almCodigo);
    $alms = getAlmacenesSoportados();

    return isset($alms[$codigo]);
}


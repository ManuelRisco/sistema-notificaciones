<?php
/**
 * Panel de métricas y resumen general para el almacén activo.
 * Espera la variable $totales con las claves: total, disponibles, no_disponibles,
 * con_stock, sin_stock, correo_enviado, correo_no_enviado, no_encontrados.
 */
?>
<div class="resumen">
    <div class="resumen-grupo resumen-total">
        <span class="resumen-total-label">Total:</span>
        <strong><?= $totales['total'] ?></strong>
    </div>

    <div class="resumen-separador"></div>

    <div class="resumen-grupo">
        <span class="resumen-grupo-titulo">Disponibilidad:</span>
        <div class="resumen-item">
            <span>Disponibles</span>
            <strong><?= $totales['disponibles'] ?></strong>
        </div>
        <div class="resumen-item">
            <span>No disponibles</span>
            <strong><?= $totales['no_disponibles'] ?></strong>
        </div>
    </div>

    <div class="resumen-separador"></div>

    <div class="resumen-grupo">
        <span class="resumen-grupo-titulo">Stock:</span>
        <div class="resumen-item">
            <span>Con stock</span>
            <strong><?= $totales['con_stock'] ?></strong>
        </div>
        <div class="resumen-item">
            <span>Sin stock</span>
            <strong><?= $totales['sin_stock'] ?></strong>
        </div>
    </div>

    <div class="resumen-separador"></div>

    <div class="resumen-grupo">
        <span class="resumen-grupo-titulo">Correo:</span>
        <div class="resumen-item">
            <span>Enviado</span>
            <strong><?= $totales['correo_enviado'] ?></strong>
        </div>
        <div class="resumen-item">
            <span>No enviado</span>
            <strong><?= $totales['correo_no_enviado'] ?></strong>
        </div>
    </div>

    <?php if ($totales['no_encontrados'] > 0): ?>
        <div class="resumen-separador"></div>
        <div class="resumen-grupo">
            <span class="resumen-grupo-titulo">Catálogo:</span>
            <div class="resumen-item">
                <span>No encontrados</span>
                <strong><?= $totales['no_encontrados'] ?></strong>
            </div>
        </div>
    <?php endif; ?>
</div>


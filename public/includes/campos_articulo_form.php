<?php

declare(strict_types=1);

/**
 * Campos comunes y botones de acción para crear.php y editar.php.
 *
 * Variables esperadas:
 * - string $categoria
 * - string $famNombre
 * - string $tfaNombre
 * - string $stockMinimo
 * - int    $disponible
 * - string $textoBotonGuardar
 * Variables opcionales:
 * - string $unidadMedida
 * - string $placeholderStockMinimo
 */
$unidadMedida = $unidadMedida ?? '';
$placeholderStockMinimo = $placeholderStockMinimo ?? '';
?>
<!-- Categoría -->
<div class="campo">
    <label for="art_categoria">
        Categoría
    </label>
    <input
        type="text"
        id="art_categoria"
        name="categoria"
        value="<?= h($categoria) ?>"
        autocomplete="off"
        readonly
        placeholder="—"
    >
</div>

<!-- Familia -->
<div class="campo">
    <label for="art_familia">
        Familia
    </label>
    <input
        type="text"
        id="art_familia"
        name="fam_nombre"
        value="<?= h($famNombre) ?>"
        autocomplete="off"
        readonly
        placeholder="—"
    >
</div>

<!-- Tipo familia -->
<div class="campo">
    <label for="art_tipo_familia">
        Tipo familia
    </label>
    <input
        type="text"
        id="art_tipo_familia"
        name="tfa_nombre"
        value="<?= h($tfaNombre) ?>"
        autocomplete="off"
        readonly
        placeholder="—"
    >
</div>

<!-- Stock mínimo -->
<div class="campo">
    <label for="stock_minimo">
        Stock mínimo
    </label>
    <div class="campo-con-sufijo">
        <input
            type="number"
            id="stock_minimo"
            name="stock_minimo"
            min="0"
            max="999999999.99999"
            step="0.00001"
            required
            <?= $placeholderStockMinimo !== '' ? 'placeholder="' . h($placeholderStockMinimo) . '"' : '' ?>
            value="<?= h($stockMinimo) ?>"
        >
        <span id="sufijo-unidad-minimo" class="sufijo-unidad"><?= h($unidadMedida) ?></span>
    </div>
</div>

<!-- Disponibilidad -->
<div class="campo">
    <label for="disponible">
        Disponibilidad
    </label>
    <select id="disponible" name="disponible">
        <option value="1" <?= $disponible === 1 ? 'selected' : '' ?>>
            Activo
        </option>
        <option value="0" <?= $disponible === 0 ? 'selected' : '' ?>>
            Inactivo
        </option>
    </select>
</div>

<div class="acciones">
    <button
        type="submit"
        class="btn btn-primario"
    >
        <?= h($textoBotonGuardar) ?>
    </button>
    <a
        href="index.php"
        class="btn btn-secundario"
    >
        Cancelar
    </a>
</div>


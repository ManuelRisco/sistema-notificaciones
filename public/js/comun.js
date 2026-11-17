// Utilidades compartidas por todas las páginas.

// Sincronizar modo oscuro antes de renderizar para evitar parpadeos
(function () {
    try {
        const modo = localStorage.getItem('dark_mode');
        if (modo === 'true') {
            document.documentElement.classList.add('dark-mode');
        } else if (modo === 'false') {
            document.documentElement.classList.remove('dark-mode');
        }
    } catch (_) {}
})();

// Alternar modo oscuro / claro compartido globalmente en todas las páginas
function toggleDarkMode(e) {
    if (e) e.preventDefault();
    const html = document.documentElement;
    const esOscuro = html.classList.toggle('dark-mode');
    const valor = esOscuro ? 'true' : 'false';

    // 1. Limpiar cualquier cookie residual con ruta específica
    document.cookie = "dark_mode=; path=" + window.location.pathname + "; expires=Thu, 01 Jan 1970 00:00:00 UTC;";
    const subpath = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
    if (subpath) {
        document.cookie = "dark_mode=; path=" + subpath + "; expires=Thu, 01 Jan 1970 00:00:00 UTC;";
        document.cookie = "dark_mode=; path=" + subpath + "/; expires=Thu, 01 Jan 1970 00:00:00 UTC;";
    }

    // 2. Guardar UNA sola cookie global en la raíz "/"
    document.cookie = `dark_mode=${valor}; path=/; max-age=2592000; SameSite=Lax`;

    // 3. Guardar en localStorage (sincronización instantánea entre pestañas y páginas)
    try {
        localStorage.setItem('dark_mode', valor);
    } catch (_) {}

    // 4. Actualizar el emoji del botón
    const btn = document.getElementById('btn-toggle-dark');
    if (btn) {
        btn.textContent = esOscuro ? '🌞' : '🌚';
    }
}

// Escapa texto para insertarlo de forma segura en HTML
function escapeHtml(texto) {
    const div = document.createElement('div');
    div.textContent = texto || '';
    return div.innerHTML;
}

// Sincronizar el emoji del botón de tema según el estado activo de la página
document.addEventListener('DOMContentLoaded', function () {
    const btnDark = document.getElementById('btn-toggle-dark');
    if (btnDark) {
        btnDark.textContent = document.documentElement.classList.contains('dark-mode') ? '🌞' : '🌚';
    }
});

// Aviso con un único botón "Entendido" (requiere SweetAlert2)
function swalEntendido(icon, title, html) {
    return Swal.fire({
        icon: icon,
        title: title,
        html: html,
        confirmButtonColor: '#dc2626',
        confirmButtonText: 'Entendido'
    });
}

// Modal de progreso mientras se guarda un formulario (requiere SweetAlert2)
function mostrarGuardando(titulo, detalle) {
    Swal.fire({
        title: titulo,
        html: detalle + '<br><small style="color: #64748b;">Por favor espera un momento...</small>',
        allowOutsideClick: false,
        allowEscapeKey: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
}

/**
 * Calcula la secuencia compacta de páginas con elipsis ('...') para barras de paginación.
 *
 * @param {number} actual Página actual (>= 1)
 * @param {number} total  Total de páginas (>= 1)
 * @returns {Array<number|string>}
 */
function calcularPaginasVisibles(actual, total) {
    if (total <= 7) {
        return Array.from({ length: total }, (_, idx) => idx + 1);
    }

    const paginas = [1];
    const inicio = Math.max(2, actual - 1);
    const fin = Math.min(total - 1, actual + 1);

    if (inicio > 2) {
        paginas.push('...');
    }
    for (let p = inicio; p <= fin; p++) {
        paginas.push(p);
    }
    if (fin < total - 1) {
        paginas.push('...');
    }

    paginas.push(total);
    return paginas;
}

/**
 * Construye el HTML de una barra de paginación compacta para modales u otros contenedores dinámicos.
 *
 * @param {Object} opts
 * @param {number} opts.pagina      Página actual (>= 1)
 * @param {number} opts.totalPaginas Total de páginas (>= 1)
 * @param {number} opts.total       Total de registros filtrados
 * @param {number} opts.porPagina   Registros por página
 * @param {string} [opts.etiquetaItem] Sustantivo en plural ('artículos', 'registros', etc.)
 * @param {string} [opts.sufijoExtra]  Texto adicional junto al resumen
 * @param {number[]} [opts.opcionesTamano]
 * @returns {string}
 */
function construirHtmlPaginacion(opts) {
    const pagina = Math.max(1, Number(opts.pagina) || 1);
    const totalPaginas = Math.max(1, Number(opts.totalPaginas) || 1);
    const total = Math.max(0, Number(opts.total) || 0);
    const porPagina = Math.max(1, Number(opts.porPagina) || 10);
    const etiqueta = opts.etiquetaItem || 'registros';
    const sufijo = opts.sufijoExtra ? ` <span class="paginacion-sufijo">${escapeHtml(opts.sufijoExtra)}</span>` : '';
    const opciones = Array.isArray(opts.opcionesTamano) ? opts.opcionesTamano : [10, 15, 25, 50];

    const inicio = total === 0 ? 0 : ((pagina - 1) * porPagina) + 1;
    const fin = total === 0 ? 0 : Math.min(total, pagina * porPagina);

    const resumenHtml = total === 0
        ? `0 ${escapeHtml(etiqueta)}${sufijo}`
        : `Mostrando <strong>${inicio}–${fin}</strong> de <strong>${total}</strong> ${escapeHtml(etiqueta)}${sufijo}`;

    const selectHtml = `
        <label class="paginacion-tamano">
            <span>Por pág.:</span>
            <select class="paginacion-select js-paginacion-tamano" aria-label="Registros por página">
                ${opciones.map(n => `<option value="${n}" ${n === porPagina ? 'selected' : ''}>${n}</option>`).join('')}
            </select>
        </label>
    `;

    const secuencia = calcularPaginasVisibles(pagina, totalPaginas);
    let botonesHtml = `
        <button type="button" class="paginacion-btn js-paginacion-btn" data-pagina="${pagina - 1}" ${pagina <= 1 ? 'disabled' : ''} aria-label="Página anterior">
            ‹ Anterior
        </button>
    `;

    secuencia.forEach(item => {
        if (item === '...') {
            botonesHtml += '<span class="paginacion-ellipsis">…</span>';
        } else if (item === pagina) {
            botonesHtml += `<button type="button" class="paginacion-btn activo" data-pagina="${item}" aria-current="page">${item}</button>`;
        } else {
            botonesHtml += `<button type="button" class="paginacion-btn js-paginacion-btn" data-pagina="${item}">${item}</button>`;
        }
    });

    botonesHtml += `
        <button type="button" class="paginacion-btn js-paginacion-btn" data-pagina="${pagina + 1}" ${pagina >= totalPaginas ? 'disabled' : ''} aria-label="Página siguiente">
            Siguiente ›
        </button>
    `;

    return `
        <div class="paginacion-contenedor paginacion-modal">
            <div class="paginacion-info">
                <span>${resumenHtml}</span>
                ${selectHtml}
            </div>
            <nav class="paginacion-controles" aria-label="Paginación">${botonesHtml}</nav>
        </div>
    `;
}

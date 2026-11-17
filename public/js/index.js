// JavaScript para la página principal de monitoreo (index.php)

/**
 * Manejo del menú desplegable para filtros de columnas en los encabezados de tabla.
 * Se expone globalmente para permitir el onclick inline de los botones.
 *
 * @param {Event} e
 * @param {string} menuId ID del menú flotante
 */
function toggleMenuFiltro(e, menuId) {
    e.stopPropagation();
    const menu = document.getElementById(menuId);
    if (!menu) return;

    const estaAbierto = menu.classList.contains('menu-abierto');

    // Cerrar cualquier otro menú de filtro abierto
    document.querySelectorAll('.menu-filtro').forEach(function(m) {
        m.classList.remove('menu-abierto');
        const th = m.closest('.th-filtrable');
        if (th) th.classList.remove('th-menu-activo');
    });

    if (!estaAbierto) {
        menu.classList.add('menu-abierto');
        const thActual = menu.closest('.th-filtrable');
        if (thActual) thActual.classList.add('th-menu-activo');
    }
}

// Exponer en el objeto global window
window.toggleMenuFiltro = toggleMenuFiltro;

// Cerrar menús al hacer clic fuera de los encabezados filtrables
document.addEventListener('click', function(e) {
    if (!e.target.closest('.th-filtrable')) {
        document.querySelectorAll('.menu-filtro').forEach(function(m) {
            m.classList.remove('menu-abierto');
            const th = m.closest('.th-filtrable');
            if (th) th.classList.remove('th-menu-activo');
        });
    }
});

// Cerrar menús o salir de pantalla completa con la tecla Escape (priorizando SweetAlert y menús abiertos)
document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;

    const menusAbiertos = document.querySelectorAll('.menu-filtro.menu-abierto');
    if (menusAbiertos.length > 0) {
        menusAbiertos.forEach(function(m) {
            m.classList.remove('menu-abierto');
            const th = m.closest('.th-filtrable');
            if (th) th.classList.remove('th-menu-activo');
        });
        return;
    }

    const swalAbierto = typeof Swal !== 'undefined' && typeof Swal.isVisible === 'function' && Swal.isVisible();
    if (!swalAbierto && typeof window.establecerPantallaCompletaTabla === 'function') {
        const panelTabla = document.getElementById('panel-tabla-index');
        if (panelTabla && panelTabla.classList.contains('tabla-pantalla-completa')) {
            window.establecerPantallaCompletaTabla(false);
        }
    }
});

/**
 * Limpia parámetros de estado (?ok / ?error) de la URL actual tras mostrar la notificación.
 */
function limpiarParametrosEstadoUrl() {
    if (!window.history.replaceState) {
        return;
    }
    const url = new URL(window.location);
    url.searchParams.delete('ok');
    url.searchParams.delete('error');
    window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : ''));
}

/**
 * Muestra el modal de resultado tras ejecutar la revisión manual de stock,
 * indicando de forma simple si el correo se envió correctamente o si hubo un error.
 *
 * @param {Object} data Respuesta JSON de ejecutar_manual.php
 */
function mostrarResultadoRevisionStock(data) {
    const estadoCorreo = data.estado_correo || 'ninguno';

    if (estadoCorreo !== 'enviado' && estadoCorreo !== 'error') {
        Swal.fire({
            icon: 'success',
            title: '¡Stock revisado correctamente!',
            confirmButtonColor: '#059669',
            confirmButtonText: 'Aceptar'
        }).then(() => {
            window.location.reload();
        });
        return;
    }

    const esError = (estadoCorreo === 'error');
    const mensajeBanner = data.correo_estado_mensaje || (
        esError
            ? 'Hubo un error al enviar el correo de notificación.'
            : 'El correo de notificación se envió correctamente.'
    );

    const svgIcono = esError
        ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>'
        : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>';

    const claseBanner = esError ? 'swal-correo-banner--error' : 'swal-correo-banner--exito';

    Swal.fire({
        icon: esError ? 'warning' : 'success',
        title: esError ? 'Stock revisado' : '¡Stock revisado correctamente!',
        html: `
            <div class="swal-correo-banner ${claseBanner}">
                <span class="swal-correo-banner-icono">${svgIcono}</span>
                <span>${escapeHtml(mensajeBanner)}</span>
            </div>
        `,
        confirmButtonColor: esError ? '#d97706' : '#059669',
        confirmButtonText: 'Aceptar'
    }).then(() => {
        window.location.reload();
    });
}

/**
 * Inicializa los eventos, modales y búsqueda de index.php.
 *
 * @param {Object} [config]
 * @param {string|null} [config.mensajeExito]
 * @param {string|null} [config.mensajeAdvertencia]
 * @param {string|null} [config.error]
 * @param {boolean} [config.hayFiltrosActivos]
 * @param {number} [config.totalRegistros]
 */
function iniciarIndex(config = {}) {
    // 1. Mostrar modales para mensajes de sesión o URL (sin alertas estáticas en HTML)
    if (config.mensajeAdvertencia) {
        Swal.fire({
            icon: 'warning',
            title: 'Stock revisado',
            html: config.mensajeAdvertencia,
            confirmButtonColor: '#d97706',
            confirmButtonText: 'Aceptar'
        });
        limpiarParametrosEstadoUrl();
    } else if (config.mensajeExito) {
        Swal.fire({
            icon: 'success',
            title: '¡Operación Exitosa!',
            html: config.mensajeExito,
            timer: 3500,
            timerProgressBar: true,
            confirmButtonColor: '#2563eb',
            confirmButtonText: 'Aceptar'
        });
        limpiarParametrosEstadoUrl();
    } else if (config.error) {
        Swal.fire({
            icon: 'error',
            title: 'Atención',
            html: config.error,
            confirmButtonColor: '#dc2626',
            confirmButtonText: 'Entendido'
        });
    }

    // 2. Control animado para "Revisar Stock" (con modal de progreso según tiempo de respuesta)
    const formEjecutar = document.querySelector('.form-ejecutar-manual');
    if (formEjecutar) {
        formEjecutar.addEventListener('submit', function(e) {
            e.preventDefault();

            Swal.fire({
                title: '¿Estás seguro de revisar el inventario?',
                text: 'Se analizará el inventario actual y se enviarán correos si existen alertas de stock que no se enviaron por correo.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#059669',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Sí, ejecutar ahora',
                cancelButtonText: 'No',
                reverseButtons: true,
                focusCancel: true
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Ejecutando alertas...',
                        html: 'Por favor espera un momento...',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });

                    const formData = new FormData(formEjecutar);
                    formData.append('formato', 'json');

                    fetch('ejecutar_manual.php', {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: formData
                    })
                    .then(response => {
                        return response.json().then(data => ({
                            status: response.status,
                            data: data
                        })).catch(() => ({
                            status: response.status,
                            data: { exito: false, error: 'Respuesta inválida del servidor.' }
                        }));
                    })
                    .then(({ status, data }) => {
                        if (status === 200 && data.exito) {
                            if (data.en_curso) {
                                Swal.fire({
                                    icon: 'info',
                                    title: 'Proceso en curso',
                                    text: data.mensaje || 'Ya hay una revisión de alertas en ejecución en este momento. Por favor espera a que finalice.',
                                    confirmButtonColor: '#2563eb',
                                    confirmButtonText: 'Entendido'
                                });
                            } else {
                                mostrarResultadoRevisionStock(data);
                            }
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error en la ejecución',
                                text: data.error || 'Ocurrió un error inesperado al procesar las alertas.',
                                confirmButtonColor: '#dc2626'
                            });
                        }
                    })
                    .catch(err => {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error de conexión',
                            text: 'No se pudo comunicar con el servidor: ' + err.message,
                            confirmButtonColor: '#dc2626'
                        });
                    });
                }
            });
        });
    }

    // 3. Control animado para botones "Desactivar" y "Reactivar" (delegado para soportar paginación AJAX)
    document.addEventListener('submit', function(e) {
        const form = e.target.closest ? e.target.closest('.form-eliminar') : null;
        if (!form) return;

        e.preventDefault();

        const accion = form.querySelector('input[name="accion"]')?.value || 'eliminar';
        const artCodigo = form.querySelector('input[name="art_codigo"]')?.value || '';
        const artNombre = form.querySelector('input[name="art_nombre"]')?.value || '';
        const categoria = form.querySelector('input[name="categoria"]')?.value || '';
        const famNombre = form.querySelector('input[name="fam_nombre"]')?.value || '';
        const tfaNombre = form.querySelector('input[name="tfa_nombre"]')?.value || '';
        const esReactivar = (accion === 'reactivar');

        let detalleArticulo = '';
        if (artNombre) {
            detalleArticulo += `<div class="swal-articulo-nombre">${escapeHtml(artNombre)}</div>`;
        }

        if (categoria || famNombre || tfaNombre) {
            detalleArticulo += `
                <div class="swal-articulo-detalle">
                    <div class="swal-articulo-detalle-fila"><span>Categoría:</span> <strong>${escapeHtml(categoria || '—')}</strong></div>
                    <div class="swal-articulo-detalle-fila"><span>Familia:</span> <strong>${escapeHtml(famNombre || '—')}</strong></div>
                    <div class="swal-articulo-detalle-fila"><span>Tipo familia:</span> <strong>${escapeHtml(tfaNombre || '—')}</strong></div>
                </div>
            `;
        }

        const titulo = esReactivar ? '¿Reactivar configuración?' : '¿Desactivar configuración?';
        const mensaje = esReactivar
            ? `¿Deseas volver a habilitar el artículo <strong>${escapeHtml(artCodigo)}</strong> para el monitoreo de stock?${detalleArticulo}`
            : `¿Estás seguro de desactivar el artículo <strong>${escapeHtml(artCodigo)}</strong>?${detalleArticulo}`;
        const icono = esReactivar ? 'question' : 'warning';
        const colorConfirmar = esReactivar ? '#059669' : '#dc2626';
        const textoConfirmar = esReactivar ? 'Sí, reactivar' : 'Sí, desactivar';

        Swal.fire({
            title: titulo,
            html: mensaje,
            icon: icono,
            showCancelButton: true,
            confirmButtonColor: colorConfirmar,
            cancelButtonColor: '#64748b',
            confirmButtonText: textoConfirmar,
            cancelButtonText: 'No',
            reverseButtons: true,
            focusCancel: true
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: esReactivar ? 'Reactivando...' : 'Desactivando...',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                const formData = new FormData(form);
                formData.append('formato', 'json');

                fetch('cambiar_estado.php', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                })
                .then(response => {
                    return response.json().then(data => ({
                        status: response.status,
                        data: data
                    })).catch(() => ({
                        status: response.status,
                        data: { exito: false, error: 'Respuesta inválida del servidor.' }
                    }));
                })
                .then(({ status, data }) => {
                    if (status === 200 && data.exito) {
                        Swal.fire({
                            icon: 'success',
                            title: esReactivar ? '¡Reactivado!' : '¡Desactivado!',
                            text: data.mensaje,
                            timer: 3000,
                            timerProgressBar: true,
                            showConfirmButton: false
                        }).then(() => {
                            window.location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: data.error || 'No se pudo completar la operación.',
                            confirmButtonColor: '#dc2626'
                        });
                    }
                })
                .catch(err => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error de conexión',
                        text: err.message,
                        confirmButtonColor: '#dc2626'
                    });
                });
            }
        });
    });

    // 4. Búsqueda de artículos en BD con timer de 300ms y paginación AJAX en index
    const inputArticulo = document.getElementById('articulo');
    const formBusqueda = document.querySelector('.busqueda-form');
    const tablaContenedor = document.querySelector('.tabla-contenedor');
    const tablaCuerpo = document.getElementById('tabla-configuraciones-body');
    const parrafoResultados = document.getElementById('busqueda-resultados');
    const paginacionContenedor = document.getElementById('paginacion-index');
    const btnLimpiarFiltros = document.getElementById('btn-limpiar-filtros');

    let busquedaActual = (config.busqueda !== undefined && config.busqueda !== null)
        ? String(config.busqueda).trim()
        : (inputArticulo ? inputArticulo.value.trim() : '');
    let paginaActual = Number(config.pagina) || 1;
    let porPaginaActual = Number(config.porPagina) || 10;
    let timerBusqueda = null;
    let abortController = null;

    function sincronizarEnlacesFiltros(q, porPagina) {
        document.querySelectorAll('.menu-filtro a.opcion-filtro').forEach(enlace => {
            try {
                const urlEnlace = new URL(enlace.href, window.location.origin);
                if (q !== '') {
                    urlEnlace.searchParams.set('q', q);
                } else {
                    urlEnlace.searchParams.delete('q');
                }
                if (porPagina !== 10) {
                    urlEnlace.searchParams.set('por_pagina', String(porPagina));
                } else {
                    urlEnlace.searchParams.delete('por_pagina');
                }
                urlEnlace.searchParams.delete('pagina');
                const query = urlEnlace.searchParams.toString();
                enlace.setAttribute('href', 'index.php' + (query ? `?${query}` : ''));
            } catch (_) {}
        });

        const hiddenPorPagina = document.getElementById('hidden-por-pagina');
        if (hiddenPorPagina) {
            hiddenPorPagina.value = String(porPagina);
        }
    }

    function cargarTablaIndex(opciones = {}) {
        if (!tablaCuerpo) return;

        const q = opciones.q !== undefined ? String(opciones.q).trim() : busquedaActual;
        const pagina = opciones.pagina !== undefined ? Math.max(1, Number(opciones.pagina) || 1) : paginaActual;
        const porPagina = opciones.porPagina !== undefined ? (Number(opciones.porPagina) || 10) : porPaginaActual;

        const urlNavegador = new URL(window.location.href);
        urlNavegador.searchParams.delete('ok');
        urlNavegador.searchParams.delete('error');
        urlNavegador.searchParams.delete('ajax');

        if (q !== '') {
            urlNavegador.searchParams.set('q', q);
        } else {
            urlNavegador.searchParams.delete('q');
        }

        if (pagina > 1) {
            urlNavegador.searchParams.set('pagina', String(pagina));
        } else {
            urlNavegador.searchParams.delete('pagina');
        }

        if (porPagina !== 10) {
            urlNavegador.searchParams.set('por_pagina', String(porPagina));
        } else {
            urlNavegador.searchParams.delete('por_pagina');
        }

        const urlFetch = new URL(urlNavegador.toString());
        urlFetch.searchParams.set('ajax', '1');

        if (abortController) {
            abortController.abort();
        }
        abortController = new AbortController();

        if (tablaContenedor) {
            tablaContenedor.classList.add('tabla-cargando');
        }

        fetch(urlFetch.toString(), {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            signal: abortController.signal
        })
        .then(res => res.json())
        .then(data => {
            if (!data || !data.exito) {
                throw new Error((data && data.error) || 'No se pudo actualizar la tabla.');
            }

            busquedaActual = q;
            paginaActual = Number(data.pagina) || 1;
            porPaginaActual = Number(data.por_pagina) || porPagina;

            if (paginaActual > 1) {
                urlNavegador.searchParams.set('pagina', String(paginaActual));
            } else {
                urlNavegador.searchParams.delete('pagina');
            }

            if (window.history.replaceState) {
                const searchStr = urlNavegador.searchParams.toString();
                window.history.replaceState({}, document.title, urlNavegador.pathname + (searchStr ? `?${searchStr}` : ''));
            }

            tablaCuerpo.innerHTML = data.tbody_html || '';

            if (paginacionContenedor && data.paginacion_html !== undefined) {
                paginacionContenedor.innerHTML = data.paginacion_html;
            }

            if (parrafoResultados) {
                if (data.hay_filtros_activos && data.resumen_html) {
                    parrafoResultados.style.display = 'block';
                    parrafoResultados.innerHTML = data.resumen_html;
                } else {
                    parrafoResultados.style.display = 'none';
                    parrafoResultados.innerHTML = '';
                }
            }

            if (btnLimpiarFiltros) {
                btnLimpiarFiltros.style.display = data.hay_filtros_activos ? '' : 'none';
            }

            sincronizarEnlacesFiltros(q, porPaginaActual);

            if (opciones.scrollTabla && tablaContenedor) {
                tablaContenedor.scrollTop = 0;
            }
        })
        .catch(err => {
            if (err && err.name === 'AbortError') {
                return;
            }
            Swal.fire({
                icon: 'error',
                title: 'Error al consultar',
                text: (err && err.message) || 'No se pudo actualizar el listado.',
                confirmButtonColor: '#dc2626'
            });
        })
        .finally(() => {
            if (tablaContenedor) {
                tablaContenedor.classList.remove('tabla-cargando');
            }
        });
    }

    if (inputArticulo && tablaCuerpo) {
        if (formBusqueda) {
            formBusqueda.addEventListener('submit', function(e) {
                e.preventDefault();
                clearTimeout(timerBusqueda);
                const nuevoTermino = inputArticulo.value.trim();
                if (nuevoTermino !== busquedaActual) {
                    cargarTablaIndex({ q: nuevoTermino, pagina: 1 });
                }
            });
        }

        inputArticulo.addEventListener('input', function() {
            clearTimeout(timerBusqueda);
            timerBusqueda = setTimeout(() => {
                const nuevoTermino = inputArticulo.value.trim();
                if (nuevoTermino === busquedaActual) {
                    return;
                }
                cargarTablaIndex({ q: nuevoTermino, pagina: 1 });
            }, 300);
        });

        inputArticulo.addEventListener('search', function() {
            clearTimeout(timerBusqueda);
            const nuevoTermino = inputArticulo.value.trim();
            if (nuevoTermino !== busquedaActual) {
                cargarTablaIndex({ q: nuevoTermino, pagina: 1 });
            }
        });
    }

    if (paginacionContenedor) {
        paginacionContenedor.addEventListener('click', function(e) {
            const enlace = e.target.closest ? e.target.closest('a.paginacion-btn[data-pagina]') : null;
            if (!enlace) return;
            e.preventDefault();
            clearTimeout(timerBusqueda);
            const nuevaPagina = Number(enlace.dataset.pagina) || 1;
            cargarTablaIndex({ pagina: nuevaPagina, scrollTabla: true });
        });

        paginacionContenedor.addEventListener('change', function(e) {
            if (e.target && e.target.id === 'por-pagina-index') {
                clearTimeout(timerBusqueda);
                const nuevoTam = Number(e.target.value) || 10;
                cargarTablaIndex({ pagina: 1, porPagina: nuevoTam, scrollTabla: true });
            }
        });
    }

    // 5. Modo pantalla completa para el panel de búsqueda, tabla y paginación
    const panelTabla = document.getElementById('panel-tabla-index');
    const btnPantallaCompleta = document.getElementById('btn-pantalla-completa');
    const STORAGE_FULLSCREEN_KEY = 'index_tabla_fullscreen';

    function establecerPantallaCompletaTabla(activo) {
        if (!panelTabla || !btnPantallaCompleta) return;

        const activar = Boolean(activo);
        panelTabla.classList.toggle('tabla-pantalla-completa', activar);
        document.body.classList.toggle('tabla-fullscreen-abierta', activar);
        btnPantallaCompleta.classList.toggle('activo', activar);
        btnPantallaCompleta.setAttribute('aria-pressed', activar ? 'true' : 'false');
        btnPantallaCompleta.setAttribute(
            'title',
            activar ? 'Salir de pantalla completa (Esc)' : 'Ver tabla en pantalla completa'
        );

        const textoSpan = btnPantallaCompleta.querySelector('.btn-pantalla-texto');
        if (textoSpan) {
            textoSpan.textContent = activar ? 'Salir de pantalla completa' : 'Pantalla completa';
        }

        try {
            if (activar) {
                sessionStorage.setItem(STORAGE_FULLSCREEN_KEY, '1');
            } else {
                sessionStorage.removeItem(STORAGE_FULLSCREEN_KEY);
            }
        } catch (_) {}
    }

    window.establecerPantallaCompletaTabla = establecerPantallaCompletaTabla;

    if (btnPantallaCompleta && panelTabla && tablaContenedor) {
        btnPantallaCompleta.addEventListener('click', function() {
            const estaActiva = panelTabla.classList.contains('tabla-pantalla-completa');
            establecerPantallaCompletaTabla(!estaActiva);
        });

        // Al salir hacia otras vistas (Editar, Crear, Cambiar almacén, Logout), restaurar vista normal
        document.addEventListener('click', function(e) {
            const enlace = e.target.closest ? e.target.closest('a[href]') : null;
            if (!enlace) return;
            const href = enlace.getAttribute('href') || '';
            if (href && !href.startsWith('index.php') && !href.startsWith('?') && !href.startsWith('#')) {
                try {
                    sessionStorage.removeItem(STORAGE_FULLSCREEN_KEY);
                } catch (_) {}
            }
        });

        try {
            if (sessionStorage.getItem(STORAGE_FULLSCREEN_KEY) === '1') {
                establecerPantallaCompletaTabla(true);
            }
        } catch (_) {}
    }
}

window.iniciarIndex = iniciarIndex;


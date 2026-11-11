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

// Cerrar menús con la tecla Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.menu-filtro').forEach(function(m) {
            m.classList.remove('menu-abierto');
            const th = m.closest('.th-filtrable');
            if (th) th.classList.remove('th-menu-activo');
        });
    }
});

/**
 * Inicializa los eventos, modales y búsqueda de index.php.
 *
 * @param {Object} [config]
 * @param {string|null} [config.mensajeExito]
 * @param {string|null} [config.error]
 * @param {boolean} [config.hayFiltrosActivos]
 * @param {number} [config.totalRegistros]
 */
function iniciarIndex(config = {}) {
    // 1. Mostrar modales para mensajes de sesión o URL (sin alertas estáticas en HTML)
    if (config.mensajeExito) {
        Swal.fire({
            icon: 'success',
            title: '¡Operación Exitosa!',
            html: config.mensajeExito,
            timer: 3500,
            timerProgressBar: true,
            confirmButtonColor: '#2563eb',
            confirmButtonText: 'Aceptar'
        });
        if (window.history.replaceState) {
            const url = new URL(window.location);
            url.searchParams.delete('ok');
            url.searchParams.delete('error');
            window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : ''));
        }
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
                                Swal.fire({
                                    icon: 'success',
                                    title: '¡Alertas procesadas con éxito!',
                                    confirmButtonColor: '#059669',
                                    confirmButtonText: 'Aceptar'
                                }).then(() => {
                                    window.location.reload();
                                });
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

    // 3. Control animado para botones "Desactivar" y "Reactivar"
    document.querySelectorAll('.form-eliminar').forEach(function(form) {
        form.addEventListener('submit', function(e) {
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
                detalleArticulo += `<div style="color: #1e293b; font-size: 14px; font-weight: 600; margin-top: 6px; line-height: 1.35;">${escapeHtml(artNombre)}</div>`;
            }

            if (categoria || famNombre || tfaNombre) {
                detalleArticulo += `
                    <div style="margin-top: 12px; padding: 10px 14px; background: #f8fafc; border: 1px solid var(--color-borde, #e2e8f0); border-radius: 8px; font-size: 12.5px; text-align: left; display: inline-flex; flex-direction: column; gap: 4px; min-width: 260px; max-width: 100%; box-sizing: border-box;">
                        <div style="display: flex; gap: 8px;"><span style="color: var(--color-texto-muted, #64748b); font-weight: 600; min-width: 82px;">Categoría:</span> <strong style="color: var(--color-texto, #0f172a);">${escapeHtml(categoria || '—')}</strong></div>
                        <div style="display: flex; gap: 8px;"><span style="color: var(--color-texto-muted, #64748b); font-weight: 600; min-width: 82px;">Familia:</span> <strong style="color: var(--color-texto, #0f172a);">${escapeHtml(famNombre || '—')}</strong></div>
                        <div style="display: flex; gap: 8px;"><span style="color: var(--color-texto-muted, #64748b); font-weight: 600; min-width: 82px;">Tipo familia:</span> <strong style="color: var(--color-texto, #0f172a);">${escapeHtml(tfaNombre || '—')}</strong></div>
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
    });

    // 4. Búsqueda instantánea en tiempo real sobre la tabla de artículos
    const inputArticulo = document.getElementById('articulo');
    const formBusqueda = document.querySelector('.busqueda-form');
    const tablaCuerpo = document.querySelector('.tabla-contenedor tbody');
    const parrafoResultados = document.getElementById('busqueda-resultados');

    if (inputArticulo && tablaCuerpo) {
        if (formBusqueda) {
            formBusqueda.addEventListener('submit', function(e) {
                e.preventDefault();
            });
        }

        const filas = Array.from(tablaCuerpo.querySelectorAll('tr')).filter(tr => {
            return !tr.querySelector('td[colspan]');
        });

        let filaSinResultados = document.getElementById('fila-sin-resultados');
        if (!filaSinResultados) {
            filaSinResultados = document.createElement('tr');
            filaSinResultados.id = 'fila-sin-resultados';
            filaSinResultados.style.display = 'none';
            filaSinResultados.innerHTML = `
                <td colspan="11" style="text-align: center; padding: 34px 16px; color: var(--color-texto-muted);">
                    No se encontraron artículos que coincidan con la búsqueda.
                </td>
            `;
            tablaCuerpo.appendChild(filaSinResultados);
        }

        function ejecutarFiltroEnVivo() {
            const termino = inputArticulo.value.toLowerCase().trim();
            let visibles = 0;

            filas.forEach(fila => {
                const codigo = (fila.dataset.codigo || fila.querySelector('.articulo-titulo')?.textContent || '').toLowerCase().trim();
                const nombre = (fila.dataset.nombre || fila.querySelector('strong')?.textContent || '').toLowerCase().trim();

                const coincideCodigo = codigo.startsWith(termino) || codigo.replace(/^0+/, '').startsWith(termino);
                const palabrasNombre = nombre.split(/\s+/);
                const coincideNombre = nombre.startsWith(termino) || palabrasNombre.some(p => p.startsWith(termino));

                if (termino === '' || coincideCodigo || coincideNombre) {
                    fila.style.display = '';
                    visibles++;
                } else {
                    fila.style.display = 'none';
                }
            });

            if (filas.length > 0) {
                filaSinResultados.style.display = (visibles === 0 && termino !== '') ? '' : 'none';
            }

            if (parrafoResultados) {
                if (termino !== '') {
                    parrafoResultados.style.display = 'block';
                    parrafoResultados.innerHTML = `Coincidencias en tiempo real: <strong>${visibles}</strong> de <strong>${filas.length}</strong> artículos.`;
                } else {
                    if (config.hayFiltrosActivos) {
                        const total = config.totalRegistros !== undefined ? config.totalRegistros : filas.length;
                        parrafoResultados.style.display = 'block';
                        parrafoResultados.innerHTML = `Filtros activos en base de datos — <strong>${total}</strong> ${total === 1 ? 'registro encontrado' : 'registros encontrados'}. <a href="index.php" style="margin-left: 8px; color: var(--color-primario); text-decoration: underline;">Quitar todos los filtros</a>`;
                    } else {
                        parrafoResultados.style.display = 'none';
                    }
                }
            }
        }

        inputArticulo.addEventListener('input', ejecutarFiltroEnVivo);
        inputArticulo.addEventListener('search', ejecutarFiltroEnVivo);

        if (inputArticulo.value.trim() !== '') {
            ejecutarFiltroEnVivo();
        }
    }
}

window.iniciarIndex = iniciarIndex;


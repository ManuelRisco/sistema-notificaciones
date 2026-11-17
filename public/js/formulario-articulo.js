// JavaScript compartido para formularios de artículos (crear.php y editar.php)
// Conserva selectores, validaciones, autocompletado y catálogo interactivo.

let consultaArticuloAbortController = null;

/**
 * Renderiza en el DOM la información de stock, clasificación y estado de un artículo.
 *
 * @param {Object|null} data Datos devueltos por consultar_articulo.php
 * @param {Object} [opciones] Configuración opcional
 */
function renderInfoArticulo(data, opciones = {}) {
  const infoContainer = document.getElementById("info_articulo");
  const sufijoUme = document.getElementById("sufijo-unidad-minimo");

  const inputCat = document.getElementById("art_categoria");
  const inputFam = document.getElementById("art_familia");
  const inputTfa = document.getElementById("art_tipo_familia");

  if (!data || !infoContainer) {
    if (infoContainer) infoContainer.innerHTML = "";
    if (sufijoUme) sufijoUme.textContent = "";
    if (inputCat) inputCat.value = "";
    if (inputFam) inputFam.value = "";
    if (inputTfa) inputTfa.value = "";
    return;
  }

  if (!data.encontrado) {
    infoContainer.innerHTML =
      '<span class="badge badge-alerta badge-sm">No encontrado</span>' +
      (opciones.modo === "editar"
        ? ' <span class="alerta-articulo-error">Este artículo no existe en el catálogo maestro.</span>'
        : "");
    if (sufijoUme) sufijoUme.textContent = "";
    if (inputCat) inputCat.value = "";
    if (inputFam) inputFam.value = "";
    if (inputTfa) inputTfa.value = "";
    return;
  }

  if (inputCat) inputCat.value = data.categoria || "";
  if (inputFam) inputFam.value = data.fam_nombre || "";
  if (inputTfa) inputTfa.value = data.tfa_nombre || "";

  const umeTexto = data.unidad_medida || "";
  if (sufijoUme) sufijoUme.textContent = umeTexto;

  const nombreHtml = `<span class="articulo-nombre-destacado">${escapeHtml(data.art_nombre || "")}</span>`;
  const stockTexto =
    data.stock_actual_formateado ||
    (data.stock_actual !== null && data.stock_actual !== undefined
      ? `${data.stock_actual} ${data.unidad_medida || ""}`
      : "0.000");
  const stockHtml = `<span class="articulo-stock-tag">Stock real: <strong>${escapeHtml(stockTexto)}</strong></span>`;

  let estadoBadge = "";
  if (
    opciones.modo === "editar" &&
    opciones.codigoOriginal &&
    data.art_codigo === opciones.codigoOriginal
  ) {
    estadoBadge =
      '<span class="badge badge-activo badge-sm">En uso actualmente</span>';
  } else if (data.registrado) {
    if (data.disponible === 1) {
      estadoBadge =
        '<span class="badge badge-alerta badge-sm">Ya está registrado</span>';
    } else {
      const txt =
        opciones.modo === "crear"
          ? "Inactivo (se reactivará)"
          : "Ya registrado (Inactivo)";
      estadoBadge = `<span class="badge badge-inactivo badge-sm">${txt}</span>`;
    }
  }

  infoContainer.innerHTML = `
        <div class="info-articulo-fila">
            ${estadoBadge}
            ${nombreHtml}
            ${stockHtml}
        </div>
    `;
}

/**
 * Consulta un artículo al backend (consultar_articulo.php) y actualiza la vista.
 *
 * @param {string} codigo Código de artículo
 * @param {string} almCodigo Código del almacén activo
 * @param {Object} [opciones]
 * @returns {Promise<Object|null>}
 */
function consultarArticulo(codigo, almCodigo, opciones = {}) {
  codigo = (codigo || "").trim();
  const infoContainer = document.getElementById("info_articulo");
  const artInput = document.getElementById("art_codigo");

  if (!codigo) {
    if (consultaArticuloAbortController) {
      consultaArticuloAbortController.abort();
      consultaArticuloAbortController = null;
    }
    renderInfoArticulo(null, opciones);
    if (opciones.onData) opciones.onData(null);
    return Promise.resolve(null);
  }

  if (infoContainer) {
    infoContainer.innerHTML =
      '<span class="texto-buscando">Buscando artículo...</span>';
  }

  if (consultaArticuloAbortController) {
    consultaArticuloAbortController.abort();
  }
  consultaArticuloAbortController = new AbortController();

  const url = `consultar_articulo.php?codigo=${encodeURIComponent(codigo)}&alm=${encodeURIComponent(almCodigo || "")}`;

  return fetch(url, { signal: consultaArticuloAbortController.signal })
    .then((res) => res.json())
    .then((data) => {
      if (artInput && artInput.value.trim() !== codigo) {
        return null;
      }
      renderInfoArticulo(data, opciones);
      if (opciones.onData) opciones.onData(data);
      return data;
    })
    .catch((err) => {
      if (err && err.name === "AbortError") {
        return null;
      }
      if (infoContainer) infoContainer.innerHTML = "";
      if (opciones.onData) opciones.onData(null);
      return null;
    });
}

/**
 * Abre el modal interactivo de catálogo de artículos con búsqueda en BD (debounce 300ms),
 * filtro de artículos únicamente con stock, ordenamiento y paginación.
 *
 * @param {string} almCodigo Código del almacén
 * @param {string} almNombre Nombre descriptivo del almacén
 */
function mostrarModalCatalogo(almCodigo, almNombre) {
  if (typeof Swal === "undefined") return;

  let paginaActual = 1;
  let porPaginaActual = 10;
  let soloConStock = false;
  let ordenStock = "defecto";
  let busquedaConsultada = "";
  let timerBusqueda = null;
  let abortController = null;
  let itemsPaginaActual = [];

  Swal.fire({
    title: `Catálogo de Artículos (Almacén ${escapeHtml(almCodigo)} - ${escapeHtml(almNombre)})`,
    html: `
            <div class="catalogo-modal">
                <p class="catalogo-subtitulo">
                    Buscar artículo por código o nombre
                </p>
                <div class="catalogo-controles">
                    <input type="search" id="swal-buscador-catalogo" class="swal2-input catalogo-buscador" placeholder="020100149 | AMARILLO HUEVO" autocomplete="off">
                    <button type="button" id="swal-btn-solo-stock" class="btn btn-secundario catalogo-btn-solo-stock" aria-pressed="false" title="Mostrar únicamente artículos con stock físico mayor a cero">
                        Solo con stock
                    </button>
                    <div class="catalogo-orden">
                        <label for="swal-orden-stock" class="catalogo-orden-label">Ordenar:</label>
                        <select id="swal-orden-stock" class="catalogo-orden-select">
                            <option value="defecto">Por defecto (Código)</option>
                            <option value="desc">Stock: mayor a menor (↓)</option>
                            <option value="asc">Stock: menor a mayor (↑)</option>
                        </select>
                    </div>
                </div>
                <div id="swal-lista-catalogo" class="catalogo-lista">
                    <div class="catalogo-cargando">
                        Cargando artículos...
                    </div>
                </div>
                <div id="swal-paginacion-catalogo"></div>
            </div>
        `,
    width: "min(1160px, 96vw)",
    customClass: { popup: "swal-catalogo-ancho" },
    showConfirmButton: false,
    showCloseButton: true,
    didClose: () => {
      clearTimeout(timerBusqueda);
      if (abortController) {
        abortController.abort();
        abortController = null;
      }
    },
    didOpen: () => {
      const buscador = document.getElementById("swal-buscador-catalogo");
      const btnSoloStock = document.getElementById("swal-btn-solo-stock");
      const lista = document.getElementById("swal-lista-catalogo");
      const paginacionContenedor = document.getElementById("swal-paginacion-catalogo");
      const selectorOrden = document.getElementById("swal-orden-stock");

      function renderizarTabla(items) {
        itemsPaginaActual = Array.isArray(items) ? items : [];

        if (itemsPaginaActual.length === 0) {
          const tieneFiltros = soloConStock || (buscador && buscador.value.trim() !== "");
          const mensajeVacio = tieneFiltros
            ? "No se encontraron artículos que coincidan con los filtros aplicados."
            : "No hay artículos pendientes de configurar en este almacén.";
          lista.innerHTML = `<div class="catalogo-vacio">${mensajeVacio}</div>`;
          return;
        }

        const codigoActualEnFormulario = (
          document.getElementById("art_codigo")?.value || ""
        ).trim();

        let html = '<table class="catalogo-tabla">';
        html += `
                    <thead>
                        <tr>
                            <th class="catalogo-th-codigo">Código</th>
                            <th>Artículo</th>
                            <th class="catalogo-th-clasificacion">Clasificación</th>
                            <th class="catalogo-th-stock">
                                <span class="catalogo-th-stock-wrap">
                                    <span>Stock real</span>
                                    <button type="button" id="th-sort-stock-modal" class="catalogo-btn-orden-stock" title="Ordenar por stock real">
                                        <svg class="icono-filtro" viewBox="0 0 16 16" width="12" height="12" fill="currentColor">
                                            <path d="M1.5 2.5a.5.5 0 0 1 .5-.5h12a.5.5 0 0 1 .38.82L9 8.24V13.5a.5.5 0 0 1-.76.42l-2-1.25A.5.5 0 0 1 6 12.25V8.24L1.62 2.82a.5.5 0 0 1-.12-.32z"></path>
                                        </svg>
                                    </button>
                                </span>
                            </th>
                            <th class="catalogo-th-accion">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                `;

        itemsPaginaActual.forEach((art) => {
          const esEnUso =
            codigoActualEnFormulario !== "" &&
            art.codigo === codigoActualEnFormulario;

          html += `
                        <tr class="fila-catalogo-modal">
                            <td class="catalogo-celda-codigo">
                                ${escapeHtml(art.codigo)}
                            </td>
                            <td class="catalogo-celda-nombre">
                                ${escapeHtml(art.nombre)}
                            </td>
                            <td class="catalogo-celda-clasificacion">
                                <div class="catalogo-meta">
                                    <div class="catalogo-meta-fila"><span class="catalogo-meta-clave">Categoría:</span> <strong class="catalogo-meta-valor">${escapeHtml(art.categoria || "—")}</strong></div>
                                    <div class="catalogo-meta-fila"><span class="catalogo-meta-clave">Familia:</span> <strong class="catalogo-meta-valor">${escapeHtml(art.fam_nombre || "—")}</strong></div>
                                    <div class="catalogo-meta-fila"><span class="catalogo-meta-clave">Tipo fam:</span> <strong class="catalogo-meta-valor">${escapeHtml(art.tfa_nombre || "—")}</strong></div>
                                </div>
                            </td>
                            <td class="catalogo-celda-stock">
                                <span class="articulo-stock-tag">
                                    ${escapeHtml(art.stock_formateado || "0.000")}
                                </span>
                            </td>
                            <td class="catalogo-celda-accion">
                                ${
                                  esEnUso
                                    ? `<span class="badge badge-activo badge-sm">En uso</span>`
                                    : `<button type="button" class="btn btn-primario btn-elegir-art" data-codigo="${escapeHtml(art.codigo)}">Elegir</button>`
                                }
                            </td>
                        </tr>
                    `;
        });

        html += "</tbody></table>";
        lista.innerHTML = html;
      }

      function cargarCatalogo(opciones = {}) {
        const termino = buscador ? buscador.value.trim() : "";

        if (abortController) {
          abortController.abort();
        }
        abortController = new AbortController();

        if (!lista.querySelector("table")) {
          lista.innerHTML =
            '<div class="catalogo-cargando">Cargando artículos...</div>';
        } else {
          lista.classList.add("catalogo-cargando-estado");
        }

        const params = new URLSearchParams({
          catalogo: "1",
          alm: almCodigo || "",
          q: termino,
          solo_stock: soloConStock ? "1" : "0",
          orden: ordenStock,
          pagina: String(paginaActual),
          por_pagina: String(porPaginaActual),
        });

        fetch(`consultar_articulo.php?${params.toString()}`, {
          signal: abortController.signal,
        })
          .then((r) => r.json())
          .then((res) => {
            if (!res || !res.exito || !Array.isArray(res.articulos)) {
              throw new Error(
                (res && res.error) || "No se pudo cargar el catálogo.",
              );
            }

            busquedaConsultada = termino;
            paginaActual = Number(res.pagina) || 1;
            porPaginaActual = Number(res.por_pagina) || porPaginaActual;

            renderizarTabla(res.articulos);

            if (paginacionContenedor && typeof construirHtmlPaginacion === "function") {
              paginacionContenedor.innerHTML = construirHtmlPaginacion({
                pagina: paginaActual,
                totalPaginas: Number(res.total_paginas) || 1,
                total: Number(res.total) || 0,
                porPagina: porPaginaActual,
                etiquetaItem: "artículos",
                sufijoExtra: soloConStock ? "(solo con stock)" : "",
              });
            }

            if (opciones.scrollArriba) {
              lista.scrollTop = 0;
            }
          })
          .catch((err) => {
            if (err && err.name === "AbortError") {
              return;
            }
            lista.innerHTML = `<div class="catalogo-error">Error: ${escapeHtml((err && err.message) || "No se pudo cargar el catálogo.")}</div>`;
            if (paginacionContenedor) {
              paginacionContenedor.innerHTML = "";
            }
          })
          .finally(() => {
            lista.classList.remove("catalogo-cargando-estado");
          });
      }

      lista.addEventListener("click", function (e) {
        const thSort = e.target.closest ? e.target.closest("#th-sort-stock-modal") : null;
        if (thSort) {
          if (ordenStock === "defecto") {
            ordenStock = "desc";
          } else if (ordenStock === "desc") {
            ordenStock = "asc";
          } else {
            ordenStock = "defecto";
          }
          if (selectorOrden) selectorOrden.value = ordenStock;
          paginaActual = 1;
          cargarCatalogo({ scrollArriba: true });
          return;
        }

        const btnElegir = e.target.closest ? e.target.closest(".btn-elegir-art") : null;
        if (btnElegir) {
          const cod = btnElegir.getAttribute("data-codigo");
          const inp = document.getElementById("art_codigo");
          const artObj = itemsPaginaActual.find((x) => x.codigo === cod) || null;
          if (artObj) {
            const inputCat = document.getElementById("art_categoria");
            const inputFam = document.getElementById("art_familia");
            const inputTfa = document.getElementById("art_tipo_familia");
            if (inputCat) inputCat.value = artObj.categoria || "";
            if (inputFam) inputFam.value = artObj.fam_nombre || "";
            if (inputTfa) inputTfa.value = artObj.tfa_nombre || "";
          }
          if (inp) {
            inp.value = cod;
            inp.dispatchEvent(new Event("input", { bubbles: true }));
            const inpStock = document.getElementById("stock_minimo");
            if (inpStock) inpStock.focus();
          }
          Swal.close();
        }
      });

      if (paginacionContenedor) {
        paginacionContenedor.addEventListener("click", function (e) {
          const btn = e.target.closest ? e.target.closest(".js-paginacion-btn[data-pagina]") : null;
          if (!btn || btn.disabled) return;
          paginaActual = Number(btn.dataset.pagina) || 1;
          cargarCatalogo({ scrollArriba: true });
        });

        paginacionContenedor.addEventListener("change", function (e) {
          if (e.target && e.target.classList.contains("js-paginacion-tamano")) {
            porPaginaActual = Number(e.target.value) || 10;
            paginaActual = 1;
            cargarCatalogo({ scrollArriba: true });
          }
        });
      }

      if (btnSoloStock) {
        btnSoloStock.addEventListener("click", function () {
          soloConStock = !soloConStock;
          btnSoloStock.classList.toggle("activo", soloConStock);
          btnSoloStock.setAttribute("aria-pressed", soloConStock ? "true" : "false");
          btnSoloStock.textContent = soloConStock ? "✓ Solo con stock" : "Solo con stock";
          paginaActual = 1;
          cargarCatalogo({ scrollArriba: true });
        });
      }

      if (selectorOrden) {
        selectorOrden.value = ordenStock;
        selectorOrden.addEventListener("change", function () {
          ordenStock = this.value;
          paginaActual = 1;
          cargarCatalogo({ scrollArriba: true });
        });
      }

      if (buscador) {
        buscador.addEventListener("input", function () {
          clearTimeout(timerBusqueda);
          timerBusqueda = setTimeout(() => {
            const nuevoQ = buscador.value.trim();
            if (nuevoQ === busquedaConsultada) {
              return;
            }
            paginaActual = 1;
            cargarCatalogo({ scrollArriba: true });
          }, 300);
        });

        buscador.addEventListener("search", function () {
          clearTimeout(timerBusqueda);
          const nuevoQ = buscador.value.trim();
          if (nuevoQ !== busquedaConsultada) {
            paginaActual = 1;
            cargarCatalogo({ scrollArriba: true });
          }
        });

        buscador.addEventListener("keydown", function (e) {
          if (e.key === "Enter") {
            e.preventDefault();
            clearTimeout(timerBusqueda);
            const nuevoQ = buscador.value.trim();
            if (nuevoQ !== busquedaConsultada) {
              paginaActual = 1;
              cargarCatalogo({ scrollArriba: true });
            }
          }
        });

        setTimeout(() => buscador.focus(), 80);
      }

      cargarCatalogo();
    },
  });
}

/**
 * Inicializa el comportamiento de un formulario de artículo (crear o editar).
 *
 * @param {Object} config
 * @param {'crear'|'editar'} config.modo
 * @param {string} config.almCodigo Código del almacén actual
 * @param {string} [config.almNombre] Nombre descriptivo del almacén
 * @param {string} [config.artOriginal] Código original del artículo (en edición)
 * @param {string} [config.artNombre] Nombre original del artículo (en edición)
 */
function iniciarFormularioArticulo(config) {
  const modo = config.modo || "crear";
  const almCodigo = config.almCodigo || "";
  const almNombre = config.almNombre || "";
  const artOriginal = config.artOriginal || "";
  const nombreOriginal = config.artNombre || "";

  // Si la página tiene botón para el modal de catálogo, vincular función global
  window.abrirModalCatalogo = function () {
    mostrarModalCatalogo(almCodigo, almNombre);
  };

  const form = document.querySelector(".formulario");
  const artInput = document.getElementById("art_codigo");
  let debounceTimer = null;
  let currentArtData = null;

  if (artInput && modo === "crear") {
    artInput.addEventListener("input", function () {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(() => {
        consultarArticulo(artInput.value, almCodigo, {
          modo,
          codigoOriginal: artOriginal,
          onData: (d) => {
            currentArtData = d;
          },
        });
      }, 300);
    });

    if (artInput.value.trim() !== "") {
      consultarArticulo(artInput.value, almCodigo, {
        modo,
        codigoOriginal: artOriginal,
        onData: (d) => {
          currentArtData = d;
        },
      });
    }
  }

  if (!form) return;

  let confirmado = false;

  form.addEventListener("submit", function (e) {
    if (confirmado) return;

    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    e.preventDefault();

    const artCodigo = artInput ? artInput.value.trim() : "";

    if (modo === "editar") {
      // En modo edición: el artículo es readonly y su nombre original ya vino renderizado
      if (nombreOriginal === "") {
        swalEntendido(
          "error",
          "Artículo no encontrado",
          `El artículo <strong>${escapeHtml(artCodigo)}</strong> no existe en el catálogo maestro.<br><br>No se puede asociar una alerta a un artículo inexistente.`,
        );
        return;
      }

      const detalleArticulo = `<br><span class="swal-articulo-subtitulo">${escapeHtml(nombreOriginal)}</span>`;

      Swal.fire({
        title: "¿Guardar cambios?",
        html: `¿Estás seguro de que deseas actualizar la configuración para el artículo <strong>${escapeHtml(artCodigo)}</strong>?${detalleArticulo}`,
        icon: "question",
        showCancelButton: true,
        confirmButtonColor: "#2563eb",
        cancelButtonColor: "#64748b",
        confirmButtonText: "Sí, guardar cambios",
        cancelButtonText: "Cancelar",
        reverseButtons: true,
        focusCancel: true,
      }).then((result) => {
        if (result.isConfirmed) {
          confirmado = true;
          mostrarGuardando(
            "Actualizando registro...",
            "Guardando los cambios en la base de datos.",
          );
          form.submit();
        }
      });
      return;
    }

    // Modo CREAR
    const procesarConfirmacion = (data) => {
      if (
        !data ||
        typeof data !== "object" ||
        typeof data.encontrado !== "boolean"
      ) {
        Swal.fire({
          icon: "error",
          title: "No se pudo consultar el artículo",
          text: "No se recibió una respuesta válida del servidor. Intenta nuevamente.",
          confirmButtonText: "Entendido",
        });
        return;
      }

      const artNombre =
        data.encontrado && data.art_nombre ? data.art_nombre : "";

      const estaRegistrado = data.registrado;
      const disponible = data.disponible;
      const noEncontrado = !data.encontrado;

      // 1. Ya registrado y activo en cfg_alerta_stock
      if (estaRegistrado && disponible === 1) {
        Swal.fire({
          icon: "warning",
          title: "Artículo ya registrado",
          html: `El artículo <strong>${escapeHtml(artCodigo)}</strong>${artNombre ? `<br><small class="swal-articulo-subtitulo">${escapeHtml(artNombre)}</small>` : ""}<br><br><span class="swal-articulo-alerta">Ya cuenta con una configuración activa registrada.</span>`,
          confirmButtonColor: "#dc2626",
          confirmButtonText: "Entendido",
        });
        return;
      }

      // 2. Ya registrado pero inactivo (reactivación o mantener inactivo)
      if (estaRegistrado && disponible === 0) {
        const dispSelect = document.getElementById("disponible");
        const seraActivo = dispSelect ? dispSelect.value === "1" : true;
        const accionTexto = seraActivo
          ? "reactivarlo como <strong>activo</strong>"
          : "actualizarlo manteniéndolo como <strong>inactivo</strong>";

        Swal.fire({
          title: seraActivo
            ? "¿Reactivar configuración?"
            : "¿Actualizar configuración?",
          html: `El artículo <strong>${escapeHtml(artCodigo)}</strong>${artNombre ? `<br><small class="swal-articulo-subtitulo">${escapeHtml(artNombre)}</small>` : ""}<br><br>Ya se encuentra registrado pero está <strong>inactivo</strong>.<br>¿Deseas ${accionTexto} con el nuevo stock mínimo?`,
          icon: "question",
          showCancelButton: true,
          confirmButtonColor: "#059669",
          cancelButtonColor: "#64748b",
          confirmButtonText: seraActivo ? "Sí, reactivar" : "Sí, guardar",
          cancelButtonText: "Cancelar",
          reverseButtons: true,
          focusCancel: true,
        }).then((result) => {
          if (result.isConfirmed) {
            ejecutarEnvio();
          }
        });
        return;
      }

      // 3. No encontrado en el catálogo maestro
      if (noEncontrado) {
        Swal.fire({
          icon: "error",
          title: "Artículo no encontrado",
          html: `El artículo <strong>${escapeHtml(artCodigo)}</strong> no existe en el catálogo.<br><br>No se puede registrar una configuración de stock mínimo para un artículo inexistente.<br>Utiliza el botón <strong>"Buscar en catálogo"</strong> para seleccionar un artículo válido.`,
          confirmButtonColor: "#dc2626",
          confirmButtonText: "Entendido",
        });
        return;
      }

      // 4. Caso normal: encontrado y no registrado
      const detalleArticulo = artNombre
        ? `<span class="swal-articulo-destacado">${escapeHtml(artNombre)}</span>`
        : "";

      Swal.fire({
        title: "¿Guardar nuevo registro?",
        html: `¿Estás seguro de que deseas registrar esta configuración para el artículo <strong>${detalleArticulo}<br>${escapeHtml(artCodigo)}</strong>?`,
        icon: "question",
        showCancelButton: true,
        confirmButtonColor: "#2563eb",
        cancelButtonColor: "#64748b",
        confirmButtonText: "Sí, guardar",
        cancelButtonText: "Cancelar",
        reverseButtons: true,
        focusCancel: true,
      }).then((result) => {
        if (result.isConfirmed) {
          ejecutarEnvio();
        }
      });
    };

    function ejecutarEnvio() {
      confirmado = true;
      mostrarGuardando(
        "Guardando registro...",
        "Registrando artículo y stock mínimo en la base de datos.",
      );
      form.submit();
    }

    if (currentArtData && currentArtData.art_codigo === artCodigo) {
      procesarConfirmacion(currentArtData);
    } else {
      consultarArticulo(artCodigo, almCodigo, {
        modo,
        codigoOriginal: artOriginal,
      }).then((data) => {
        currentArtData = data;
        procesarConfirmacion(data);
      });
    }
  });
}

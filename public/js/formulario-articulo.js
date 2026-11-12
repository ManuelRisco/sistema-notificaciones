// JavaScript compartido para formularios de artículos (crear.php y editar.php)
// Conserva selectores, validaciones, autocompletado y catálogo interactivo.

let catalogoCache = null;
let ordenStockModal = "defecto";

/**
 * Renderiza en el DOM la información de stock, clasificación y estado de un artículo.
 *
 * @param {Object|null} data Datos devueltos por consultar_articulo.php
 * @param {Object} [opciones] Configuración opcional
 */
function renderInfoArticulo(data, opciones = {}) {
  const infoContainer = document.getElementById("info_articulo");
  const sufijoUme = document.getElementById("sufijo-unidad-minimo");
  const labelUme = document.getElementById("label-unidad-minimo");

  const inputCat = document.getElementById("art_categoria");
  const inputFam = document.getElementById("art_familia");
  const inputTfa = document.getElementById("art_tipo_familia");

  if (!data || !infoContainer) {
    if (infoContainer) infoContainer.innerHTML = "";
    if (sufijoUme) sufijoUme.textContent = "";
    if (labelUme) labelUme.textContent = "";
    if (inputCat) inputCat.value = "";
    if (inputFam) inputFam.value = "";
    if (inputTfa) inputTfa.value = "";
    return;
  }

  if (!data.encontrado) {
    infoContainer.innerHTML =
      '<span class="badge badge-alerta" style="display: inline-block; font-size: 11px;">No encontrado</span>' +
      (opciones.modo === "editar"
        ? ' <span style="display: block; margin-top: 4px; font-size: 12px; color: #dc2626;">Este artículo no existe en el catálogo maestro.</span>'
        : "");
    if (sufijoUme) sufijoUme.textContent = "";
    if (labelUme) labelUme.textContent = "";
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
  if (labelUme) labelUme.textContent = umeTexto ? `(${umeTexto})` : "";

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
      '<span class="badge badge-activo" style="font-size: 11px;">En uso actualmente</span>';
  } else if (data.registrado) {
    if (data.disponible === 1) {
      estadoBadge =
        '<span class="badge badge-alerta" style="font-size: 11px;">Ya está registrado</span>';
    } else {
      const txt =
        opciones.modo === "crear"
          ? "Inactivo (se reactivará)"
          : "Ya registrado (Inactivo)";
      estadoBadge = `<span class="badge badge-inactivo" style="font-size: 11px;">${txt}</span>`;
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
    renderInfoArticulo(null, opciones);
    if (opciones.onData) opciones.onData(null);
    return Promise.resolve(null);
  }

  if (infoContainer) {
    infoContainer.innerHTML =
      '<span style="color: #64748b; font-size: 13px;">Buscando artículo...</span>';
  }

  const url = `consultar_articulo.php?codigo=${encodeURIComponent(codigo)}&alm=${encodeURIComponent(almCodigo || "")}`;

  return fetch(url)
    .then((res) => res.json())
    .then((data) => {
      if (artInput && artInput.value.trim() !== codigo) {
        return null;
      }
      renderInfoArticulo(data, opciones);
      if (opciones.onData) opciones.onData(data);
      return data;
    })
    .catch(() => {
      if (infoContainer) infoContainer.innerHTML = "";
      if (opciones.onData) opciones.onData(null);
      return null;
    });
}

/**
 * Abre el modal interactivo de catálogo de artículos con búsqueda y ordenamiento en tiempo real.
 *
 * @param {string} almCodigo Código del almacén
 * @param {string} almNombre Nombre descriptivo del almacén
 */
function mostrarModalCatalogo(almCodigo, almNombre) {
  if (typeof Swal === "undefined") return;

  Swal.fire({
    title: `Catálogo de Artículos (Almacén ${escapeHtml(almCodigo)} - ${escapeHtml(almNombre)})`,
    html: `
            <div style="text-align: left; font-size: 13px;">
                <p style="color: var(--color-texto-muted); margin-bottom: 12px;">
                    Buscar artículo por código o nombre
                </p>
                <div style="margin-bottom: 12px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <input type="search" id="swal-buscador-catalogo" class="swal2-input" placeholder="020100149 | AMARILLO HUEVO" style="flex: 1; min-width: 240px; margin: 0; font-size: 13.5px; height: 40px; box-sizing: border-box;" autocomplete="off">
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <label for="swal-orden-stock" style="font-weight: 600; font-size: 12px; color: var(--color-texto-muted); white-space: nowrap;">Ordenar:</label>
                        <select id="swal-orden-stock" style="height: 40px; padding: 0 10px; border: 1.5px solid var(--color-borde-campo); border-radius: var(--radio-control); font-size: 12.5px; font-weight: 600; color: var(--color-texto); background-color: var(--color-tarjeta); cursor: pointer;">
                            <option value="defecto">Por defecto (Código)</option>
                            <option value="desc">Stock: mayor a menor (↓)</option>
                            <option value="asc">Stock: menor a mayor (↑)</option>
                        </select>
                    </div>
                </div>
                <div id="swal-lista-catalogo" style="max-height: 400px; overflow-y: auto; overflow-x: hidden; border: 1px solid var(--color-borde); border-radius: 8px; background: var(--color-tarjeta);">
                    <div style="padding: 30px; text-align: center; color: var(--color-texto-muted);">
                        Cargando artículos...
                    </div>
                </div>
                <div id="swal-contador-catalogo" style="margin-top: 8px; font-size: 12px; color: var(--color-texto-muted); text-align: right;"></div>
            </div>
        `,
    width: "min(1160px, 96vw)",
    customClass: { popup: "swal-catalogo-ancho" },
    showConfirmButton: false,
    showCloseButton: true,
    didOpen: () => {
      const buscador = document.getElementById("swal-buscador-catalogo");
      const lista = document.getElementById("swal-lista-catalogo");
      const contador = document.getElementById("swal-contador-catalogo");
      const selectorOrden = document.getElementById("swal-orden-stock");

      function obtenerItemsOrdenados() {
        if (!catalogoCache) return [];
        const copia = [...catalogoCache];
        if (ordenStockModal === "desc") {
          copia.sort((a, b) => {
            const valA =
              a.stock_actual !== null && a.stock_actual !== undefined
                ? Number(a.stock_actual)
                : -Infinity;
            const valB =
              b.stock_actual !== null && b.stock_actual !== undefined
                ? Number(b.stock_actual)
                : -Infinity;
            if (valB !== valA) return valB - valA;
            return (a.codigo || "").localeCompare(b.codigo || "");
          });
        } else if (ordenStockModal === "asc") {
          copia.sort((a, b) => {
            const valA =
              a.stock_actual !== null && a.stock_actual !== undefined
                ? Number(a.stock_actual)
                : Infinity;
            const valB =
              b.stock_actual !== null && b.stock_actual !== undefined
                ? Number(b.stock_actual)
                : Infinity;
            if (valA !== valB) return valA - valB;
            return (a.codigo || "").localeCompare(b.codigo || "");
          });
        }
        return copia;
      }

      function renderizar(items) {
        if (!items || items.length === 0) {
          lista.innerHTML = `
                        <div style="padding: 36px 16px; text-align: center; color: var(--color-texto-muted); font-size: 13px;">
                            No hay artículos pendientes de configurar en este almacén.
                        </div>
                    `;
          if (contador) contador.textContent = "0 artículos disponibles";
          return;
        }

        const codigoActualEnFormulario = (
          document.getElementById("art_codigo")?.value || ""
        ).trim();

        let html =
          '<table style="width: 100%; table-layout: fixed; border-collapse: collapse; font-size: 12.5px; text-align: left;">';
        html += `
                    <thead>
                        <tr style="background: var(--color-secundario-fondo); position: sticky; top: 0; z-index: 2; border-bottom: 1.5px solid var(--color-borde);">
                            <th style="padding: 8px 6px; width: 95px; font-size: 11px; text-transform: uppercase;">Código</th>
                            <th style="padding: 8px 6px; font-size: 11px; text-transform: uppercase;">Artículo</th>
                            <th style="padding: 8px 6px; width: 230px; font-size: 11px; text-transform: uppercase;">Clasificación</th>
                            <th style="padding: 8px 6px; width: 125px; font-size: 11px; text-transform: uppercase; text-align: right; user-select: none;">
                                <span style="display: inline-flex; align-items: center; justify-content: flex-end; gap: 4px;">
                                    <span>Stock real</span>
                                    <button type="button" id="th-sort-stock-modal" style="background:none;border:none;cursor:pointer;padding:0;color:inherit;">
                                        <svg class="icono-filtro" viewBox="0 0 16 16" width="12" height="12" fill="currentColor">
                                            <path d="M1.5 2.5a.5.5 0 0 1 .5-.5h12a.5.5 0 0 1 .38.82L9 8.24V13.5a.5.5 0 0 1-.76.42l-2-1.25A.5.5 0 0 1 6 12.25V8.24L1.62 2.82a.5.5 0 0 1-.12-.32z"></path>
                                        </svg>
                                    </button>
                                </span>
                            </th>
                            <th style="padding: 8px 6px; width: 95px; font-size: 11px; text-transform: uppercase; text-align: center;">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                `;

        items.forEach((art) => {
          const metaTexto =
            `${art.categoria || ""} ${art.fam_nombre || ""} ${art.tfa_nombre || ""}`.trim();
          const esEnUso =
            codigoActualEnFormulario !== "" &&
            art.codigo === codigoActualEnFormulario;

          html += `
                        <tr class="fila-catalogo-modal" style="border-bottom: 1px solid var(--color-borde);" data-codigo="${escapeHtml(art.codigo)}" data-nombre="${escapeHtml(art.nombre)}" data-meta="${escapeHtml(metaTexto)}">
                            <td style="padding: 8px 6px; font-weight: 700; font-family: monospace; font-size: 12.5px; word-break: break-all;">
                                ${escapeHtml(art.codigo)}
                            </td>
                            <td style="padding: 8px 6px; line-height: 1.3; font-size: 12.5px; font-weight: 600; color: #1e293b; word-break: break-word;">
                                ${escapeHtml(art.nombre)}
                            </td>
                            <td style="padding: 6px; font-size: 12px; line-height: 1.35; color: #334155;">
                                <div style="display: flex; flex-direction: column; gap: 2px;">
                                    <div style="display: flex; gap: 6px;"><span style="color: #64748b; font-weight: 600; min-width: 68px; font-size: 11px;">Categoría:</span> <strong style="color: #1e293b; font-size: 11.5px; font-weight: 600;">${escapeHtml(art.categoria || "—")}</strong></div>
                                    <div style="display: flex; gap: 6px;"><span style="color: #64748b; font-weight: 600; min-width: 68px; font-size: 11px;">Familia:</span> <strong style="color: #1e293b; font-size: 11.5px; font-weight: 600;">${escapeHtml(art.fam_nombre || "—")}</strong></div>
                                    <div style="display: flex; gap: 6px;"><span style="color: #64748b; font-weight: 600; min-width: 68px; font-size: 11px;">Tipo fam:</span> <strong style="color: #1e293b; font-size: 11.5px; font-weight: 600;">${escapeHtml(art.tfa_nombre || "—")}</strong></div>
                                </div>
                            </td>
                            <td style="padding: 8px 6px; text-align: right; white-space: nowrap;">
                                <span class="articulo-stock-tag" style="font-size: 11.5px; padding: 2px 6px;">
                                    ${escapeHtml(art.stock_formateado || "0.000")}
                                </span>
                            </td>
                            <td style="padding: 8px 6px; text-align: center; white-space: nowrap;">
                                ${
                                  esEnUso
                                    ? `<span class="badge badge-activo" style="font-size: 11px; padding: 4px 6px;">En uso</span>`
                                    : `<button type="button" class="btn btn-primario btn-elegir-art" data-codigo="${escapeHtml(art.codigo)}" style="padding: 2px 10px; font-size: 11.5px; height: 26px; line-height: 26px; font-weight: 600;">Elegir</button>`
                                }
                            </td>
                        </tr>
                    `;
        });

        html += `
                    <tr id="swal-fila-vacia" style="display: none;">
                        <td colspan="5" style="padding: 30px 16px; text-align: center; color: var(--color-texto-muted);">
                            No se encontraron artículos que coincidan con la búsqueda.
                        </td>
                    </tr>
                `;

        html += "</tbody></table>";
        lista.innerHTML = html;
        if (contador)
          contador.innerHTML = `Mostrando <strong>${items.length}</strong> de <strong>${catalogoCache.length}</strong> artículos`;

        const thStock = lista.querySelector("#th-sort-stock-modal");
        if (thStock) {
          thStock.addEventListener("click", function () {
            if (ordenStockModal === "defecto") {
              ordenStockModal = "desc";
            } else if (ordenStockModal === "desc") {
              ordenStockModal = "asc";
            } else {
              ordenStockModal = "defecto";
            }
            if (selectorOrden) selectorOrden.value = ordenStockModal;
            aplicarOrdenYRenderizar();
          });
        }

        lista.querySelectorAll(".btn-elegir-art").forEach((b) => {
          b.addEventListener("click", function () {
            const cod = this.getAttribute("data-codigo");
            const inp = document.getElementById("art_codigo");
            const artObj = catalogoCache
              ? catalogoCache.find((x) => x.codigo === cod)
              : null;
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
          });
        });
      }

      function aplicarOrdenYRenderizar() {
        const ordenados = obtenerItemsOrdenados();
        renderizar(ordenados);
        filtrar();
      }

      function filtrar() {
        if (!catalogoCache) return;
        const q = (buscador ? buscador.value : "").toLowerCase().trim();
        let visibles = 0;

        const filasModal = lista.querySelectorAll(
          "tbody tr:not(#swal-fila-vacia)",
        );
        filasModal.forEach((fila) => {
          const c = (fila.dataset.codigo || "").toLowerCase().trim();
          const n = (fila.dataset.nombre || "").toLowerCase().trim();
          const m = (fila.dataset.meta || "").toLowerCase().trim();

          const coincideCodigo =
            c.startsWith(q) || c.replace(/^0+/, "").startsWith(q);
          const palabrasNombre = n.split(/\s+/);
          const coincideNombre =
            n.startsWith(q) || palabrasNombre.some((p) => p.startsWith(q));
          const coincideMeta = m.includes(q);

          if (q === "" || coincideCodigo || coincideNombre || coincideMeta) {
            fila.style.display = "";
            visibles++;
          } else {
            fila.style.display = "none";
          }
        });

        const filaVacia = document.getElementById("swal-fila-vacia");
        if (filaVacia) {
          filaVacia.style.display = visibles === 0 && q !== "" ? "" : "none";
        }

        if (contador) {
          contador.innerHTML = `Mostrando <strong>${visibles}</strong> de <strong>${catalogoCache.length}</strong> artículos`;
        }
      }

      if (selectorOrden) {
        selectorOrden.value = ordenStockModal;
        selectorOrden.addEventListener("change", function () {
          ordenStockModal = this.value;
          aplicarOrdenYRenderizar();
        });
      }

      if (buscador) {
        buscador.addEventListener("input", filtrar);
        buscador.addEventListener("search", filtrar);
        buscador.addEventListener("keydown", function (e) {
          if (e.key === "Enter") e.preventDefault();
        });
      }

      if (catalogoCache) {
        aplicarOrdenYRenderizar();
        if (buscador) setTimeout(() => buscador.focus(), 80);
        return;
      }

      fetch(
        "consultar_articulo.php?catalogo=1&alm=" +
          encodeURIComponent(almCodigo || ""),
      )
        .then((r) => r.json())
        .then((res) => {
          if (res && res.exito && Array.isArray(res.articulos)) {
            catalogoCache = res.articulos;
            aplicarOrdenYRenderizar();
            if (buscador) setTimeout(() => buscador.focus(), 80);
          } else {
            lista.innerHTML =
              '<div style="padding: 24px; text-align: center; color: #dc2626;">No se pudo cargar el catálogo.</div>';
          }
        })
        .catch((err) => {
          lista.innerHTML = `<div style="padding: 24px; text-align: center; color: #dc2626;">Error: ${escapeHtml(err.message)}</div>`;
        });
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

      const detalleArticulo = `<br><span style="color: #64748b; font-size: 13px; font-weight: 500;">${escapeHtml(nombreOriginal)}</span>`;

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
          html: `El artículo <strong>${escapeHtml(artCodigo)}</strong>${artNombre ? `<br><small style="color: #64748b; font-size: 13px;">${escapeHtml(artNombre)}</small>` : ""}<br><br><span style="color: #dc2626;">Ya cuenta con una configuración activa registrada.</span>`,
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
          html: `El artículo <strong>${escapeHtml(artCodigo)}</strong>${artNombre ? `<br><small style="color: #64748b; font-size: 13px;">${escapeHtml(artNombre)}</small>` : ""}<br><br>Ya se encuentra registrado pero está <strong>inactivo</strong>.<br>¿Deseas ${accionTexto} con el nuevo stock mínimo?`,
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
        ? `<span style="color: #000000ff; font-size: 13px; font-weight: 800;">${escapeHtml(artNombre)}</span>`
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

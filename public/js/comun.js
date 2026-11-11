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

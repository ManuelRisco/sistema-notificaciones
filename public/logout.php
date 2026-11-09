<?php

declare(strict_types=1);

const PAGINA_PUBLICA = true;

require_once __DIR__ . '/seguridad.php';

cerrarSesionUsuario();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cerrando sesión...</title>
    <script>
        try {
            localStorage.removeItem('auth_token');
            sessionStorage.removeItem('auth_token');
            localStorage.removeItem('auth_user');
        } catch (_) {}
        window.location.href = 'login.php?logout=1';
    </script>
</head>
<body>
    <noscript>
        <meta http-equiv="refresh" content="0;url=login.php?logout=1">
        <p>Sesión cerrada correctamente. <a href="login.php?logout=1">Haga clic aquí para continuar</a>.</p>
    </noscript>
</body>
</html>


<?php

declare(strict_types=1);

namespace Manue\SistemaNotificaciones;

use Dotenv\Dotenv;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use RuntimeException;

class EmailService
{
    private string $host;
    private int $port;

    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;
    private string $rutaLogoFooter;

    public function __construct()
    {
        $raizProyecto = dirname(__DIR__);

        if (!class_exists(Dotenv::class)) {
            throw new RuntimeException(
                'No está instalada la dependencia vlucas/phpdotenv. Ejecuta composer require vlucas/phpdotenv.'
            );
        }

        if (!is_file($raizProyecto . '/.env')) {
            throw new RuntimeException(
                'No se encontró el archivo .env en la raíz del proyecto.'
            );
        }

        // Cargar catálogo de almacenes desde config/almacenes.php si existe
        $archivoAlmacenes = $raizProyecto . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'almacenes.php';
        if (is_file($archivoAlmacenes)) {
            require_once $archivoAlmacenes;
        }

        // Cargar obligatoriamente la configuración desde el archivo .env.
        $dotenv = Dotenv::createImmutable($raizProyecto);
        $dotenv->load();

        $variablesRequeridas = [
            'APP_TIMEZONE',
            'SISTEMAS_MAIL_HOST',
            'SISTEMAS_MAIL_PORT',
            'SISTEMAS_MAIL_USERNAME',
            'SISTEMAS_MAIL_PASSWORD',
            'SISTEMAS_MAIL_FROM',
            'SISTEMAS_MAIL_FROM_NAME',
        ];

        // Requerir dinámicamente las variables de destinatarios para cada almacén configurado
        if (function_exists('getAlmacenesSoportados')) {
            foreach (array_keys(getAlmacenesSoportados()) as $codAlm) {
                $variablesRequeridas[] = 'SISTEMAS_MAIL_DESTINO_' . $codAlm;
            }
        }

        $dotenv->required($variablesRequeridas)->notEmpty();

        date_default_timezone_set(
            $this->obtenerVariable('APP_TIMEZONE')
        );

        $this->host = $this->obtenerVariable('SISTEMAS_MAIL_HOST');
        $this->port = (int) $this->obtenerVariable('SISTEMAS_MAIL_PORT');
        $this->username = $this->obtenerVariable('SISTEMAS_MAIL_USERNAME');
        // No aplicar trim a la contraseña: debe conservarse exactamente.
        $this->password = $this->obtenerVariable(
            'SISTEMAS_MAIL_PASSWORD',
            false
        );
        $this->fromEmail = $this->obtenerVariable('SISTEMAS_MAIL_FROM');
        $this->fromName = $this->obtenerVariable('SISTEMAS_MAIL_FROM_NAME');

        // Ruta del logo del footer (relativa a la raíz del proyecto).
        $this->rutaLogoFooter = $raizProyecto
            . DIRECTORY_SEPARATOR . 'public'
            . DIRECTORY_SEPARATOR . 'img'
            . DIRECTORY_SEPARATOR . 'tecnofilm.png';
    }

    private function obtenerVariable(
        string $nombre,
        bool $recortar = true
    ): string {
        if (!array_key_exists($nombre, $_ENV) || !is_string($_ENV[$nombre])) {
            throw new RuntimeException(
                "No se configuró la variable {$nombre} en el archivo .env."
            );
        }

        $valor = $_ENV[$nombre];

        if ($recortar && trim($valor) === '') {
            throw new RuntimeException(
                "La variable {$nombre} del archivo .env está vacía."
            );
        }

        return $recortar ? trim($valor) : $valor;
    }

    /**
     * Normaliza los destinatarios.
     * Acepta un correo, varios separados por coma o punto y coma,
     * o un arreglo de correos.
     */
    public function normalizarDestinatarios(
        string|array|null $destinatarios
    ): array {
        if ($destinatarios === null || $destinatarios === '') {
            return $this->obtenerDestinatariosPredeterminados();
        }

        if (is_string($destinatarios)) {
            $destinatarios = preg_split('/[,;]+/', $destinatarios) ?: [];
        }

        return $this->normalizarLista($destinatarios);
    }

    /**
     * Lee los destinatarios predeterminados.
     * Si no existe SISTEMAS_MAIL_DESTINO en .env, utiliza los del primer almacén configurado.
     */
    public function obtenerDestinatariosPredeterminados(): array
    {
        $cadena = $_ENV['SISTEMAS_MAIL_DESTINO'] ?? $_SERVER['SISTEMAS_MAIL_DESTINO'] ?? getenv('SISTEMAS_MAIL_DESTINO');

        if (is_string($cadena) && trim($cadena) !== '') {
            $correos = preg_split('/[,;]+/', $cadena) ?: [];
            return $this->normalizarLista($correos);
        }

        $almacenes = function_exists('getAlmacenesSoportados') ? getAlmacenesSoportados() : [];
        $primerAlmacen = !empty($almacenes) ? (string) array_key_first($almacenes) : '';

        if ($primerAlmacen !== '') {
            return $this->obtenerDestinatariosPorAlmacen($primerAlmacen);
        }

        return [];
    }

    /**
     * Lee los destinatarios configurados para un almacén específico desde .env.
     * Consulta dinámicamente SISTEMAS_MAIL_DESTINO_{CODIGO}.
     *
     * @throws RuntimeException Si falta configurar la variable específica del almacén o está vacía.
     */
    public function obtenerDestinatariosPorAlmacen(string $almCodigo): array
    {
        $almCodigo = trim($almCodigo);
        if ($almCodigo === '') {
            throw new RuntimeException('No se especificó un código de almacén para obtener los destinatarios.');
        }

        $variableAlmacen = 'SISTEMAS_MAIL_DESTINO_' . $almCodigo;
        $cadena = $_ENV[$variableAlmacen] ?? $_SERVER[$variableAlmacen] ?? getenv($variableAlmacen);

        if (!is_string($cadena) || trim($cadena) === '') {
            throw new RuntimeException(
                "No se configuraron destinatarios para el almacén {$almCodigo} en el archivo .env (variable {$variableAlmacen})."
            );
        }

        $correos = preg_split('/[,;]+/', $cadena) ?: [];
        $limpios = $this->normalizarLista($correos);

        if (empty($limpios)) {
            throw new RuntimeException(
                "La lista de destinatarios para el almacén {$almCodigo} ({$variableAlmacen}) en el archivo .env está vacía."
            );
        }

        return $limpios;
    }

    private function normalizarLista(array $correos): array
    {
        $limpios = [];

        foreach ($correos as $correo) {
            $correo = trim((string) $correo);

            if ($correo !== '') {
                $limpios[] = $correo;
            }
        }

        return array_values(array_unique($limpios));
    }

    /**
     * Compatibilidad con el envío de un solo artículo.
     */
    public function enviarAlerta(
        array $articulo,
        string|array|null $destinatarios = null
    ): bool {
        return $this->enviarAlertas(
            [$articulo],
            $destinatarios
        );
    }

    /**
     * Envía un correo con los artículos en alerta correspondientes a un almacén específico.
     */
    public function enviarAlertasPorAlmacen(
        string $almCodigo,
        array $articulos,
        string|array|null $destinatarios = null
    ): bool {
        return $this->enviarAlertas($articulos, $destinatarios, $almCodigo);
    }

    /**
     * Envía UN SOLO correo con todos los artículos en alerta.
     */
    public function enviarAlertas(
        array $articulos,
        string|array|null $destinatarios = null,
        ?string $almCodigo = null
    ): bool {
        if (empty($articulos)) {
            throw new RuntimeException(
                'No existen artículos para generar la alerta.'
            );
        }

        // Determinar el almacén si no fue provisto
        if ($almCodigo === null || trim($almCodigo) === '') {
            $almCodigo = trim((string) ($articulos[0]['alm_codigo'] ?? ''));
        }

        if ($almCodigo === '') {
            throw new RuntimeException('No se especificó el almacén para el envío de alertas.');
        }

        $listaDestinatarios = ($destinatarios === null || $destinatarios === '')
            ? $this->obtenerDestinatariosPorAlmacen($almCodigo)
            : $this->normalizarDestinatarios($destinatarios);

        if (empty($listaDestinatarios)) {
            throw new RuntimeException(
                "No hay destinatarios configurados para el almacén {$almCodigo}."
            );
        }

        $tarjetasHtml = '';
        $lineasTexto = [];

        foreach ($articulos as $articulo) {

            $codigo = trim((string) ($articulo['art_codigo'] ?? ''));
            $descripcion = trim((string) ($articulo['descripcion'] ?? ''));

            $unidad = trim((string) ($articulo['unidad_medida'] ?? ''));
            if ($unidad === '') {
                $unidad = 'UND';
            }
            $categoria = trim((string) ($articulo['categoria'] ?? ''));
            $famNombre = trim((string) ($articulo['fam_nombre'] ?? ''));
            $tfaNombre = trim((string) ($articulo['tfa_nombre'] ?? ''));

            $stockActual = (float) ($articulo['stock_actual'] ?? 0);
            $stockMinimo = (float) ($articulo['stock_minimo'] ?? 0);
            $faltante = max(0.0, $stockMinimo - $stockActual);

            if (
                $codigo === ''
                || $descripcion === ''
            ) {
                throw new RuntimeException(
                    'Un artículo contiene datos incompletos.'
                );
            }

            if ($stockActual > $stockMinimo) {
                throw new RuntimeException(
                    "El artículo {$codigo} ya no está por debajo del stock."
                );
            }

            // Escapar los datos provenientes de SQL Server.
            $codigoHtml = htmlspecialchars(
                $codigo,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );

            $descripcionHtml = htmlspecialchars(
                $descripcion,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );

            $unidadHtml = htmlspecialchars(
                $unidad,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );

            $categoriaHtml = htmlspecialchars(
                $categoria !== '' ? $categoria : '—',
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );

            $famNombreHtml = htmlspecialchars(
                $famNombre !== '' ? $famNombre : '—',
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );

            $tfaNombreHtml = htmlspecialchars(
                $tfaNombre !== '' ? $tfaNombre : '—',
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );

            $stockFormato = number_format(
                $stockActual,
                3,
                '.',
                ','
            );

            $minimoFormato = number_format(
                $stockMinimo,
                3,
                '.',
                ','
            );

            $faltanteFormato = number_format(
                $faltante,
                3,
                '.',
                ','
            );

            if ($faltante > 0) {
                $faltanteValorHtml = "{$faltanteFormato} {$unidadHtml}";
                $faltanteValorTexto = "{$faltanteFormato} {$unidad}";
            } else {
                $faltanteValorHtml = "{$faltanteFormato} {$unidadHtml} <span style='font-size:12px;font-weight:normal;color:#666666;'>(En el límite)</span>";
                $faltanteValorTexto = "{$faltanteFormato} {$unidad} (En el límite)";
            }

            $tarjetasHtml .= "
<table
    role='presentation'
    width='100%'
    cellpadding='0'
    cellspacing='0'
    border='0'
    style='
        width:100%;
        max-width:100%;
        table-layout:fixed;
        border:1px solid #dddddd;
        border-collapse:collapse;
        margin-bottom:15px;
        background-color:#ffffff;
    '
>
    <tr>
        <td style='
            padding:14px;
            background-color:#f3f4f6;
            overflow-wrap:anywhere;
            word-break:break-word;
        '>
            <div style='font-size:12px;color:#666666;'>
                Código del artículo
            </div>

            <div style='
                font-size:16px;
                font-weight:bold;
                color:#222222;
                margin-top:4px;
                overflow-wrap:anywhere;
                word-break:break-word;
            '>
                {$codigoHtml}
            </div>

            <div style='
                font-size:14px;
                color:#333333;
                margin-top:5px;
                overflow-wrap:anywhere;
                word-break:break-word;
            '>
                {$descripcionHtml}
            </div>
        </td>
    </tr>

    <tr>
        <td style='padding:9px 14px;background-color:#fafafa;border-bottom:1px solid #eeeeee;'>
            <div style='font-size:12px;color:#555555;line-height:1.6;'>
                <div style='margin-bottom:3px;'>
                    <strong style='color:#333333;display:inline-block;min-width:85px;'>Categoría:</strong> {$categoriaHtml}
                </div>
                <div style='margin-bottom:3px;'>
                    <strong style='color:#333333;display:inline-block;min-width:85px;'>Familia:</strong> {$famNombreHtml}
                </div>
                <div>
                    <strong style='color:#333333;display:inline-block;min-width:85px;'>Tipo familia:</strong> {$tfaNombreHtml}
                </div>
            </div>
        </td>
    </tr>

    <tr>
        <td style='padding:12px 14px 10px 14px;'>
            <div style='font-size:13px;color:#666666;'>
                Stock físico actual
            </div>
            <div style='
                font-size:16px;
                font-weight:bold;
                color:#c62828;
                margin-top:3px;
                overflow-wrap:anywhere;
            '>
                {$stockFormato} {$unidadHtml}
            </div>
        </td>
    </tr>

    <tr>
        <td style='padding:0 14px 10px 14px;'>
            <div style='font-size:13px;color:#666666;'>
                Stock mínimo configurado
            </div>
            <div style='font-size:14px;color:#222222;margin-top:3px;'>
                {$minimoFormato} {$unidadHtml}
            </div>
        </td>
    </tr>

    <tr>
        <td style='padding:0 14px 14px 14px;'>
            <div style='font-size:13px;color:#666666;'>
                Stock a reponer
            </div>
            <div style='
                font-size:15px;
                font-weight:bold;
                color:#b71c1c;
                margin-top:3px;
                overflow-wrap:anywhere;
            '>
                {$faltanteValorHtml}
            </div>
        </td>
    </tr>
</table>
";

            $lineasTexto[] =
                "Código: {$codigo}\n"
                . "Descripción: {$descripcion}\n"
                . "Categoría: " . ($categoria !== '' ? $categoria : '—') . "\n"
                . "Familia: " . ($famNombre !== '' ? $famNombre : '—') . "\n"
                . "Tipo familia: " . ($tfaNombre !== '' ? $tfaNombre : '—') . "\n"
                . "Stock actual: {$stockFormato} {$unidad}\n"
                . "Stock mínimo: {$minimoFormato} {$unidad}\n"
                . "Stock a reponer: {$faltanteValorTexto}\n";
        }

        $cantidad = count($articulos);
        $fecha = date('d/m/Y H:i:s');

        $textoCantidad = $cantidad === 1
            ? '1 artículo requiere atención'
            : "{$cantidad} artículos requieren atención";

        $nombreAlmacen = function_exists('getNombreAlmacen')
            ? getNombreAlmacen($almCodigo)
            : "Almacén {$almCodigo}";

        $nombreAlmacenHtml = htmlspecialchars($nombreAlmacen, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $almCodigoHtml = htmlspecialchars($almCodigo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $asunto = "[ALERTA DE STOCK] {$nombreAlmacen} - TECNOFILM";

        // Logo y aviso en el pie de página
        $logoImgHtml = '';

        if (is_file($this->rutaLogoFooter)) {
            $logoImgHtml = "
        <img
            src='cid:tecnofilm'
            alt='Tecnofilm'
            width='260'
            style='display:inline-block;max-width:260px;width:100%;height:auto;border:0;'
        >";
        } else {
            error_log(
                'Logo del footer no encontrado: ' . $this->rutaLogoFooter
            );
        }

        $footerHtml = "
    <div style='
        border-top:1px solid #e2e8f0;
        padding:18px 12px;
        text-align:center;
        background-color:#ffffff;
    '>
        {$logoImgHtml}
        <p style='
            margin:" . ($logoImgHtml !== '' ? '12px' : '0') . " 0 0 0;
            font-size:11.5px;
            color:#888888;
            line-height:1.4;
        '>
            Este es un correo automático, no mande una respuesta por aquí.
        </p>
    </div>";

        $cuerpoHtml = "
<div style='
    width:100%;
    max-width:640px;
    margin:0 auto;
    font-family:Arial,Helvetica,sans-serif;
    box-sizing:border-box;
    color:#333333;
'>

    <div style='
        background-color:#c62828;
        color:#ffffff;
        padding:22px 14px;
        text-align:center;
        box-sizing:border-box;
    '>
        <h2 style='
            margin:0;
            font-size:21px;
        '>
            ALERTA DE STOCK MÍNIMO
        </h2>

        <div style='
            margin-top:8px;
            font-size:15px;
            font-weight:bold;
            letter-spacing:0.02em;
            text-transform:uppercase;
        '>
            Almacén: {$almCodigoHtml} - {$nombreAlmacenHtml}
        </div>

        <p style='
            margin:8px 0 0 0;
            font-size:13.5px;
            opacity:0.95;
        '>
            {$textoCantidad}.
        </p>
    </div>

    <div style='
        padding:18px 12px;
        background-color:#ffffff;
        box-sizing:border-box;
    '>

        <p style='
            font-size:14px;
            line-height:1.5;
            margin:0 0 18px 0;
        '>
            Se detectaron los siguientes artículos con un stock
            físico igual o inferior al mínimo.
        </p>

        {$tarjetasHtml}

        <p style='
            margin-top:20px;
            font-size:12px;
            color:#777777;
        '>
            Fecha de generación: {$fecha}
        </p>

    </div>
{$footerHtml}
</div>";

        $cuerpoTexto =
            "ALERTA DE STOCK MÍNIMO - {$nombreAlmacen} (Almacén {$almCodigo})\n"
            . "====================================\n"
            . "Artículos que requieren atención: {$cantidad}\n"
            . "Fecha: {$fecha}\n"
            . "====================================\n\n"
            . implode(
                "\n------------------------------------\n",
                $lineasTexto
            )
            . "\nSe requiere gestionar la reposición de estos artículos.\n\n"
            . "Este es un correo automático, no mande una respuesta por aquí.";

        return $this->enviarCorreo(
            $listaDestinatarios,
            $asunto,
            $cuerpoHtml,
            $cuerpoTexto
        );
    }

    /**
     * Envía un correo mediante SMTP de Microsoft 365 / Outlook.
     */
    public function enviarCorreo(
        string|array $destinatarios,
        string $asunto,
        string $cuerpoHtml,
        string $cuerpoTexto = ''
    ): bool {
        $listaDestinatarios = $this->normalizarDestinatarios(
            $destinatarios
        );

        if (empty($listaDestinatarios)) {
            throw new RuntimeException(
                'No hay destinatarios especificados para el envío.'
            );
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();

            $mail->Host = $this->host;
            $mail->SMTPAuth = true;

            $mail->Username = $this->username;
            $mail->Password = $this->password;

            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $this->port;

            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 20;
            $mail->SMTPDebug = 0;

            // No desactivar la validación de certificados TLS.
            $mail->setFrom(
                $this->fromEmail,
                $this->fromName
            );

            foreach ($listaDestinatarios as $correo) {

                if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException(
                        "Dirección de correo no válida: {$correo}"
                    );
                }

                $mail->addAddress($correo);
            }

            $mail->isHTML(true);

            $mail->Subject = $asunto;
            $mail->Body = $cuerpoHtml;

            $mail->AltBody = $cuerpoTexto !== ''
                ? $cuerpoTexto
                : strip_tags($cuerpoHtml);

            // Logo embebido: el cid debe coincidir con src='cid:tecnofilm'.
            if (is_file($this->rutaLogoFooter)) {
                $mail->addEmbeddedImage(
                    $this->rutaLogoFooter,
                    'tecnofilm',
                    basename($this->rutaLogoFooter)
                );
            }

            $mail->send();

            return true;

        } catch (PHPMailerException $e) {

            throw new RuntimeException(
                'Error al enviar correo mediante Microsoft 365 / Outlook: '
                . $mail->ErrorInfo,
                0,
                $e
            );
        }
    }
}
@echo off
setlocal

REM Directorio del proyecto
set "APP_DIR=C:\xampp\htdocs\sistema-notificaciones"

REM Ejecutable PHP de XAMPP
set "PHP_EXE=C:\xampp\php\php.exe"

REM Directorio para almacenar los registros
set "LOG_DIR=%APP_DIR%\logs"

REM Crear carpeta de logs si no existe
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

REM Entrar al directorio del proyecto
cd /d "%APP_DIR%"

REM Obtener fecha actual en formato YYYY-MM-DD
for /f %%I in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "FECHA=%%I"
if "%FECHA%"=="" set "FECHA=actual"

REM Archivo de log diario (se acumula por dia, sin borrar historicos)
set "LOG_FILE=%LOG_DIR%\procesamiento_%FECHA%.log"

REM Ejecutar el sistema y registrar salida y errores
"%PHP_EXE%" "%APP_DIR%\bin\procesar_alertas.php" >> "%LOG_FILE%" 2>&1

REM Capturar el codigo de salida
set "EXIT_CODE=%ERRORLEVEL%"

REM Registrar unicamente si ocurrio un error critico de PHP
if not "%EXIT_CODE%"=="0" (
    echo [%date% %time%] ERROR FATAL: El proceso finalizo con codigo de error %EXIT_CODE% >> "%LOG_FILE%"
)

exit /b %EXIT_CODE%

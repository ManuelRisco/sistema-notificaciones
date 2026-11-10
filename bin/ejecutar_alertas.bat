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

REM Ejecutar el sistema (cada BD y almacen registra su propio log en logs\<base_de_datos>\<almacen>\<YYYY-MM>\)
"%PHP_EXE%" "%APP_DIR%\bin\procesar_alertas.php" >nul 2> "%LOG_DIR%\errores_ejecucion.log"

REM Capturar el codigo de salida
set "EXIT_CODE=%ERRORLEVEL%"

REM Si no hubo errores o el archivo de errores esta vacio, eliminarlo
if "%EXIT_CODE%"=="0" (
    if exist "%LOG_DIR%\errores_ejecucion.log" (
        for %%A in ("%LOG_DIR%\errores_ejecucion.log") do if %%~zA==0 del "%LOG_DIR%\errores_ejecucion.log"
    )
)

exit /b %EXIT_CODE%

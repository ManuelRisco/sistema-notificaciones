<?php

declare(strict_types=1);

use Dotenv\Dotenv;

if (!defined('APP_BOOTSTRAP_CARGADO')) {
    define('APP_BOOTSTRAP_CARGADO', true);

    $raizProyecto = dirname(__DIR__);

    if (!class_exists(Dotenv::class) && file_exists($raizProyecto . '/vendor/autoload.php')) {
        require_once $raizProyecto . '/vendor/autoload.php';
    }

    if (!class_exists(Dotenv::class)) {
        throw new RuntimeException(
            'No está instalada la dependencia vlucas/phpdotenv. Ejecuta composer install.'
        );
    }

    if (!is_file($raizProyecto . '/.env')) {
        throw new RuntimeException(
            'No se encontró el archivo .env en la raíz del proyecto.'
        );
    }

    // Cargar variables exclusivamente desde .env sobrescribiendo cualquier valor heredado del sistema
    $dotenv = Dotenv::createMutable($raizProyecto);
    $variablesDotenv = $dotenv->load();
    define('APP_CLAVES_DOTENV', array_fill_keys(array_keys($variablesDotenv), true));

    /**
     * Obtiene una variable de entorno exclusivamente desde $_ENV tras cargar .env.
     * No utiliza valores por defecto ni respaldos desde $_SERVER o getenv().
     *
     * @throws RuntimeException Si la variable no existe en .env o está vacía.
     */
    function obtenerVariableEntorno(string $nombre, bool $recortar = true): string
    {
        if (defined('APP_CLAVES_DOTENV') && !array_key_exists($nombre, APP_CLAVES_DOTENV)) {
            throw new RuntimeException(
                "No se configuró la variable {$nombre} en el archivo .env."
            );
        }

        if (!array_key_exists($nombre, $_ENV) || !is_string($_ENV[$nombre])) {
            throw new RuntimeException(
                "No se encontró la variable {$nombre} en el archivo .env."
            );
        }

        $valor = $recortar ? trim($_ENV[$nombre]) : $_ENV[$nombre];
        if ($valor === '') {
            throw new RuntimeException(
                "La variable {$nombre} del archivo .env está vacía."
            );
        }

        return $valor;
    }

    date_default_timezone_set(obtenerVariableEntorno('APP_TIMEZONE'));

    require_once __DIR__ . '/almacenes.php';
    require_once __DIR__ . '/database.php';
}


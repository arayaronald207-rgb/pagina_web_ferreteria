<?php

declare(strict_types=1);

function obtener_conexion(): PDO
{
    static $conexion = null;

    if ($conexion instanceof PDO) {
        return $conexion;
    }

    $ruta_entorno = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';

    if (!is_file($ruta_entorno) || !is_readable($ruta_entorno)) {
        throw new RuntimeException('No está disponible la configuración local de la base de datos.');
    }

    $variables_entorno = @parse_ini_file($ruta_entorno, false, INI_SCANNER_RAW);

    if (!is_array($variables_entorno)) {
        throw new RuntimeException('La configuración local de la base de datos no es válida.');
    }

    $variables_requeridas = [
        'DB_CONNECTION',
        'DB_HOST',
        'DB_PORT',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD',
    ];

    foreach ($variables_requeridas as $nombre_variable) {
        if (!array_key_exists($nombre_variable, $variables_entorno)) {
            throw new RuntimeException('La configuración local de la base de datos está incompleta.');
        }
    }

    if (strtolower($variables_entorno['DB_CONNECTION']) !== 'mysql') {
        throw new RuntimeException('El controlador de base de datos configurado no es compatible.');
    }

    foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME'] as $nombre_variable) {
        if (trim($variables_entorno[$nombre_variable]) === '' || str_contains($variables_entorno[$nombre_variable], ';')) {
            throw new RuntimeException('La configuración local de la base de datos no es válida.');
        }
    }

    $puerto = filter_var(
        $variables_entorno['DB_PORT'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 65535]]
    );

    if ($puerto === false) {
        throw new RuntimeException('El puerto de la base de datos no es válido.');
    }

    $cadena_conexion = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $variables_entorno['DB_HOST'],
        $puerto,
        $variables_entorno['DB_DATABASE']
    );

    try {
        $conexion = new PDO(
            $cadena_conexion,
            $variables_entorno['DB_USERNAME'],
            $variables_entorno['DB_PASSWORD'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException $error) {
        error_log('Falló la conexión PDO con MySQL. Código: ' . $error->getCode());
        throw new RuntimeException('No fue posible conectar con la base de datos.');
    }

    return $conexion;
}

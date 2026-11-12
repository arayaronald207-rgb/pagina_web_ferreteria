<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'conexion.php';

try {
    $conexion = obtener_conexion();
    $consulta = $conexion->prepare('SELECT 1');
    $consulta->execute();

    if ((int) $consulta->fetchColumn() !== 1) {
        throw new RuntimeException('La consulta de prueba no devolvió el resultado esperado.');
    }

    fwrite(STDOUT, "Conexión a MySQL establecida correctamente.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "No se pudo comprobar la conexión. Revisa MySQL y el archivo .env local.\n");
    exit(1);
}

<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'conexion.php';

function leer_dato_consola(string $mensaje): string
{
    if (function_exists('readline')) {
        $respuesta = readline($mensaje);
        return is_string($respuesta) ? trim($respuesta) : '';
    }

    fwrite(STDOUT, $mensaje);
    $respuesta = fgets(STDIN);
    return is_string($respuesta) ? trim($respuesta) : '';
}

$nombre = leer_dato_consola('Nombre del administrador: ');
$correo = leer_dato_consola('Correo electrónico: ');
$clave = getenv('FERRETERIA_PRIMER_ADMIN_CLAVE');
putenv('FERRETERIA_PRIMER_ADMIN_CLAVE');
unset($_ENV['FERRETERIA_PRIMER_ADMIN_CLAVE'], $_SERVER['FERRETERIA_PRIMER_ADMIN_CLAVE']);

$longitud_clave = is_string($clave) ? preg_match_all('/./us', $clave) : false;

if ($nombre === '' || strlen($nombre) > 100
    || strlen($correo) > 150
    || filter_var($correo, FILTER_VALIDATE_EMAIL) === false
    || !is_string($clave)
    || $longitud_clave === false
    || $longitud_clave < 12
    || strlen($clave) > 72) {
    fwrite(STDERR, "Datos no válidos. La contraseña debe tener entre 12 y 72 bytes.\n");
    exit(1);
}

try {
    $conexion = obtener_conexion();
    $consulta_administradores = $conexion->query(
        "SELECT COUNT(*) FROM usuarios WHERE rol = 'administrador'"
    );

    if ((int) $consulta_administradores->fetchColumn() > 0) {
        fwrite(STDERR, "Ya existe una cuenta administradora; no se creó otra.\n");
        exit(1);
    }

    $hash_clave = password_hash($clave, PASSWORD_DEFAULT);
    $clave = str_repeat("\0", strlen($clave));
    unset($clave);

    $consulta_usuario = $conexion->prepare(
        'INSERT INTO usuarios (nombre, correo, clave_hash, rol, activo)
        VALUES (:nombre, :correo, :clave_hash, :rol, :activo)'
    );
    $consulta_usuario->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $consulta_usuario->bindValue(':correo', $correo, PDO::PARAM_STR);
    $consulta_usuario->bindValue(':clave_hash', $hash_clave, PDO::PARAM_STR);
    $consulta_usuario->bindValue(':rol', 'administrador', PDO::PARAM_STR);
    $consulta_usuario->bindValue(':activo', 1, PDO::PARAM_INT);
    $consulta_usuario->execute();

    fwrite(STDOUT, "Cuenta administradora creada. Cierra la sesión de PowerShell y prueba el acceso.\n");
} catch (Throwable $error) {
    error_log('No se pudo crear la cuenta administradora inicial.');
    fwrite(STDERR, "No se pudo crear la cuenta. Revisa la conexión y los datos ingresados.\n");
    exit(1);
}

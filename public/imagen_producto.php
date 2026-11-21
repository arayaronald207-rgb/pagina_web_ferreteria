<?php

declare(strict_types=1);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../app/Servicios/autenticacion.php';
require_once __DIR__ . '/../app/Servicios/imagenes_productos.php';
require_once __DIR__ . '/../app/Servicios/categorias.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) { header('Allow: GET, HEAD'); http_response_code(405); exit; }
$nombre = $_GET['archivo'] ?? null;
$ruta = is_string($nombre) ? 'productos/' . $nombre : null;
$archivo = archivo_foto_producto($ruta);
if ($archivo === null || !is_file($archivo)) { http_response_code(404); exit; }
try {
    $conexion = obtener_conexion();
    $visible = 'CASE WHEN p.visible = 1 AND ' . condicion_categoria_publica($conexion) . ' THEN 1 ELSE 0 END';
    $principal = 'SELECT ' . $visible . ' AS visible FROM productos p
        INNER JOIN categorias c ON c.id_categoria = p.id_categoria WHERE p.imagen = :imagen';
    $sql = 'SELECT MAX(visible) FROM (' . $principal . ') AS referencias';
    $parametros = ['imagen' => $ruta];
    if (detalles_productos_disponibles($conexion)) {
        $sql = 'SELECT MAX(visible) FROM (' . $principal . '
            UNION ALL SELECT ' . $visible . ' AS visible FROM producto_imagenes i INNER JOIN productos p ON p.id_producto = i.id_producto
            INNER JOIN categorias c ON c.id_categoria = p.id_categoria
            WHERE i.imagen = :adicional) AS referencias';
        $parametros['adicional'] = $ruta;
    }
    $consulta = $conexion->prepare($sql);
    $consulta->execute($parametros);
    $publicado = $consulta->fetchColumn();
    if ($publicado === null || $publicado === false) { http_response_code(404); exit; }
    if ((int) $publicado !== 1) {
        $usuario = obtener_usuario_actual();
        if ($usuario === null) { http_response_code(404); exit; }
        $consulta = $conexion->prepare('SELECT rol FROM usuarios WHERE id_usuario = :id AND activo = 1');
        $consulta->execute(['id' => $usuario['id_usuario']]);
        $rol = $consulta->fetchColumn();
        if (!in_array($rol, ['administrador', 'empleado'], true) || $rol !== $usuario['rol']) { http_response_code(404); exit; }
    }
    $mime = @(new finfo(FILEINFO_MIME_TYPE))->file($archivo);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) { http_response_code(404); exit; }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . basename($archivo) . '"');
    $tamano = @filesize($archivo);
    if ($tamano === false) { http_response_code(404); exit; }
    header('Content-Length: ' . $tamano);
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') { @readfile($archivo); }
} catch (Throwable $error) {
    error_log('No se pudo servir una fotografía de producto.');
    http_response_code(503);
}

<?php

declare(strict_types=1);
require_once __DIR__ . '/detalles_productos.php';

const MAX_FOTO_PRODUCTO = 5 * 1024 * 1024;

function archivo_foto_producto(?string $ruta): ?string
{
    if ($ruta === null || !preg_match('~\Aproductos/[a-f0-9]{32}\.(jpg|png|webp)\z~', $ruta)) {
        return null;
    }
    return dirname(__DIR__, 2) . '/almacenamiento/' . $ruta;
}

function ruta_imagen_producto(?string $ruta): ?string
{
    $archivo = archivo_foto_producto($ruta);
    if ($archivo !== null) {
        return is_file($archivo) ? 'imagen_producto.php?archivo=' . rawurlencode(basename($ruta)) : null;
    }
    // Compatibilidad con las fotografías locales anteriores; nunca URLs externas.
    $ruta = ltrim(str_replace('\\', '/', trim($ruta ?? '')), '/');
    if (str_starts_with($ruta, 'public/')) { $ruta = substr($ruta, 7); }
    if (!preg_match('~\A(?:assets/images/|uploads/)[A-Za-z0-9._/-]+\.(?:jpg|jpeg|png|webp|gif)\z~i', $ruta)) { return null; }
    foreach (explode('/', $ruta) as $segmento) {
        if ($segmento === '' || $segmento === '.' || $segmento === '..') { return null; }
    }
    $publico = realpath(dirname(__DIR__, 2) . '/public');
    $real = realpath($publico . '/' . $ruta);
    if ($real === false || !str_starts_with($real, $publico . DIRECTORY_SEPARATOR) || !is_file($real)) { return null; }
    return $ruta;
}

function validar_foto_producto(?array $foto): ?array
{
    if ($foto === null) { return null; }
    if (!isset($foto['error']) || !is_int($foto['error'])) { throw new InvalidArgumentException('La fotografía recibida no es válida.'); }
    if ($foto['error'] === UPLOAD_ERR_NO_FILE) { return null; }
    if ($foto['error'] !== UPLOAD_ERR_OK) { throw new InvalidArgumentException('No se pudo subir la fotografía. Revisa su tamaño e inténtalo nuevamente.'); }
    $temporal = $foto['tmp_name'] ?? null;
    $nombre = $foto['name'] ?? null;
    if (!is_string($temporal) || !is_string($nombre) || !is_uploaded_file($temporal)) {
        throw new InvalidArgumentException('La fotografía no procede de una subida válida.');
    }
    $tamano = filesize($temporal);
    if ($tamano === false || $tamano < 1 || $tamano > MAX_FOTO_PRODUCTO) {
        throw new InvalidArgumentException('La fotografía debe ocupar como máximo 5 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporal);
    $tipos = ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp']];
    $extension = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
    $dimensiones = @getimagesize($temporal);
    if (!isset($tipos[$mime]) || !in_array($extension, $tipos[$mime], true)
        || $dimensiones === false || ($dimensiones['mime'] ?? '') !== $mime) {
        throw new InvalidArgumentException('Utiliza una fotografía JPG, PNG o WebP con contenido y extensión válidos.');
    }
    if ($dimensiones[0] < 1 || $dimensiones[1] < 1 || $dimensiones[0] > 12000 || $dimensiones[1] > 12000
        || $dimensiones[0] * $dimensiones[1] > 40000000) {
        throw new InvalidArgumentException('La fotografía supera las dimensiones permitidas (12 000 px por lado y 40 megapíxeles).');
    }
    return ['temporal' => $temporal, 'extension' => $tipos[$mime][0]];
}

function almacenar_foto_producto(array $foto): string
{
    $ruta = 'productos/' . bin2hex(random_bytes(16)) . '.' . $foto['extension'];
    $archivo = archivo_foto_producto($ruta);
    $directorio = dirname($archivo);
    if (!is_dir($directorio) && !@mkdir($directorio, 0700, true) && !is_dir($directorio)) {
        throw new RuntimeException('No se pudo preparar el almacenamiento de fotografías.');
    }
    if (!@move_uploaded_file($foto['temporal'], $archivo)) { throw new RuntimeException('No se pudo guardar la fotografía.'); }
    return $ruta;
}

function limpiar_foto_producto(PDO $conexion, ?string $ruta): void
{
    $archivo = archivo_foto_producto($ruta);
    // Solo borrar archivos administrados por este módulo, nunca imágenes originales.
    if ($archivo === null || !is_file($archivo)) { return; }
    $consulta = $conexion->prepare('SELECT COUNT(*) FROM productos WHERE imagen = :imagen');
    $consulta->execute(['imagen' => $ruta]);
    if ((int) $consulta->fetchColumn() > 0) { return; }
    if (detalles_productos_disponibles($conexion)) {
        $consulta = $conexion->prepare('SELECT COUNT(*) FROM producto_imagenes WHERE imagen = :imagen');
        $consulta->execute(['imagen' => $ruta]);
        if ((int) $consulta->fetchColumn() > 0) { return; }
    }
    if (!@unlink($archivo)) {
        throw new RuntimeException('No se pudo retirar una fotografía sin referencias.');
    }
}

function validar_fotos_adicionales(?array $archivos): array
{
    if ($archivos === null) { return []; }
    if (!isset($archivos['error']) || !is_array($archivos['error']) || count($archivos['error']) > 5) {
        throw new InvalidArgumentException('Puedes añadir hasta cinco fotografías adicionales.');
    }
    foreach (['name', 'tmp_name'] as $campo) {
        if (!isset($archivos[$campo]) || !is_array($archivos[$campo])
            || array_keys($archivos[$campo]) !== array_keys($archivos['error'])) {
            throw new InvalidArgumentException('Las fotografías recibidas no son válidas.');
        }
    }
    $fotos = [];
    foreach ($archivos['error'] as $indice => $error) {
        $foto = validar_foto_producto(['error' => $error, 'name' => $archivos['name'][$indice] ?? null,
            'tmp_name' => $archivos['tmp_name'][$indice] ?? null]);
        if ($foto !== null) { $fotos[] = $foto; }
    }
    return $fotos;
}

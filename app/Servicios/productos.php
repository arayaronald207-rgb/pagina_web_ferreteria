<?php

declare(strict_types=1);
require_once __DIR__ . '/categorias.php';
require_once __DIR__ . '/imagenes_productos.php';

function validar_id_producto(mixed $valor): int
{
    try { return validar_id_categoria($valor); }
    catch (InvalidArgumentException $e) { throw new InvalidArgumentException('El producto seleccionado no es válido.'); }
}

function datos_producto(array $entrada): array
{
    $nombre = $entrada['nombre'] ?? null;
    $codigo = $entrada['codigo'] ?? null;
    $descripcion = $entrada['descripcion'] ?? '';
    if (!is_string($nombre) || !mb_check_encoding($nombre, 'UTF-8')) { throw new InvalidArgumentException('Escribe un nombre válido.'); }
    $nombre = trim(preg_replace('/\s+/u', ' ', $nombre) ?? '');
    if ($nombre === '' || mb_strlen($nombre) > 150 || preg_match('/[\x00-\x1f\x7f]/u', $nombre)) {
        throw new InvalidArgumentException('El nombre debe tener entre 1 y 150 caracteres.');
    }
    if (!is_string($codigo)) { throw new InvalidArgumentException('Escribe un código válido.'); }
    // El código lo asigna el personal: conservar ceros iniciales y minúsculas.
    if (!preg_match('~\A[A-Za-z0-9][A-Za-z0-9._/-]{0,63}\z~', $codigo)) {
        throw new InvalidArgumentException('El código debe tener de 1 a 64 caracteres: letras, números, punto, guion, barra o guion bajo.');
    }
    if (!is_string($descripcion) || !mb_check_encoding($descripcion, 'UTF-8') || mb_strlen($descripcion) > 5000
        || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $descripcion)) {
        throw new InvalidArgumentException('La descripción debe ser texto válido de hasta 5000 caracteres.');
    }
    $visible = $entrada['visible'] ?? null;
    if (!in_array($visible, ['0', '1'], true)) { throw new InvalidArgumentException('Selecciona un estado de publicación válido.'); }
    return ['nombre' => $nombre, 'codigo' => $codigo, 'descripcion' => trim($descripcion),
        'id_categoria' => validar_id_categoria($entrada['id_categoria'] ?? null), 'visible' => (int) $visible];
}

function buscar_producto(PDO $conexion, int $id, bool $bloquear = false): array
{
    $sql = 'SELECT * FROM productos WHERE id_producto = :id';
    if ($bloquear && $conexion->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') { $sql .= ' FOR UPDATE'; }
    $consulta = $conexion->prepare($sql);
    $consulta->execute(['id' => $id]);
    $producto = $consulta->fetch();
    if (!$producto) { throw new InvalidArgumentException('El producto ya no existe. Actualiza el listado.'); }
    return $producto;
}

function producto_tiene_solicitudes(PDO $conexion, int $id): bool
{
    $consulta = $conexion->prepare('SELECT COUNT(*) FROM detalle_solicitud WHERE id_producto = :id');
    $consulta->execute(['id' => $id]);
    return (int) $consulta->fetchColumn() > 0;
}

function traducir_error_producto(Throwable $error): never
{
    if ($error instanceof PDOException) {
        $codigo = (int) ($error->errorInfo[1] ?? 0);
        if ($codigo === 1062 || ($codigo === 19 && str_contains($error->getMessage(), 'productos.codigo'))) {
            throw new InvalidArgumentException('Ya existe un producto con ese código.');
        }
        if ($codigo === 1451) { throw new InvalidArgumentException('El producto tiene solicitudes asociadas. Puedes ocultarlo para conservar su historial.'); }
        if ($codigo === 1452) { throw new InvalidArgumentException('La categoría seleccionada ya no existe.'); }
    }
    throw $error;
}

// Los fallos de disco se registran para reintento, sin deshacer un guardado confirmado.
function retirar_foto_producto(PDO $conexion, ?string $ruta): void
{
    if (archivo_foto_producto($ruta) === null) { return; }
    try { limpiar_foto_producto($conexion, $ruta); }
    catch (Throwable $error) {
        error_log('Quedó pendiente retirar una fotografía de producto.');
        if (@file_put_contents(archivo_foto_producto($ruta) . '.pendiente', '') === false) {
            error_log('No se pudo registrar la limpieza pendiente de la fotografía.');
        }
    }
}

function reintentar_limpieza_productos(PDO $conexion): void
{
    foreach (glob(dirname(__DIR__, 2) . '/almacenamiento/productos/*.pendiente') ?: [] as $marca) {
        $ruta = 'productos/' . basename($marca, '.pendiente');
        if (archivo_foto_producto($ruta) === null) { continue; }
        try { limpiar_foto_producto($conexion, $ruta); @unlink($marca); }
        catch (Throwable $error) { error_log('Continúa pendiente la limpieza de una fotografía.'); }
    }
}

function preparar_insercion_producto(PDO $conexion): PDOStatement
{
    return $conexion->prepare('INSERT INTO productos (codigo, nombre, descripcion, id_categoria, visible, imagen)
        VALUES (:codigo, :nombre, :descripcion, :id_categoria, :visible, :imagen)');
}

// La transacción pertenece al llamador: formulario individual o lote del importador.
function insertar_producto_validado(PDO $conexion, array $datos, ?PDOStatement $consulta = null): int
{
    ($consulta ?? preparar_insercion_producto($conexion))->execute($datos);
    return (int) $conexion->lastInsertId();
}

function guardar_producto(PDO $conexion, array $entrada, ?int $id, ?array $subida, bool $quitar_foto, ?array $subidas_adicionales = null): int
{
    $datos = datos_producto($entrada);
    $foto = validar_foto_producto($subida);
    $ampliado = detalles_productos_disponibles($conexion);
    $adicionales = validar_fotos_adicionales($subidas_adicionales);
    $quitar_adicionales = $entrada['quitar_imagenes'] ?? [];
    if (!is_array($quitar_adicionales) || count($quitar_adicionales) > 5) { throw new InvalidArgumentException('La selección de fotografías no es válida.'); }
    $quitar_adicionales = array_unique(array_map('validar_id_producto', $quitar_adicionales));
    $especificaciones = array_key_exists('especificaciones', $entrada) ? validar_especificaciones($entrada['especificaciones']) : null;
    if (!$ampliado && ($adicionales !== [] || $quitar_adicionales !== [] || $especificaciones !== null)) {
        throw new InvalidArgumentException('Las fotografías adicionales y especificaciones aún no están habilitadas.');
    }
    if ($foto !== null && $quitar_foto) { throw new InvalidArgumentException('Elige sustituir o eliminar la fotografía, no ambas opciones.'); }
    $nueva = null;
    $nuevas_adicionales = [];
    $retiradas = [];
    $anterior = null;
    $conexion->beginTransaction();
    try {
        $producto = $id === null ? null : buscar_producto($conexion, $id, true);
        $galeria = $ampliado && $id !== null ? imagenes_adicionales_producto($conexion, $id) : [];
        if (array_diff($quitar_adicionales, array_column($galeria, 'id_imagen'))) {
            throw new InvalidArgumentException('Una fotografía seleccionada ya no pertenece a este producto.');
        }
        if (count($galeria) - count($quitar_adicionales) + count($adicionales) > 5) {
            throw new InvalidArgumentException('El producto admite hasta cinco fotografías adicionales.');
        }
        buscar_categoria($conexion, $datos['id_categoria']);
        $consulta = $conexion->prepare('SELECT id_producto FROM productos WHERE codigo = :codigo AND id_producto <> :id');
        $consulta->execute(['codigo' => $datos['codigo'], 'id' => $id ?? 0]);
        if ($consulta->fetch()) { throw new InvalidArgumentException('Ya existe un producto con ese código.'); }
        $anterior = $producto['imagen'] ?? null;
        if ($foto !== null) { $nueva = almacenar_foto_producto($foto); }
        $datos['imagen'] = $nueva ?? ($quitar_foto ? null : $anterior);
        if ($id === null) {
            $id = insertar_producto_validado($conexion, $datos);
        } else {
            $consulta = $conexion->prepare('UPDATE productos SET codigo = :codigo, nombre = :nombre, descripcion = :descripcion,
                id_categoria = :id_categoria, visible = :visible, imagen = :imagen WHERE id_producto = :id');
            $consulta->execute($datos + ['id' => $id]);
        }
        if ($ampliado) {
            if ($especificaciones !== null) {
                $consulta = $conexion->prepare('UPDATE productos SET especificaciones = :datos WHERE id_producto = :id');
                $consulta->execute(['datos' => $especificaciones === [] ? null : json_encode($especificaciones, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'id' => $id]);
            }
            foreach ($galeria as $imagen) {
                if (!in_array((int) $imagen['id_imagen'], $quitar_adicionales, true)) { continue; }
                $consulta = $conexion->prepare('DELETE FROM producto_imagenes WHERE id_imagen = :imagen AND id_producto = :producto');
                $consulta->execute(['imagen' => $imagen['id_imagen'], 'producto' => $id]);
                $retiradas[] = $imagen['imagen'];
            }
            foreach ($adicionales as $adicional) {
                $ruta = almacenar_foto_producto($adicional);
                $nuevas_adicionales[] = $ruta;
                $consulta = $conexion->prepare('INSERT INTO producto_imagenes (id_producto, imagen) VALUES (:id, :imagen)');
                $consulta->execute(['id' => $id, 'imagen' => $ruta]);
            }
        }
        $conexion->commit();
    } catch (Throwable $error) {
        if ($conexion->inTransaction()) { $conexion->rollBack(); }
        retirar_foto_producto($conexion, $nueva);
        foreach ($nuevas_adicionales as $ruta) { retirar_foto_producto($conexion, $ruta); }
        traducir_error_producto($error);
    }
    if ($nueva !== null || $quitar_foto) { retirar_foto_producto($conexion, $anterior); }
    foreach ($retiradas as $ruta) { retirar_foto_producto($conexion, $ruta); }
    return $id;
}

function cambiar_publicacion_producto(PDO $conexion, int $id, mixed $visible): void
{
    if (!in_array($visible, ['0', '1'], true)) { throw new InvalidArgumentException('Estado de publicación no válido.'); }
    buscar_producto($conexion, $id);
    $consulta = $conexion->prepare('UPDATE productos SET visible = :visible WHERE id_producto = :id');
    $consulta->execute(['visible' => (int) $visible, 'id' => $id]);
}

function eliminar_producto(PDO $conexion, int $id): void
{
    $ampliado = detalles_productos_disponibles($conexion);
    $conexion->beginTransaction();
    try {
        $producto = buscar_producto($conexion, $id, true);
        $galeria = $ampliado ? imagenes_adicionales_producto($conexion, $id) : [];
        if (producto_tiene_solicitudes($conexion, $id)) {
            throw new InvalidArgumentException('El producto tiene solicitudes asociadas. Puedes ocultarlo para conservar su historial.');
        }
        $consulta = $conexion->prepare('DELETE FROM productos WHERE id_producto = :id
            AND NOT EXISTS (SELECT 1 FROM detalle_solicitud WHERE id_producto = :detalle)');
        $consulta->execute(['id' => $id, 'detalle' => $id]);
        if ($consulta->rowCount() !== 1) { throw new InvalidArgumentException('No se eliminó el producto. Actualiza el listado.'); }
        $conexion->commit();
    } catch (Throwable $error) {
        if ($conexion->inTransaction()) { $conexion->rollBack(); }
        traducir_error_producto($error);
    }
    retirar_foto_producto($conexion, $producto['imagen']);
    foreach ($galeria as $imagen) { retirar_foto_producto($conexion, $imagen['imagen']); }
}

function listar_productos_panel(PDO $conexion, string $busqueda, ?int $categoria, string $estado, int $pagina): array
{
    $condiciones = [];
    $parametros = [];
    if ($busqueda !== '') {
        $condiciones[] = busqueda_productos_sql($busqueda, $parametros);
    }
    if ($categoria !== null) { $condiciones[] = 'p.id_categoria = :categoria'; $parametros['categoria'] = $categoria; }
    if ($estado !== '') { $condiciones[] = 'p.visible = :visible'; $parametros['visible'] = (int) $estado; }
    $where = $condiciones ? ' WHERE ' . implode(' AND ', $condiciones) : '';
    $consulta = $conexion->prepare('SELECT COUNT(*) FROM productos p' . $where);
    $consulta->execute($parametros);
    $total = (int) $consulta->fetchColumn();
    $paginas = max(1, (int) ceil($total / 20));
    $pagina = min(max(1, $pagina), $paginas);
    $consulta = $conexion->prepare('SELECT p.*, c.nombre AS categoria_nombre,
        (SELECT COUNT(*) FROM detalle_solicitud d WHERE d.id_producto = p.id_producto) AS solicitudes
        FROM productos p INNER JOIN categorias c ON c.id_categoria = p.id_categoria' . $where . '
        ORDER BY p.nombre, p.id_producto LIMIT :limite OFFSET :desplazamiento');
    foreach ($parametros as $nombre => $valor) { $consulta->bindValue(':' . $nombre, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR); }
    $consulta->bindValue(':limite', 20, PDO::PARAM_INT);
    $consulta->bindValue(':desplazamiento', ($pagina - 1) * 20, PDO::PARAM_INT);
    $consulta->execute();
    return ['productos' => $consulta->fetchAll(), 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
}

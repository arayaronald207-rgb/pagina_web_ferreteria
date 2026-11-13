<?php

declare(strict_types=1);

function validar_nombre_categoria(mixed $valor): string
{
    if (!is_string($valor) || !mb_check_encoding($valor, 'UTF-8')) {
        throw new InvalidArgumentException('Escribe un nombre válido para la categoría.');
    }
    $nombre = preg_replace('/\s+/u', ' ', trim($valor));
    $nombre = trim($nombre ?? '');
    if ($nombre === '' || mb_strlen($nombre, 'UTF-8') > 100
        || preg_match('/[\x00-\x1F\x7F]/u', $nombre)) {
        throw new InvalidArgumentException('El nombre debe tener entre 1 y 100 caracteres, sin caracteres de control.');
    }
    return $nombre;
}

function validar_id_categoria(mixed $valor): int
{
    if ((!is_string($valor) && !is_int($valor))
        || !preg_match('/\A[1-9][0-9]*\z/', (string) $valor)) {
        throw new InvalidArgumentException('La categoría seleccionada no es válida.');
    }
    $id = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    if ($id === false) {
        throw new InvalidArgumentException('La categoría seleccionada no es válida.');
    }
    return $id;
}

function listar_categorias(PDO $conexion): array
{
    $consulta = $conexion->prepare(
        'SELECT c.id_categoria, c.nombre, COUNT(p.id_producto) AS productos
        FROM categorias c LEFT JOIN productos p ON p.id_categoria = c.id_categoria
        GROUP BY c.id_categoria, c.nombre ORDER BY c.nombre ASC'
    );
    $consulta->execute();
    return $consulta->fetchAll();
}

function buscar_categoria(PDO $conexion, int $id): array
{
    $consulta = $conexion->prepare(
        'SELECT c.id_categoria, c.nombre,
            (SELECT COUNT(*) FROM productos p WHERE p.id_categoria = c.id_categoria) AS productos
        FROM categorias c WHERE c.id_categoria = :id'
    );
    $consulta->execute(['id' => $id]);
    $categoria = $consulta->fetch();
    if (!$categoria) {
        throw new InvalidArgumentException('La categoría ya no existe. Actualiza el listado.');
    }
    return $categoria;
}

function guardar_categoria(PDO $conexion, string $nombre, ?int $id = null): void
{
    $nombre = validar_nombre_categoria($nombre);
    if ($id !== null) {
        buscar_categoria($conexion, $id);
    }
    $duplicado = $conexion->prepare('SELECT id_categoria FROM categorias WHERE nombre = :nombre AND id_categoria <> :id LIMIT 1');
    $duplicado->execute(['nombre' => $nombre, 'id' => $id ?? 0]);
    if ($duplicado->fetch()) {
        throw new InvalidArgumentException('Ya existe una categoría con ese nombre.');
    }
    try {
        if ($id === null) {
            $consulta = $conexion->prepare('INSERT INTO categorias (nombre) VALUES (:nombre)');
            $consulta->execute(['nombre' => $nombre]);
        } else {
            $consulta = $conexion->prepare('UPDATE categorias SET nombre = :nombre WHERE id_categoria = :id');
            $consulta->execute(['nombre' => $nombre, 'id' => $id]);
            if ($consulta->rowCount() === 0) {
                buscar_categoria($conexion, $id);
            }
        }
    } catch (PDOException $error) {
        // La restricción UNIQUE también protege frente a creaciones simultáneas.
        if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
            throw new InvalidArgumentException('Ya existe una categoría con ese nombre.');
        }
        throw $error;
    }
}

function eliminar_categoria(PDO $conexion, int $id): void
{
    buscar_categoria($conexion, $id);
    try {
        // Incluye productos ocultos. La clave foránea protege ante cambios concurrentes.
        $consulta = $conexion->prepare(
            'DELETE FROM categorias WHERE id_categoria = :id
            AND NOT EXISTS (SELECT 1 FROM productos WHERE id_categoria = :categoria_productos)'
        );
        $consulta->execute(['id' => $id, 'categoria_productos' => $id]);
        if ($consulta->rowCount() !== 1) {
            throw new InvalidArgumentException('No se eliminó la categoría: tiene productos asociados o ya no existe.');
        }
    } catch (PDOException $error) {
        if ((int) ($error->errorInfo[1] ?? 0) === 1451) {
            throw new InvalidArgumentException('No puedes eliminar una categoría que tiene productos asociados.');
        }
        throw $error;
    }
}

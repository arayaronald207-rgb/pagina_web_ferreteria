<?php

declare(strict_types=1);

const CAMPOS_ESPECIFICACIONES = [
    'marca' => 'Marca', 'modelo' => 'Modelo', 'material' => 'Material',
    'color' => 'Color', 'dimensiones' => 'Dimensiones', 'potencia' => 'Potencia',
];

// La ampliación es opcional hasta que se autorice la migración 003.
function detalles_productos_disponibles(PDO $conexion): bool
{
    try {
        $conexion->query('SELECT especificaciones FROM productos LIMIT 0');
        $conexion->query('SELECT id_imagen, id_producto, imagen FROM producto_imagenes LIMIT 0');
        return true;
    } catch (PDOException $error) {
        if (in_array($error->getCode(), ['42S02', '42S22'], true)
            || ($conexion->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                && (str_contains($error->getMessage(), 'no such table:') || str_contains($error->getMessage(), 'no such column:')))) {
            return false;
        }
        throw $error;
    }
}

function validar_especificaciones(mixed $entrada): array
{
    if (!is_array($entrada) || array_diff(array_keys($entrada), array_keys(CAMPOS_ESPECIFICACIONES))) {
        throw new InvalidArgumentException('Las especificaciones no son válidas.');
    }
    $valores = [];
    foreach (CAMPOS_ESPECIFICACIONES as $campo => $etiqueta) {
        $valor = $entrada[$campo] ?? '';
        if (!is_string($valor) || !mb_check_encoding($valor, 'UTF-8') || mb_strlen($valor) > 150
            || preg_match('/[\x00-\x1f\x7f]/u', $valor)) {
            throw new InvalidArgumentException($etiqueta . ': utiliza hasta 150 caracteres de texto.');
        }
        if (trim($valor) !== '') { $valores[$campo] = trim($valor); }
    }
    return $valores;
}

function leer_especificaciones(?string $json): array
{
    if ($json === null || $json === '') { return []; }
    try { return validar_especificaciones(json_decode($json, true, 8, JSON_THROW_ON_ERROR)); }
    catch (JsonException | InvalidArgumentException $error) {
        error_log('Especificaciones de producto inválidas; no se muestran.');
        return [];
    }
}

// Solo llamar cuando se haya comprobado la disponibilidad de la ampliación.
function imagenes_adicionales_producto(PDO $conexion, int $id): array
{
    $consulta = $conexion->prepare('SELECT id_imagen, imagen FROM producto_imagenes WHERE id_producto = :id ORDER BY id_imagen');
    $consulta->execute(['id' => $id]);
    return $consulta->fetchAll();
}

// Marcadores distintos para las consultas nativas de PDO MySQL.
function busqueda_productos_sql(string $busqueda, array &$parametros): string
{
    foreach (['nombre', 'codigo', 'descripcion'] as $campo) { $parametros['buscar_' . $campo] = '%' . $busqueda . '%'; }
    return '(p.nombre LIKE :buscar_nombre OR p.codigo LIKE :buscar_codigo OR p.descripcion LIKE :buscar_descripcion)';
}

function whatsapp_producto(array $producto): string
{
    return 'https://wa.me/50670988980?text=' . rawurlencode('Hola, quisiera consultar sobre ' . $producto['nombre'] . ' (código ' . $producto['codigo'] . ').');
}

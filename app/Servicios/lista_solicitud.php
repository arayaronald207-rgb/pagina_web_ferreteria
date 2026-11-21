<?php

declare(strict_types=1);
require_once __DIR__ . '/catalogo.php';

const MAX_PRODUCTOS_SOLICITUD = 100;
const MAX_CANTIDAD_SOLICITUD = 999;

function normalizar_lista_solicitud(mixed $entrada): array
{
    if (!is_array($entrada) || !array_is_list($entrada) || count($entrada) > MAX_PRODUCTOS_SOLICITUD) {
        throw new InvalidArgumentException('La lista admite hasta 100 productos distintos.');
    }
    $cantidades = [];
    foreach ($entrada as $linea) {
        if (!is_array($linea) || array_diff(array_keys($linea), ['id_producto', 'cantidad'])
            || !is_int($linea['id_producto'] ?? null) || $linea['id_producto'] < 1 || $linea['id_producto'] > 2147483647
            || !is_int($linea['cantidad'] ?? null) || $linea['cantidad'] < 1 || $linea['cantidad'] > MAX_CANTIDAD_SOLICITUD) {
            throw new InvalidArgumentException('Cada producto debe tener un identificador válido y una cantidad entera entre 1 y 999.');
        }
        $id = $linea['id_producto'];
        $cantidades[$id] = ($cantidades[$id] ?? 0) + $linea['cantidad'];
        if ($cantidades[$id] > MAX_CANTIDAD_SOLICITUD) {
            throw new InvalidArgumentException('La cantidad máxima por producto es 999, incluso si aparece repetido.');
        }
    }
    $lista = [];
    foreach ($cantidades as $id => $cantidad) { $lista[] = ['id_producto' => $id, 'cantidad' => $cantidad]; }
    return $lista;
}

// Solo lectura. No guarda solicitudes, no inicia sesiones y no modifica productos.
// En la etapa de envío será necesario validar nuevamente, aunque la lista ya se haya revisado.
function validar_lista_solicitud(PDO $conexion, mixed $entrada): array
{
    $lista = normalizar_lista_solicitud($entrada);
    $productos = [];
    $retirados = [];
    if ($lista !== []) {
        $ids = array_column($lista, 'id_producto');
        $consulta = $conexion->prepare('SELECT p.id_producto, p.codigo, p.nombre, p.imagen FROM productos p
            INNER JOIN categorias c ON c.id_categoria = p.id_categoria
            WHERE p.visible = 1 AND ' . condicion_categoria_publica($conexion)
            . ' AND p.id_producto IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $consulta->execute($ids);
        $publicados = [];
        foreach ($consulta->fetchAll() as $producto) { $publicados[(int) $producto['id_producto']] = $producto; }
        foreach ($lista as $linea) {
            $producto = $publicados[$linea['id_producto']] ?? null;
            if ($producto === null) { $retirados[] = $linea['id_producto']; continue; }
            $productos[] = [
                'id_producto' => (int) $producto['id_producto'],
                'codigo' => $producto['codigo'], 'nombre' => $producto['nombre'],
                'imagen' => ruta_imagen_producto($producto['imagen']) ?? 'assets/images/producto_sin_imagen.svg',
                'cantidad' => $linea['cantidad'],
            ];
        }
    }
    return ['productos' => $productos, 'retirados' => $retirados,
        'limites' => ['productos' => MAX_PRODUCTOS_SOLICITUD, 'cantidad' => MAX_CANTIDAD_SOLICITUD]];
}

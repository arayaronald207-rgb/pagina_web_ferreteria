<?php

declare(strict_types=1);
require_once __DIR__ . '/imagenes_productos.php';
require_once __DIR__ . '/categorias.php';

function escapar_catalogo(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function filtros_catalogo(array $entrada): array
{
    $buscar = $entrada['buscar'] ?? '';
    if (!is_string($buscar) || strlen($buscar) > 480 || preg_match('//u', $buscar) !== 1) { $buscar = ''; }
    $categoria = $entrada['categoria'] ?? '';
    if (!is_string($categoria) || strlen($categoria) > 400 || preg_match('//u', $categoria) !== 1) { $categoria = ''; }
    $filtros = array_filter(['buscar' => trim($buscar), 'categoria' => trim($categoria)], fn($valor) => $valor !== '');
    $pagina = filter_var($entrada['pagina'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    if ($pagina !== false && $pagina > 1) { $filtros['pagina'] = $pagina; }
    return $filtros;
}

function url_catalogo(array $filtros): string
{
    if (($filtros['pagina'] ?? null) === 1) { unset($filtros['pagina']); }
    return 'catalogo.php' . ($filtros ? '?' . http_build_query($filtros, '', '&', PHP_QUERY_RFC3986) : '');
}

function categorias_catalogo(PDO $conexion): array
{
    return $conexion->query('SELECT nombre FROM categorias ORDER BY nombre ASC')->fetchAll();
}

function productos_catalogo(PDO $conexion, string $busqueda, ?string $categoria, int $pagina = 1): array
{
    $sql = ' FROM productos p INNER JOIN categorias c ON c.id_categoria = p.id_categoria WHERE p.visible = 1';
    $parametros = [];
    if ($busqueda !== '') { $sql .= ' AND ' . busqueda_productos_sql($busqueda, $parametros); }
    if ($categoria !== null) { $sql .= ' AND c.nombre = :categoria'; $parametros['categoria'] = $categoria; }
    $consulta = $conexion->prepare('SELECT COUNT(*)' . $sql);
    $consulta->execute($parametros);
    $total = (int) $consulta->fetchColumn();
    $paginas = max(1, (int) ceil($total / 24));
    $pagina = min(max(1, $pagina), $paginas);
    $consulta = $conexion->prepare('SELECT p.id_producto, p.codigo, p.nombre, p.descripcion, p.imagen, c.nombre AS categoria_nombre'
        . $sql . ' ORDER BY p.nombre, p.id_producto LIMIT :limite OFFSET :inicio');
    foreach ($parametros as $nombre => $valor) { $consulta->bindValue(':' . $nombre, $valor, PDO::PARAM_STR); }
    $consulta->bindValue(':limite', 24, PDO::PARAM_INT);
    $consulta->bindValue(':inicio', ($pagina - 1) * 24, PDO::PARAM_INT);
    $consulta->execute();
    return ['productos' => $consulta->fetchAll(), 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
}

function producto_publicado(PDO $conexion, int $id): ?array
{
    $consulta = $conexion->prepare('SELECT p.*, c.nombre AS categoria_nombre FROM productos p
        INNER JOIN categorias c ON c.id_categoria = p.id_categoria WHERE p.id_producto = :id AND p.visible = 1');
    $consulta->execute(['id' => $id]);
    return $consulta->fetch() ?: null;
}

function galeria_producto_publico(PDO $conexion, array $producto): array
{
    $rutas = [$producto['imagen']];
    if (detalles_productos_disponibles($conexion)) {
        $rutas = array_merge($rutas, array_column(imagenes_adicionales_producto($conexion, (int) $producto['id_producto']), 'imagen'));
    }
    $urls = [];
    foreach ($rutas as $ruta) {
        $url = ruta_imagen_producto($ruta);
        if ($url !== null && !in_array($url, $urls, true)) { $urls[] = $url; }
    }
    return $urls ?: ['assets/images/producto_sin_imagen.svg'];
}

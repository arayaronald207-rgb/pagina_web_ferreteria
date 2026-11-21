<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'conexion.php';

require_once __DIR__ . '/../app/Servicios/catalogo.php';
header('Cache-Control: no-store');
$filtros = filtros_catalogo($_GET);
$busqueda = $filtros['buscar'] ?? '';
$categoria_seleccionada = $filtros['categoria'] ?? null;

$pagina_actual = 'productos';
$termino_busqueda = $busqueda;
$categorias = [];
$productos = [];
$listado = ['total' => 0, 'pagina' => 1, 'paginas' => 1];
$error_catalogo = false;
$error_categorias_navegacion = true;
$categoria_no_disponible = false;

try {
    $conexion = obtener_conexion();

    $categorias = categorias_catalogo($conexion);
    $error_categorias_navegacion = false;
    if ($categoria_seleccionada !== null && !in_array($categoria_seleccionada, array_column($categorias, 'nombre'), true)) {
        // Un enlace antiguo a una categoría oculta no debe mostrarla como filtro
        // ni ampliar accidentalmente la consulta a todo el catálogo.
        $categoria_no_disponible = true;
        $categoria_seleccionada = null;
        unset($filtros['categoria']);
    } else {
        $listado = productos_catalogo($conexion, $busqueda, $categoria_seleccionada, $filtros['pagina'] ?? 1);
        $productos = $listado['productos'];
    }
    unset($filtros['pagina']);
    if ($listado['pagina'] > 1) { $filtros['pagina'] = $listado['pagina']; }
} catch (Throwable $error) {
    error_log('No se pudo cargar el catálogo público: ' . $error->getMessage());
    $error_catalogo = true;
}

$filtros_activos = $busqueda !== '' || $categoria_seleccionada !== null;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Consulta los productos publicados por FERRETERÍA EL CRUCE.">
    <title>Catálogo | FERRETERÍA EL CRUCE</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/estilos.css">
    <link rel="stylesheet" href="assets/css/catalogo.css">
    <script src="assets/js/navegacion.js" defer></script>
    <script src="assets/vendor/bootstrap/bootstrap.bundle.min.js" defer></script>
</head>
<body class="sitio-publico">
    <a class="salto-contenido" href="#contenido">Saltar al contenido</a>

    <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Vistas' . DIRECTORY_SEPARATOR . 'plantillas' . DIRECTORY_SEPARATOR . 'cabecera_publica.php'; ?>

    <main class="catalogo" id="contenido">
        <section class="catalogo__introduccion contenedor" aria-labelledby="titulo-catalogo">
            <h1 id="titulo-catalogo">Catálogo de productos</h1>
            <p>Consulta los productos publicados por la ferretería.</p>
        </section>

        <div class="contenedor">
            <?php if ($filtros_activos): ?>
                <div class="catalogo__filtros-activos" id="filtros" role="region" aria-label="Filtros aplicados">
                    <p>
                        <?php if ($categoria_seleccionada !== null): ?>
                            <span>Categoría: <strong><?= escapar_catalogo($categoria_seleccionada) ?></strong></span>
                        <?php endif; ?>
                        <?php if ($busqueda !== ''): ?>
                            <span>Búsqueda: <strong><?= escapar_catalogo($busqueda) ?></strong></span>
                        <?php endif; ?>
                    </p>
                    <a class="catalogo__limpiar" href="catalogo.php">Quitar filtros <span aria-hidden="true">×</span></a>
                </div>
            <?php endif; ?>

            <section class="catalogo__resultados" id="productos" aria-label="Resultados del catálogo" aria-live="polite">
                <?php if ($error_catalogo): ?>
                    <div class="catalogo__estado catalogo__estado--error" role="alert">
                        <h2>No pudimos cargar el catálogo</h2>
                        <p>El catálogo no está disponible en este momento. Inténtalo más tarde.</p>
                        <a class="catalogo__estado-enlace" href="#contacto">Contactar con la ferretería</a>
                    </div>
                <?php elseif ($categoria_no_disponible): ?>
                    <div class="catalogo__estado" role="status">
                        <h2>La categoría seleccionada no está disponible</h2>
                        <p>Consulta las categorías visibles o busca nuevamente en todo el catálogo.</p>
                        <a class="catalogo__estado-enlace" href="catalogo.php">Ver catálogo</a>
                    </div>
                <?php elseif ($productos === [] && $categoria_seleccionada !== null && $busqueda === ''): ?>
                    <div class="catalogo__estado">
                        <h2>No hay productos publicados en esta categoría</h2>
                        <p>La categoría «<?= escapar_catalogo($categoria_seleccionada) ?>» aún no tiene productos disponibles en el catálogo público.</p>
                        <a class="catalogo__estado-enlace" href="catalogo.php">Ver todo el catálogo</a>
                    </div>
                <?php elseif ($productos === [] && $filtros_activos): ?>
                    <div class="catalogo__estado">
                        <h2>No encontramos coincidencias</h2>
                        <p>No encontramos productos que coincidan con la búsqueda o categoría seleccionada.</p>
                        <a class="catalogo__estado-enlace" href="catalogo.php">Ver todo el catálogo</a>
                    </div>
                <?php elseif ($productos === []): ?>
                    <div class="catalogo__estado catalogo__estado--vacio">
                        <h2>Estamos preparando nuestro catálogo</h2>
                        <p>Aún no hay productos publicados. Mientras tanto, contáctanos para consultar sobre herramientas y materiales para tu proyecto.</p>
                        <a class="catalogo__estado-enlace" href="#contacto">Contactar con la ferretería</a>
                    </div>
                <?php else: ?>
                    <p class="catalogo__resumen">Mostrando <?= ($listado['pagina'] - 1) * 12 + 1 ?>–<?= min($listado['pagina'] * 12, $listado['total']) ?> de <?= $listado['total'] ?> productos</p>
                    <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-4 catalogo__rejilla">
                        <?php foreach ($productos as $producto): ?>
                            <?php $ruta_imagen = ruta_imagen_producto($producto['imagen']);
                            $enlace_detalle = 'producto.php?' . http_build_query(['id' => $producto['id_producto']] + $filtros, '', '&', PHP_QUERY_RFC3986); ?>
                            <div class="col"><article class="card producto h-100">
                                <a href="<?= escapar_catalogo($enlace_detalle) ?>" class="producto__imagen<?= $ruta_imagen === null ? ' producto__imagen--vacia' : '' ?>">
                                    <?php if ($ruta_imagen !== null): ?>
                                        <img src="<?= escapar_catalogo($ruta_imagen) ?>" alt="<?= escapar_catalogo($producto['nombre']) ?>" loading="lazy">
                                    <?php else: ?>
                                        <img src="assets/images/producto_sin_imagen.svg" alt="Sin fotografía disponible" loading="lazy">
                                    <?php endif; ?>
                                </a>
                                <div class="card-body producto__contenido">
                                    <p class="producto__categoria"><?= escapar_catalogo($producto['categoria_nombre']) ?></p>
                                    <h2><a href="<?= escapar_catalogo($enlace_detalle) ?>"><?= escapar_catalogo($producto['nombre']) ?></a></h2>
                                    <?php if (is_string($producto['descripcion']) && trim($producto['descripcion']) !== ''): ?>
                                        <p class="producto__descripcion"><?= nl2br(escapar_catalogo($producto['descripcion'])) ?></p>
                                    <?php endif; ?>
                                    <div class="producto__acciones">
                                        <a class="producto__detalles" href="<?= escapar_catalogo($enlace_detalle) ?>">Ver detalles</a>
                                        <button class="solicitud-boton solicitud-boton--agregar solicitud-boton--icono" type="button" data-agregar-solicitud="<?= (int) $producto['id_producto'] ?>" aria-label="Agregar a solicitud: <?= escapar_catalogo($producto['nombre']) ?>" title="Agregar a solicitud" disabled>
                                            <svg class="solicitud-icono-bolsa" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5.5 8h13l1 12h-15l1-12Z"/><path d="M9 9V6a3 3 0 0 1 6 0v3"/></svg>
                                            <svg class="solicitud-icono-listo" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m5 12 4 4L19 6"/></svg>
                                        </button>
                                    </div>
                                    <a class="producto__consulta" href="<?= escapar_catalogo(whatsapp_producto($producto)) ?>" target="_blank" rel="noopener noreferrer">Consultar por WhatsApp</a>
                                </div>
                            </article></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
            <noscript><p>Activa JavaScript para agregar productos a tu solicitud. Puedes seguir consultando por WhatsApp.</p></noscript>
            <?php if (!$error_catalogo && $listado['paginas'] > 1): ?>
                <nav class="catalogo__paginacion" aria-label="Paginación del catálogo">
                    <?php if ($listado['pagina'] > 1): ?>
                        <a class="catalogo__paso" rel="prev" href="<?= escapar_catalogo(url_catalogo(array_merge($filtros, ['pagina' => $listado['pagina'] - 1]))) ?>#productos">Anterior</a>
                    <?php else: ?><span class="catalogo__paso" aria-disabled="true">Anterior</span><?php endif; ?>
                    <ul class="catalogo__paginas">
                        <?php foreach (paginas_catalogo($listado['pagina'], $listado['paginas']) as $numero): ?>
                            <li>
                                <?php if ($numero === null): ?><span class="catalogo__elipsis"><span aria-hidden="true">…</span><span class="solo-lectores">Páginas intermedias</span></span>
                                <?php elseif ($numero === $listado['pagina']): ?><span class="catalogo__numero" aria-current="page" aria-label="Página <?= $numero ?>, actual"><?= $numero ?></span>
                                <?php else: ?><a class="catalogo__numero" aria-label="Página <?= $numero ?>" href="<?= escapar_catalogo(url_catalogo(array_merge($filtros, ['pagina' => $numero]))) ?>#productos"><?= $numero ?></a><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($listado['pagina'] < $listado['paginas']): ?>
                        <a class="catalogo__paso" rel="next" href="<?= escapar_catalogo(url_catalogo(array_merge($filtros, ['pagina' => $listado['pagina'] + 1]))) ?>#productos">Siguiente</a>
                    <?php else: ?><span class="catalogo__paso" aria-disabled="true">Siguiente</span><?php endif; ?>
                    <p class="catalogo__pagina-actual">Página <?= $listado['pagina'] ?> de <?= $listado['paginas'] ?></p>
                </nav>
            <?php endif; ?>
        </div>
    </main>

    <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Vistas' . DIRECTORY_SEPARATOR . 'plantillas' . DIRECTORY_SEPARATOR . 'pie_publico.php'; ?>
</body>
</html>

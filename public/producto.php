<?php

declare(strict_types=1);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../app/Servicios/catalogo.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD'); http_response_code(405); exit('Método no permitido.');
}
$filtros = filtros_catalogo($_GET);
$termino_busqueda = $filtros['buscar'] ?? '';
$categoria_seleccionada = $filtros['categoria'] ?? null;
$pagina_actual = 'productos';
$categorias_navegacion = [];
$error_categorias_navegacion = false;
$producto = null;
$galeria = [];
$especificaciones = [];
$error_detalle = false;
$id = null;
try { $id = validar_id_categoria($_GET['id'] ?? null); }
catch (InvalidArgumentException $error) { http_response_code(404); }
try {
    $conexion = obtener_conexion();
    $categorias_navegacion = categorias_catalogo($conexion);
} catch (Throwable $error) {
    $error_categorias_navegacion = true;
    error_log('No se pudieron cargar las categorías del detalle.');
}
if ($id !== null) {
    try {
        $conexion = obtener_conexion();
        $producto = producto_publicado($conexion, $id);
        if ($producto === null) { http_response_code(404); }
        else {
            $galeria = galeria_producto_publico($conexion, $producto);
            $especificaciones = leer_especificaciones($producto['especificaciones'] ?? null);
        }
    } catch (Throwable $error) {
        $producto = null;
        $error_detalle = true;
        http_response_code(503);
        error_log('No se pudo consultar el detalle público del producto.');
    }
}
$titulo = $producto['nombre'] ?? ($error_detalle ? 'Producto no disponible' : 'Producto no encontrado');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escapar_catalogo($titulo) ?> | FERRETERÍA EL CRUCE</title>
    <?php if ($producto === null): ?><meta name="robots" content="noindex"><?php endif; ?>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/estilos.css">
    <link rel="stylesheet" href="assets/css/producto.css">
    <script src="assets/js/navegacion.js" defer></script>
    <script src="assets/js/producto.js" defer></script>
</head>
<body class="sitio-publico">
    <a class="salto-contenido" href="#contenido">Saltar al contenido</a>
    <?php require __DIR__ . '/../app/Vistas/plantillas/cabecera_publica.php'; ?>
    <main class="detalle contenedor" id="contenido">
        <a class="detalle__volver" href="<?= escapar_catalogo(url_catalogo($filtros)) ?>#productos">← Volver al catálogo</a>
        <?php if ($producto === null): ?>
            <section class="detalle__seccion" aria-labelledby="titulo-producto">
                <h1 id="titulo-producto"><?= escapar_catalogo($titulo) ?></h1>
                <p><?= $error_detalle ? 'No pudimos cargar el producto. Inténtalo más tarde.' : 'Este producto no está disponible en el catálogo público.' ?></p>
            </section>
        <?php else: ?>
            <article aria-labelledby="titulo-producto">
                <div class="row g-4 g-lg-5 align-items-start">
                    <div class="col-12 col-lg-6">
                        <div class="detalle__galeria" aria-label="Fotografías del producto">
                            <img class="detalle__imagen" id="imagen-principal" src="<?= escapar_catalogo($galeria[0]) ?>" alt="<?= escapar_catalogo($producto['nombre']) ?> — fotografía 1" width="640" height="480">
                            <?php if (count($galeria) > 1): ?>
                                <div class="detalle__miniaturas" aria-label="Seleccionar fotografía">
                                    <?php foreach ($galeria as $indice => $url): ?>
                                        <a class="detalle__miniatura" href="<?= escapar_catalogo($url) ?>" data-fotografia="<?= escapar_catalogo($url) ?>" data-alternativa="<?= escapar_catalogo($producto['nombre']) ?> — fotografía <?= $indice + 1 ?>" aria-controls="imagen-principal" aria-label="Ver fotografía <?= $indice + 1 ?>"<?= $indice === 0 ? ' aria-current="true"' : '' ?>>
                                            <img src="<?= escapar_catalogo($url) ?>" alt="" width="80" height="60">
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                                <p class="solo-lectores" id="estado-galeria" role="status" aria-live="polite"></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-12 col-lg-6 detalle__informacion">
                        <p class="detalle__categoria"><a href="<?= escapar_catalogo(url_catalogo(array_merge($filtros, ['categoria' => $producto['categoria_nombre']]))) ?>"><?= escapar_catalogo($producto['categoria_nombre']) ?></a></p>
                        <h1 id="titulo-producto"><?= escapar_catalogo($producto['nombre']) ?></h1>
                        <p class="detalle__codigo">Código: <strong><?= escapar_catalogo($producto['codigo']) ?></strong></p>
                        <p>Consulta con nuestro equipo sobre este producto.</p>
                        <a class="btn detalle__whatsapp" href="<?= escapar_catalogo(whatsapp_producto($producto)) ?>" target="_blank" rel="noopener noreferrer">Consultar por WhatsApp <span aria-hidden="true">↗</span></a>
                        <p class="detalle__ayuda">Se abrirá WhatsApp con el nombre y código del producto. Tú decides si envías el mensaje.</p>
                    </div>
                </div>
                <?php if (is_string($producto['descripcion']) && trim($producto['descripcion']) !== ''): ?>
                    <section class="detalle__seccion" aria-labelledby="descripcion-titulo">
                        <h2 id="descripcion-titulo">Descripción</h2>
                        <div class="detalle__descripcion"><?= escapar_catalogo($producto['descripcion']) ?></div>
                    </section>
                <?php endif; ?>
                <?php if ($especificaciones !== []): ?>
                    <section class="detalle__seccion" aria-labelledby="especificaciones-titulo">
                        <h2 id="especificaciones-titulo">Especificaciones técnicas</h2>
                        <dl class="detalle__especificaciones">
                            <?php foreach ($especificaciones as $campo => $valor): ?>
                                <div><dt><?= escapar_catalogo(CAMPOS_ESPECIFICACIONES[$campo]) ?></dt><dd><?= escapar_catalogo($valor) ?></dd></div>
                            <?php endforeach; ?>
                        </dl>
                    </section>
                <?php endif; ?>
            </article>
        <?php endif; ?>
    </main>
    <?php require __DIR__ . '/../app/Vistas/plantillas/pie_publico.php'; ?>
</body>
</html>

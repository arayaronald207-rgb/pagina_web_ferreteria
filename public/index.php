<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/conexion.php';

$pagina_actual = 'inicio';
$termino_busqueda = '';
$categorias_portada = [];
$categorias_navegacion = [];
$error_categorias_portada = false;

try {
    // Solo nombres registrados: no se publican conteos ni datos de inventario.
    $consulta = obtener_conexion()->prepare('SELECT nombre FROM categorias ORDER BY nombre ASC');
    $consulta->execute();
    $categorias_navegacion = $consulta->fetchAll();
    $categorias_portada = array_slice($categorias_navegacion, 0, 6);
} catch (Throwable $error) {
    error_log('No se pudieron cargar las categorías de la portada. Código: ' . $error->getCode());
    $error_categorias_portada = true;
}
$error_categorias_navegacion = $error_categorias_portada;

function escapar_portada(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Ferretería El Cruce en Mollejones de Platanares, Pérez Zeledón. Explora nuestro catálogo y consulta por WhatsApp sobre herramientas y materiales para tu proyecto.">
    <title>FERRETERÍA EL CRUCE</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/estilos.css">
    <link rel="stylesheet" href="assets/css/portada.css">
    <script src="assets/js/navegacion.js" defer></script>
    <script src="assets/js/portada.js" defer></script>
</head>
<body class="sitio-publico">
    <a class="salto-contenido" href="#contenido">Saltar al contenido</a>

    <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Vistas' . DIRECTORY_SEPARATOR . 'plantillas' . DIRECTORY_SEPARATOR . 'cabecera_publica.php'; ?>

    <main id="contenido">
        <section class="portada-principal" id="inicio" aria-labelledby="titulo-principal">
            <div class="portada-principal__interior contenedor">
                <div class="portada-principal__contenido">
                    <p class="portada-etiqueta">Tu ferretería en Mollejones</p>
                    <h1 id="titulo-principal">Todo lo que necesitas para tu próximo <span>proyecto.</span></h1>
                    <p class="portada-principal__descripcion">Explora nuestras herramientas, materiales y soluciones para construcción, reparación y mantenimiento.</p>
                    <div class="portada-acciones">
                        <a class="boton boton--principal" href="catalogo.php">Explorar productos <span aria-hidden="true">→</span></a>
                    </div>
                    <button class="portada-cotizacion" type="button" disabled>Solicitar cotización <span>Próximamente</span></button>
                </div>
                <figure class="portada-local">
                    <img src="assets/images/portada_ferre.jpeg"
                         alt="Interior de Ferretería El Cruce con exhibición de herramientas y materiales"
                         width="1280" height="591"
                         loading="eager" fetchpriority="high" decoding="async">
                    <figcaption><span>Conoce nuestra ferretería</span><a href="#contacto">Mollejones, Pérez Zeledón <span aria-hidden="true">↗</span></a></figcaption>
                </figure>
            </div>
        </section>

        <section class="portada-categorias contenedor" aria-labelledby="titulo-categorias" id="categorias-destacadas">
            <div class="portada-seccion-cabecera" data-revelar>
                <div>
                    <p class="portada-etiqueta">Encuentra lo que necesitas</p>
                    <h2 id="titulo-categorias">Explora por categoría</h2>
                    <p>Un punto de partida para tu próximo proyecto.</p>
                </div>
                <a class="portada-enlace" href="catalogo.php">Ver catálogo completo <span aria-hidden="true">→</span></a>
            </div>
            <?php if ($error_categorias_portada || $categorias_portada === []): ?>
                <div class="portada-vacio" data-revelar<?= $error_categorias_portada ? ' role="alert"' : '' ?>>
                    <div>
                        <h3><?= $error_categorias_portada ? 'No pudimos cargar las categorías' : 'Estamos organizando nuestras categorías' ?></h3>
                        <p><?= $error_categorias_portada ? 'Inténtalo más tarde. Encontrarás nuestros datos de contacto al pie de esta página.' : 'Pronto podrás explorar aquí las categorías de nuestro catálogo.' ?></p>
                    </div>
                </div>
            <?php else: ?>
                <div class="portada-categorias__rejilla">
                    <?php foreach ($categorias_portada as $categoria_portada): ?>
                        <a class="categoria-destacada" data-revelar href="catalogo.php?categoria=<?= rawurlencode($categoria_portada['nombre']) ?>#productos">
                            <span class="categoria-destacada__visual" aria-hidden="true"></span>
                            <div class="categoria-destacada__contenido">
                                <span class="categoria-destacada__etiqueta">Explora el catálogo</span>
                                <h3><?= escapar_portada($categoria_portada['nombre']) ?></h3>
                                <span class="categoria-destacada__accion">Explorar categoría <span aria-hidden="true">→</span></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

    </main>

    <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Vistas' . DIRECTORY_SEPARATOR . 'plantillas' . DIRECTORY_SEPARATOR . 'pie_publico.php'; ?>
</body>
</html>

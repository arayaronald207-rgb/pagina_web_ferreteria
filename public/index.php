<?php

declare(strict_types=1);

$pagina_actual = 'inicio';
$termino_busqueda = '';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Ferretería El Cruce: herramientas y soluciones para construcción y hogar. Catálogo digital en preparación.">
    <title>FERRETERÍA EL CRUCE</title>
    <link rel="stylesheet" href="assets/css/estilos.css">
    <script src="assets/js/navegacion.js" defer></script>
</head>
<body>
    <a class="salto-contenido" href="#contenido">Saltar al contenido</a>

    <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Vistas' . DIRECTORY_SEPARATOR . 'plantillas' . DIRECTORY_SEPARATOR . 'cabecera_publica.php'; ?>

    <main id="contenido">
        <section class="hero contenedor" id="inicio" aria-labelledby="titulo-principal">
            <div class="hero__contenido">
                <h1 id="titulo-principal">Todo lo que necesitas para tu próximo <span>proyecto.</span></h1>
                <p class="hero__descripcion">Explora nuestras herramientas, materiales y soluciones para construcción, reparación y mantenimiento.</p>

                <div class="hero__acciones">
                    <a class="boton boton--principal" href="catalogo.php"><span>Explorar productos</span></a>
                    <button class="boton boton--secundario" type="button" disabled>
                        <span>Solicitar cotización</span><span class="estado-boton">Próximamente</span>
                    </button>
                </div>

                <p class="hero__nota"><span aria-hidden="true"></span> Catálogo digital en preparación</p>
            </div>

            <div class="hero__fotografia" role="img" aria-label="Espacio reservado para una fotografía comercial de herramientas" data-ruta_imagen="public/assets/images/portada_herramientas.webp"></div>
        </section>
    </main>

    <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Vistas' . DIRECTORY_SEPARATOR . 'plantillas' . DIRECTORY_SEPARATOR . 'pie_publico.php'; ?>
</body>
</html>

<?php

$pagina_actual = $pagina_actual ?? 'inicio';
$termino_busqueda = isset($termino_busqueda) && is_string($termino_busqueda) ? $termino_busqueda : '';
$termino_busqueda_html = htmlspecialchars($termino_busqueda, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<header class="cabecera">
    <div class="cabecera__interior contenedor">
        <a class="marca" href="index.php#inicio" aria-label="FERRETERÍA EL CRUCE, inicio">
            <img class="marca__logo" src="assets/images/logo_ferreteria.png" alt="Logotipo oficial de FERRETERÍA EL CRUCE">
        </a>

        <nav class="navegacion" id="navegacion-principal" aria-label="Navegación principal">
            <div class="navegacion__lista">
                <a class="navegacion__enlace<?= $pagina_actual === 'inicio' ? ' navegacion__enlace--actual' : '' ?>" href="index.php#inicio"<?= $pagina_actual === 'inicio' ? ' aria-current="page"' : '' ?>>Inicio</a>
                <a class="navegacion__enlace<?= $pagina_actual === 'productos' ? ' navegacion__enlace--actual' : '' ?>" href="catalogo.php#productos"<?= $pagina_actual === 'productos' ? ' aria-current="page"' : '' ?>>Productos</a>
                <a class="navegacion__enlace" href="catalogo.php#filtros">Categorías</a>
                <a class="navegacion__enlace" href="index.php#contacto">Contacto</a>
            </div>
            <form class="buscador" role="search" aria-label="Buscar productos" method="get" action="catalogo.php">
                <label class="solo-lectores" for="busqueda_cabecera">Buscar productos por nombre</label>
                <span class="buscador__lupa" aria-hidden="true"></span>
                <input id="busqueda_cabecera" name="buscar" type="search" placeholder="Buscar productos" value="<?= $termino_busqueda_html ?>" maxlength="120">
                <button type="submit" aria-label="Buscar productos"></button>
            </form>
        </nav>

        <div class="cabecera__acciones">
            <button class="boton-lista" type="button" disabled aria-label="Lista de cotización, pendiente">
                <span class="boton-lista__icono" aria-hidden="true"></span>
                <span class="boton-lista__texto">Mi cotización <small>Pendiente</small></span>
            </button>
            <button class="boton-menu" type="button" aria-expanded="false" aria-controls="navegacion-principal" aria-label="Abrir menú">
                <span></span><span></span><span></span>
            </button>
        </div>
    </div>
</header>

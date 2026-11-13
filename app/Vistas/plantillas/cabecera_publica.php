<?php

$pagina_actual = $pagina_actual ?? 'inicio';
$termino_busqueda = isset($termino_busqueda) && is_string($termino_busqueda) ? $termino_busqueda : '';
$termino_busqueda_html = htmlspecialchars($termino_busqueda, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<header class="cabecera">
    <div class="cabecera__interior contenedor">
        <button class="boton-menu" type="button" aria-expanded="false" aria-controls="panel-navegacion" aria-label="Abrir menú">
            <span></span><span></span><span></span>
        </button>
        <a class="marca" href="index.php#inicio" aria-label="FERRETERÍA EL CRUCE, inicio">
            <img class="marca__logo" src="assets/images/logo_ferreteria.png" alt="Logotipo oficial de FERRETERÍA EL CRUCE">
        </a>

        <form class="buscador" role="search" aria-label="Buscar productos" method="get" action="catalogo.php">
            <label class="solo-lectores" for="busqueda_cabecera">Buscar productos por nombre</label>
            <input id="busqueda_cabecera" name="buscar" type="search" placeholder="¿Qué necesitas para tu proyecto?" value="<?= $termino_busqueda_html ?>" maxlength="120">
            <button type="submit" aria-label="Buscar productos">
                <span class="buscador__lupa" aria-hidden="true"></span>
                <span class="buscador__texto">Buscar</span>
            </button>
        </form>

        <div class="cabecera__acciones">
            <button class="boton-lista" type="button" disabled aria-label="Lista de cotización, pendiente">
                <span class="boton-lista__icono" aria-hidden="true"></span>
                <span class="boton-lista__texto">Mi cotización <small>Pendiente</small></span>
            </button>
        </div>
    </div>

    <div class="menu-fondo" aria-hidden="true"></div>
    <div class="menu-panel" id="panel-navegacion">
        <div class="menu-panel__cabecera">
            <span id="titulo-menu">Menú principal</span>
            <button class="menu-panel__cerrar" type="button" aria-label="Cerrar menú"><span aria-hidden="true">×</span></button>
        </div>
        <nav class="navegacion contenedor" id="navegacion-principal" aria-label="Navegación principal">
            <div class="navegacion__lista">
                <a class="navegacion__enlace<?= $pagina_actual === 'inicio' ? ' navegacion__enlace--actual' : '' ?>" href="index.php#inicio"<?= $pagina_actual === 'inicio' ? ' aria-current="page"' : '' ?>>Inicio</a>
                <a class="navegacion__enlace<?= $pagina_actual === 'productos' ? ' navegacion__enlace--actual' : '' ?>" href="catalogo.php#productos"<?= $pagina_actual === 'productos' ? ' aria-current="page"' : '' ?>>Productos</a>
                <details class="navegacion__categorias">
                    <summary class="navegacion__enlace">Categorías <span class="navegacion__flecha" aria-hidden="true"></span></summary>
                    <div class="navegacion__submenu">
                        <a href="catalogo.php#filtros">Ver categorías del catálogo <span aria-hidden="true">→</span></a>
                    </div>
                </details>
                <a class="navegacion__enlace" href="index.php#contacto">Contacto</a>
            </div>
            <a class="navegacion__consulta" href="https://wa.me/50670988980" target="_blank" rel="noopener noreferrer">Consultar por WhatsApp <span aria-hidden="true">↗</span></a>
        </nav>
    </div>
</header>

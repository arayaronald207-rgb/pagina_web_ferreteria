<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= escapar_panel($titulo_pagina) ?> | FERRETERÍA EL CRUCE</title>
    <link rel="stylesheet" href="assets/css/estilos.css">
    <link rel="stylesheet" href="assets/css/panel_administrativo.css">
</head>
<body class="panel-cuerpo">
<a class="salto-contenido" href="#contenido">Saltar al contenido</a>
<header class="panel-cabecera">
    <div class="panel-ancho panel-cabecera__interior">
        <a class="panel-marca" href="administracion.php" aria-label="FERRETERÍA EL CRUCE, panel administrativo">
            <img src="assets/images/logo_ferreteria.png" alt="Logotipo oficial de FERRETERÍA EL CRUCE" width="76" height="64">
            <span>FERRETERÍA EL CRUCE<small>Administración</small></span>
        </a>
        <div class="panel-cuenta">
            <span><strong><?= escapar_panel($usuario['nombre']) ?></strong><small><?= $usuario['rol'] === 'administrador' ? 'Administrador' : 'Empleado' ?></small></span>
            <form action="cerrar_sesion.php" method="post">
                <input type="hidden" name="token_csrf" value="<?= escapar_panel(obtener_token_csrf()) ?>">
                <button class="panel-boton panel-boton--secundario" type="submit">Cerrar sesión</button>
            </form>
        </div>
    </div>
</header>
<div class="panel-ancho panel-estructura">
    <aside class="panel-lateral">
        <p class="panel-etiqueta">ESPACIO DE TRABAJO</p>
        <nav class="panel-navegacion" aria-label="Administración">
            <a href="administracion.php"<?= $seccion_actual === 'resumen' ? ' aria-current="page"' : '' ?>>Resumen</a>
            <a href="categorias.php"<?= $seccion_actual === 'categorias' ? ' aria-current="page"' : '' ?>>Categorías</a>
            <span>Productos <small>Pendiente</small></span>
            <span>Solicitudes <small>Pendiente</small></span>
            <?php if ($usuario['rol'] === 'administrador'): ?>
                <span>Usuarios <small>Pendiente</small></span>
            <?php endif; ?>
        </nav>
        <a class="panel-volver" href="index.php">Ver sitio público ↗</a>
    </aside>
    <main class="panel-contenido" id="contenido">

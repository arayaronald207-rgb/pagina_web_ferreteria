<?php

declare(strict_types=1);
require_once __DIR__ . '/../app/Servicios/panel_administrativo.php';
[$conexion, $usuario] = iniciar_panel();
$titulo_pagina = 'Resumen administrativo';
$seccion_actual = 'resumen';
$indicadores = null;
try {
    $indicadores = indicadores_panel($conexion);
} catch (Throwable $error) {
    error_log('No se pudieron consultar los indicadores administrativos.');
    http_response_code(503);
}
require __DIR__ . '/../app/Vistas/plantillas/cabecera_administrativa.php';
?>
<div class="panel-titulo">
    <p class="panel-etiqueta">PANEL ADMINISTRATIVO</p>
    <h1>Resumen del negocio</h1>
    <p>Consulta la actividad y organiza el catálogo de la ferretería.</p>
</div>
<?php if ($indicadores === null): ?>
    <p class="panel-aviso panel-aviso--error" role="alert">No fue posible cargar los indicadores. Actualiza la página más tarde.</p>
<?php endif; ?>
<section class="panel-indicadores" aria-label="Indicadores del negocio">
    <article class="panel-indicador"><p>Productos</p><strong><?= $indicadores === null ? '—' : escapar_panel($indicadores['productos']) ?></strong><span>Total de artículos registrados, incluidos los ocultos.</span></article>
    <article class="panel-indicador"><p>Categorías</p><strong><?= $indicadores === null ? '—' : escapar_panel($indicadores['categorias']) ?></strong><a href="categorias.php">Gestionar categorías →</a></article>
    <article class="panel-indicador panel-indicador--atencion"><p>Solicitudes pendientes</p><strong><?= $indicadores === null ? '—' : escapar_panel($indicadores['pendientes']) ?></strong><span>Solicitudes con estado Pendiente.</span></article>
</section>
<section class="panel-tarjeta">
    <h2>Organiza tu catálogo</h2>
    <p>Crea y actualiza las categorías que agrupan los productos. Las categorías con artículos asociados se conservan para proteger el catálogo.</p>
    <a class="panel-boton" href="categorias.php">Administrar categorías</a>
</section>
<section class="panel-tarjeta panel-proximamente">
    <p class="panel-etiqueta">PRÓXIMAS SECCIONES</p>
    <h2>Un espacio para cada tarea</h2>
    <ul>
        <li><strong>Productos</strong><span>Administración de artículos y fotografías.</span><small>Pendiente</small></li>
        <li><strong>Solicitudes</strong><span>Revisión y seguimiento de proformas.</span><small>Pendiente</small></li>
        <?php if ($usuario['rol'] === 'administrador'): ?>
            <li><strong>Usuarios</strong><span>Gestión de cuentas, exclusiva del administrador.</span><small>Pendiente</small></li>
        <?php endif; ?>
    </ul>
</section>
<?php require __DIR__ . '/../app/Vistas/plantillas/pie_administrativo.php'; ?>

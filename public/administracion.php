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
<?php if (is_string($_SESSION['mensaje_cuenta'] ?? null)): ?>
    <p class="alert alert-success panel-aviso" role="status"><?= escapar_panel($_SESSION['mensaje_cuenta']) ?></p>
    <?php unset($_SESSION['mensaje_cuenta']); ?>
<?php endif; ?>
<div class="panel-titulo">
    <p class="panel-etiqueta">PANEL ADMINISTRATIVO</p>
    <h1>Resumen del negocio</h1>
    <p>Consulta la actividad y organiza el catálogo de la ferretería.</p>
</div>
<?php if ($indicadores === null): ?>
    <p class="alert alert-danger panel-aviso panel-aviso--error" role="alert">No fue posible cargar los indicadores. Actualiza la página más tarde.</p>
<?php endif; ?>
<section class="row row-cols-1 row-cols-md-3 g-3 panel-indicadores" aria-label="Indicadores del negocio">
    <div class="col"><article class="card panel-indicador h-100"><p>Productos</p><strong><?= $indicadores === null ? '—' : escapar_panel($indicadores['productos']) ?></strong><a href="productos.php">Gestionar productos →</a></article></div>
    <div class="col"><article class="card panel-indicador h-100"><p>Categorías</p><strong><?= $indicadores === null ? '—' : escapar_panel($indicadores['categorias']) ?></strong><a href="categorias.php">Gestionar categorías →</a></article></div>
    <div class="col"><article class="card panel-indicador h-100 panel-indicador--atencion"><p>Solicitudes pendientes</p><strong><?= $indicadores === null ? '—' : escapar_panel($indicadores['pendientes']) ?></strong><a href="solicitudes.php?estado=Pendiente">Revisar solicitudes pendientes →</a></article></div>
</section>
<section class="card panel-tarjeta">
    <h2>Organiza tu catálogo</h2>
    <p>Crea y actualiza las categorías que agrupan los productos. Las categorías con artículos asociados se conservan para proteger el catálogo.</p>
    <a class="btn btn-primary panel-boton" href="categorias.php">Administrar categorías</a>
</section>
<section class="card panel-tarjeta">
    <h2>Atiende las solicitudes de proforma</h2>
    <p>Consulta los datos del cliente, revisa los productos solicitados y actualiza el estado de atención.</p>
    <a class="btn btn-primary panel-boton" href="solicitudes.php">Gestionar solicitudes</a>
</section>
<?php if ($usuario['rol'] === 'administrador'): ?>
<section class="card panel-tarjeta">
    <h2>Usuarios y permisos</h2>
    <p>Gestiona las cuentas del equipo, sus roles y su acceso al sistema.</p>
    <a class="btn btn-primary panel-boton" href="usuarios.php">Administrar usuarios</a>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../app/Vistas/plantillas/pie_administrativo.php'; ?>

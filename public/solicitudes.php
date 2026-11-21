<?php

declare(strict_types=1);
require_once __DIR__ . '/../app/Servicios/panel_administrativo.php';
require_once __DIR__ . '/../app/Servicios/solicitudes.php';
[$conexion, $usuario] = iniciar_panel();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET'); http_response_code(405); exit('Método no permitido.');
}
$titulo_pagina = 'Solicitudes';
$seccion_actual = 'solicitudes';
$error_formulario = '';
$filtros = ['buscar' => '', 'estado' => '', 'pagina' => 1];
$listado = null;
try {
    $filtros = filtros_solicitudes($_GET);
    $listado = listar_solicitudes($conexion, $filtros);
} catch (InvalidArgumentException $error) {
    http_response_code(422); $error_formulario = $error->getMessage();
} catch (Throwable $error) {
    http_response_code(503); error_log('No se pudo consultar el listado de solicitudes.');
    $error_formulario = 'No fue posible cargar las solicitudes. Inténtalo nuevamente más tarde.';
}
require __DIR__ . '/../app/Vistas/plantillas/cabecera_administrativa.php';
require __DIR__ . '/../app/Vistas/solicitudes/listado.php';
require __DIR__ . '/../app/Vistas/plantillas/pie_administrativo.php';

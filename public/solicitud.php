<?php

declare(strict_types=1);
require_once __DIR__ . '/../app/Servicios/panel_administrativo.php';
require_once __DIR__ . '/../app/Servicios/solicitudes.php';
[$conexion, $usuario] = iniciar_panel();
header('Referrer-Policy: no-referrer');
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($metodo, ['GET', 'POST'], true)) { header('Allow: GET, POST'); http_response_code(405); exit('Método no permitido.'); }
$titulo_pagina = 'Detalle de solicitud';
$seccion_actual = 'solicitudes';
$error_formulario = '';
$solicitud = null;
$productos = [];
$confirmar = null;
$token_confirmacion = null;
$mensaje = '';
$id = null;
$filtros = ['buscar' => '', 'estado' => '', 'pagina' => 1];
$_SESSION['confirmaciones_estado_solicitud'] ??= [];
$confirmaciones = &$_SESSION['confirmaciones_estado_solicitud'];
try {
    $filtros = filtros_solicitudes($_GET);
    $id = validar_id_solicitud($_GET['id'] ?? null);
    if ($metodo === 'POST') {
        if (!validar_token_csrf($_POST['token_csrf'] ?? null)) {
            http_response_code(403);
            $error_formulario = 'La sesión del formulario venció o no es válida. Actualiza la página antes de volver a intentarlo.';
        } elseif (($_POST['accion'] ?? null) === 'revisar_estado') {
            $token_confirmacion = preparar_estado_solicitud($conexion, $id, $_POST['estado'] ?? null, $confirmaciones);
            $confirmar = $confirmaciones[$token_confirmacion];
        } elseif (($_POST['accion'] ?? null) === 'guardar_estado') {
            $nuevo = confirmar_estado_solicitud($conexion, $id, $_POST['confirmacion'] ?? null, $confirmaciones);
            $_SESSION['mensaje_solicitud'] = ['id' => $id, 'texto' => 'Solicitud n.º ' . $id . ': estado actualizado a ' . $nuevo . '.'];
            header('Location: solicitud.php?' . http_build_query(['id' => $id] + $filtros, '', '&', PHP_QUERY_RFC3986), true, 303);
            exit;
        } else { throw new InvalidArgumentException('La operación solicitada no es válida.'); }
    }
} catch (InvalidArgumentException $error) {
    http_response_code(422); $error_formulario = $error->getMessage();
} catch (DomainException $error) {
    http_response_code(409); $error_formulario = $error->getMessage();
} catch (Throwable $error) {
    http_response_code(503); error_log('No se pudo actualizar el estado de una solicitud.');
    $error_formulario = 'No fue posible actualizar el estado. Revisa el estado actual antes de volver a intentarlo.';
}
if ($id !== null) {
    try {
        $solicitud = buscar_solicitud($conexion, $id);
        if ($solicitud === null) { http_response_code(404); $error_formulario = 'La solicitud no existe o ya no está disponible.'; }
        else { $productos = detalle_solicitud_panel($conexion, $id); }
    } catch (Throwable $error) {
        $solicitud = null; http_response_code(503); error_log('No se pudo cargar el detalle de una solicitud.');
        $error_formulario = 'No fue posible cargar la solicitud. Inténtalo nuevamente más tarde.';
    }
    $aviso = $_SESSION['mensaje_solicitud'] ?? null;
    if (is_array($aviso) && ($aviso['id'] ?? null) === $id && $metodo === 'GET') {
        $mensaje = $aviso['texto']; unset($_SESSION['mensaje_solicitud']);
    }
}
$url_detalle = 'solicitud.php?' . http_build_query(['id' => $id] + $filtros, '', '&', PHP_QUERY_RFC3986);
require __DIR__ . '/../app/Vistas/plantillas/cabecera_administrativa.php';
require __DIR__ . '/../app/Vistas/solicitudes/detalle.php';
require __DIR__ . '/../app/Vistas/plantillas/pie_administrativo.php';

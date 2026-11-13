<?php

declare(strict_types=1);

require_once __DIR__ . '/autenticacion.php';
require_once __DIR__ . '/../../config/conexion.php';

function escapar_panel(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function iniciar_panel(): array
{
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    $usuario = exigir_autenticacion(['administrador', 'empleado']);

    try {
        $conexion = obtener_conexion();
        $consulta = $conexion->prepare(
            'SELECT id_usuario, nombre, correo, rol, activo FROM usuarios WHERE id_usuario = :id LIMIT 1'
        );
        $consulta->execute(['id' => (int) $usuario['id_usuario']]);
        $cuenta = $consulta->fetch();
    } catch (Throwable $error) {
        error_log('No se pudo verificar el acceso al panel administrativo.');
        http_response_code(503);
        exit('El panel no está disponible en este momento. Inténtalo más tarde.');
    }

    // Una cuenta desactivada o un cambio de rol exige volver a autenticarse.
    if (!$cuenta || (int) $cuenta['activo'] !== 1
        || !in_array($cuenta['rol'], ['administrador', 'empleado'], true)
        || $cuenta['rol'] !== $usuario['rol']) {
        cerrar_sesion_completa();
        header('Location: iniciar_sesion.php', true, 303);
        exit;
    }

    return [$conexion, $cuenta];
}

function indicadores_panel(PDO $conexion): array
{
    $consulta = $conexion->prepare(
        'SELECT (SELECT COUNT(*) FROM productos) AS productos,
            (SELECT COUNT(*) FROM categorias) AS categorias,
            (SELECT COUNT(*) FROM solicitudes WHERE estado = :estado) AS pendientes'
    );
    $consulta->execute(['estado' => 'Pendiente']);
    return $consulta->fetch();
}

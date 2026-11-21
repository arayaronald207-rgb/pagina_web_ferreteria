<?php

declare(strict_types=1);

require_once __DIR__ . '/autenticacion.php';
require_once __DIR__ . '/../../config/conexion.php';

function escapar_panel(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cuenta_panel_vigente(array $usuario, mixed $cuenta, mixed $credencial): bool
{
    return is_array($cuenta) && (int) $cuenta['activo'] === 1
        && in_array($cuenta['rol'], ['administrador', 'empleado'], true)
        && $cuenta['rol'] === $usuario['rol']
        && ($credencial === null || (is_string($credencial) && hash_equals($credencial, hash('sha256', $cuenta['clave_hash']))));
}

function iniciar_panel(array $roles_permitidos = ['administrador', 'empleado']): array
{
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    $usuario = exigir_autenticacion($roles_permitidos);

    try {
        $conexion = obtener_conexion();
        $consulta = $conexion->prepare(
            'SELECT id_usuario, nombre, correo, rol, activo, clave_hash FROM usuarios WHERE id_usuario = :id LIMIT 1'
        );
        $consulta->execute(['id' => (int) $usuario['id_usuario']]);
        $cuenta = $consulta->fetch();
    } catch (Throwable $error) {
        error_log('No se pudo verificar el acceso al panel administrativo.');
        http_response_code(503);
        exit('El panel no está disponible en este momento. Inténtalo más tarde.');
    }

    // Una cuenta desactivada o un cambio de rol exige volver a autenticarse.
    if (!cuenta_panel_vigente($usuario, $cuenta, $_SESSION['credencial_usuario'] ?? null)) {
        cerrar_sesion_completa();
        header('Location: iniciar_sesion.php', true, 303);
        exit;
    }

    // Conserva las sesiones previas a este módulo y vincula las siguientes
    // comprobaciones a la contraseña actual. El hash nunca llega a las vistas.
    $_SESSION['credencial_usuario'] = hash('sha256', $cuenta['clave_hash']);
    unset($cuenta['clave_hash']);
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

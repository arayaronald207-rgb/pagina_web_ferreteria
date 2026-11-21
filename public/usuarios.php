<?php

declare(strict_types=1);
require_once __DIR__ . '/../app/Servicios/panel_administrativo.php';
require_once __DIR__ . '/../app/Servicios/usuarios.php';
[$conexion, $usuario] = iniciar_panel(['administrador']);
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($metodo, ['GET', 'POST'], true)) { header('Allow: GET, POST'); http_response_code(405); exit('Método no permitido.'); }
$titulo_pagina = 'Usuarios'; $seccion_actual = 'usuarios';
$error_formulario = ''; $formulario = null; $modo = ''; $confirmar = null; $token_confirmacion = null;
$mensaje = $_SESSION['mensaje_usuarios'] ?? ''; unset($_SESSION['mensaje_usuarios']);
$cuentas = null;
$actor = (int) $usuario['id_usuario'];
$_SESSION['cambios_usuario'] ??= [];
$pendientes = &$_SESSION['cambios_usuario'];
try {
    if ($metodo === 'POST') {
        if (!validar_token_csrf($_POST['token_csrf'] ?? null)) {
            http_response_code(403); $error_formulario = 'La sesión del formulario venció o no es válida. Actualiza la página y vuelve a intentarlo.';
        } elseif (($_POST['accion'] ?? null) === 'revisar') {
            $modo = in_array($_POST['operacion'] ?? null, ['crear', 'editar', 'clave'], true) ? $_POST['operacion'] : '';
            if ($modo !== '') {
                $formulario = ['id_usuario' => $modo === 'crear' ? null : validar_id_usuario($_POST['id_usuario'] ?? null), 'nombre' => '', 'correo' => '', 'rol' => 'empleado'];
                foreach (['nombre', 'correo', 'rol'] as $campo) {
                    if (is_string($_POST[$campo] ?? null)) { $formulario[$campo] = mb_substr($_POST[$campo], 0, 150, 'UTF-8'); }
                }
                if ($modo === 'clave') { $formulario = buscar_usuario_panel($conexion, $formulario['id_usuario']); }
            }
            $token_confirmacion = preparar_cambio_usuario($conexion, $actor, $_POST, $pendientes);
            $confirmar = $pendientes[$token_confirmacion]; $formulario = null;
        } elseif (($_POST['accion'] ?? null) === 'confirmar') {
            $token_confirmacion = $_POST['confirmacion'] ?? null;
            $confirmar = obtener_cambio_usuario($pendientes, $token_confirmacion, $actor);
            if (actualizar_limite_inicio_sesion('consultar') > 0) {
                throw new InvalidArgumentException('Se alcanzó el límite de intentos de contraseña. Espera unos minutos antes de confirmar.');
            }
            $resultado = confirmar_cambio_usuario($conexion, $actor, $token_confirmacion, $_POST['clave_actual'] ?? null, $pendientes);
            // El registro ya está confirmado: un fallo del contador de intentos no debe ocultar el éxito.
            try { actualizar_limite_inicio_sesion('reiniciar'); } catch (Throwable $error) { error_log('No se pudo reiniciar el contador de intentos.'); }
            if ($resultado['credencial_propia'] !== null) { $_SESSION['credencial_usuario'] = $resultado['credencial_propia']; }
            if ($resultado['id'] === $actor && $resultado['operacion'] === 'editar') {
                foreach (['nombre', 'correo', 'rol'] as $campo) { $_SESSION['usuario'][$campo] = $confirmar['datos'][$campo]; }
            }
            $texto = match ($resultado['operacion']) {
                'crear' => 'Cuenta creada correctamente.', 'editar' => 'Datos y rol de la cuenta actualizados.',
                'clave' => 'Contraseña actualizada correctamente.',
                default => $confirmar['datos']['activo'] === 1 ? 'Cuenta activada correctamente.' : 'Cuenta desactivada correctamente.',
            };
            if ($resultado['rol_propio'] === 'empleado') {
                $_SESSION['cambios_usuario'] = [];
                $_SESSION['mensaje_cuenta'] = $texto . ' Tu cuenta ahora tiene permisos de empleado.';
                session_regenerate_id(true);
                header('Location: administracion.php', true, 303); exit;
            }
            $_SESSION['mensaje_usuarios'] = $texto;
            header('Location: usuarios.php', true, 303); exit;
        } elseif (($_POST['accion'] ?? null) === 'cancelar') {
            $token = $_POST['confirmacion'] ?? null;
            obtener_cambio_usuario($pendientes, $token, $actor);
            unset($pendientes[$token]);
            $_SESSION['mensaje_usuarios'] = 'Operación cancelada. No se guardaron cambios.';
            header('Location: usuarios.php', true, 303); exit;
        } else { throw new InvalidArgumentException('La operación solicitada no es válida.'); }
    } elseif (isset($_GET['nuevo'])) {
        $modo = 'crear'; $formulario = ['id_usuario' => null, 'nombre' => '', 'correo' => '', 'rol' => 'empleado'];
    } elseif (isset($_GET['editar']) || isset($_GET['clave'])) {
        $modo = isset($_GET['editar']) ? 'editar' : 'clave';
        $id = validar_id_usuario($_GET[$modo] ?? null);
        $formulario = buscar_usuario_panel($conexion, $id);
        if (!$formulario) { http_response_code(404); $error_formulario = 'La cuenta no existe o ya no está disponible.'; }
    }
} catch (CredencialAdministradorInvalida $error) {
    try { actualizar_limite_inicio_sesion('fallo'); } catch (Throwable $fallo) { error_log('No se pudo registrar el intento de confirmación.'); }
    http_response_code(422); $error_formulario = $error->getMessage();
} catch (PermisoUsuariosDenegado $error) {
    http_response_code(403); $error_formulario = $error->getMessage(); $confirmar = null; $formulario = null;
} catch (InvalidArgumentException | DomainException $error) {
    http_response_code($error instanceof DomainException ? 409 : 422); $error_formulario = $error->getMessage();
} catch (Throwable $error) {
    http_response_code(503); error_log('No se pudo completar una operación de usuarios.');
    $error_formulario = 'No fue posible completar la operación. Revisa el listado antes de volver a intentarlo.';
}
if ($confirmar !== null && (!is_string($token_confirmacion) || !isset($pendientes[$token_confirmacion]))) { $confirmar = null; }
try { $cuentas = listar_usuarios($conexion); }
catch (Throwable $error) { http_response_code(503); $error_formulario = 'No fue posible cargar las cuentas. Inténtalo más tarde.'; }
require __DIR__ . '/../app/Vistas/plantillas/cabecera_administrativa.php';
require __DIR__ . '/../app/Vistas/usuarios.php';
require __DIR__ . '/../app/Vistas/plantillas/pie_administrativo.php';

<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Servicios/autenticacion.php';
require_once __DIR__ . '/../config/conexion.php';

iniciar_sesion_segura();
header('Cache-Control: no-store, private');

if (obtener_usuario_actual() !== null) {
    header('Location: administracion.php', true, 303);
    exit;
}

$correo_ingresado = '';
$mensaje_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo_recibido = $_POST['correo'] ?? '';
    $clave_recibida = $_POST['contrasena'] ?? '';
    $correo_ingresado = is_string($correo_recibido) ? trim($correo_recibido) : '';

    if (!validar_token_csrf($_POST['token_csrf'] ?? null)) {
        http_response_code(400);
        $mensaje_error = 'La solicitud venció. Actualiza la página e inténtalo nuevamente.';
    } else {
        try {
            $segundos_espera = actualizar_limite_inicio_sesion('consultar');

            if ($segundos_espera > 0) {
                $mensaje_error = 'No se pudo iniciar sesión. Inténtalo nuevamente más tarde.';
            } else {
                $correo_valido = is_string($correo_recibido)
                    && strlen($correo_ingresado) <= 150
                    && filter_var($correo_ingresado, FILTER_VALIDATE_EMAIL) !== false;
                $clave_valida = is_string($clave_recibida)
                    && $clave_recibida !== ''
                    && strlen($clave_recibida) <= 4096;
                $usuario = null;

                if ($correo_valido) {
                    $conexion = obtener_conexion();
                    $consulta_usuario = $conexion->prepare(
                        'SELECT id_usuario, nombre, correo, clave_hash, rol, activo
                        FROM usuarios
                        WHERE correo = :correo
                        LIMIT 1'
                    );
                    $consulta_usuario->bindValue(':correo', $correo_ingresado, PDO::PARAM_STR);
                    $consulta_usuario->execute();
                    $usuario = $consulta_usuario->fetch();
                }

                $hash_simulado = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
                $hash_verificacion = is_array($usuario) && is_string($usuario['clave_hash'])
                    ? $usuario['clave_hash']
                    : $hash_simulado;
                $clave_correcta = password_verify($clave_valida ? $clave_recibida : '', $hash_verificacion);
                $usuario_valido = is_array($usuario)
                    && $clave_correcta
                    && (int) $usuario['activo'] === 1
                    && in_array($usuario['rol'], ['administrador', 'empleado'], true);

                if ($usuario_valido) {
                    actualizar_limite_inicio_sesion('reiniciar');
                    establecer_sesion_usuario($usuario);
                    header('Location: administracion.php', true, 303);
                    exit;
                }

                actualizar_limite_inicio_sesion('fallo');
                $mensaje_error = 'No se pudo iniciar sesión. Verifica tus datos e inténtalo nuevamente.';
            }
        } catch (Throwable $error) {
            error_log('No se pudo procesar el inicio de sesión.');
            $mensaje_error = 'No fue posible procesar el inicio de sesión. Inténtalo más tarde.';
        }
    }
}

$correo_html = htmlspecialchars($correo_ingresado, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$token_csrf_html = htmlspecialchars(obtener_token_csrf(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$mensaje_error_html = htmlspecialchars($mensaje_error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Acceso del personal | FERRETERÍA EL CRUCE</title>
    <link rel="stylesheet" href="assets/css/estilos.css">
    <link rel="stylesheet" href="assets/css/acceso_administrativo.css">
</head>
<body class="acceso-cuerpo">
    <main class="acceso-contenedor">
        <a class="acceso-marca" href="index.php" aria-label="Volver a FERRETERÍA EL CRUCE">
            <img src="assets/images/logo_ferreteria.png" alt="Logotipo oficial de FERRETERÍA EL CRUCE">
        </a>
        <section class="acceso-tarjeta" aria-labelledby="titulo-acceso">
            <p class="acceso-ceja">ÁREA PRIVADA</p>
            <h1 id="titulo-acceso">Acceso del personal</h1>
            <p class="acceso-descripcion">Ingresa con tu cuenta autorizada.</p>

            <?php if ($mensaje_error !== ''): ?>
                <p class="acceso-error" role="alert"><?= $mensaje_error_html ?></p>
            <?php endif; ?>

            <form class="acceso-formulario" method="post" action="iniciar_sesion.php">
                <input type="hidden" name="token_csrf" value="<?= $token_csrf_html ?>">
                <div class="acceso-campo">
                    <label for="correo">Correo electrónico</label>
                    <input id="correo" name="correo" type="email" autocomplete="username" maxlength="150" required value="<?= $correo_html ?>">
                </div>
                <div class="acceso-campo">
                    <label for="contrasena">Contraseña</label>
                    <input id="contrasena" name="contrasena" type="password" autocomplete="current-password" maxlength="1024" required>
                </div>
                <button class="acceso-boton" type="submit">Iniciar sesión</button>
            </form>
        </section>
        <a class="acceso-volver" href="index.php">Volver al sitio público</a>
    </main>
</body>
</html>

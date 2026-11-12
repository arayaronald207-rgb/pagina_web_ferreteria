<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Servicios/autenticacion.php';

header('Cache-Control: no-store, private');
$usuario = exigir_autenticacion(['administrador', 'empleado']);
$nombre_html = htmlspecialchars($usuario['nombre'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$rol = $usuario['rol'] === 'administrador' ? 'Administrador' : 'Empleado';
$rol_html = htmlspecialchars($rol, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$token_csrf_html = htmlspecialchars(obtener_token_csrf(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Administración | FERRETERÍA EL CRUCE</title>
    <link rel="stylesheet" href="assets/css/estilos.css">
    <link rel="stylesheet" href="assets/css/acceso_administrativo.css">
</head>
<body class="acceso-cuerpo">
    <main class="acceso-contenedor administracion-contenedor">
        <a class="acceso-marca" href="index.php" aria-label="Volver a FERRETERÍA EL CRUCE">
            <img src="assets/images/logo_ferreteria.png" alt="Logotipo oficial de FERRETERÍA EL CRUCE">
        </a>
        <section class="acceso-tarjeta administracion-tarjeta" aria-labelledby="titulo-administracion">
            <p class="acceso-ceja">ÁREA PRIVADA</p>
            <h1 id="titulo-administracion">Panel administrativo</h1>
            <p class="administracion-bienvenida">Sesión iniciada como <strong><?= $nombre_html ?></strong>.</p>
            <p class="administracion-rol">Rol: <?= $rol_html ?></p>
            <form action="cerrar_sesion.php" method="post">
                <input type="hidden" name="token_csrf" value="<?= $token_csrf_html ?>">
                <button class="acceso-boton administracion-cerrar" type="submit">Cerrar sesión</button>
            </form>
        </section>
    </main>
</body>
</html>

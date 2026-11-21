<?php

declare(strict_types=1);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../app/Servicios/registro_solicitud.php';
require_once __DIR__ . '/../app/Servicios/autenticacion.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($metodo, ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, HEAD, POST'); http_response_code(405); exit('Método no permitido.');
}
$csrf = obtener_token_csrf();
$_SESSION['intentos_solicitud'] ??= [];
$intentos = &$_SESSION['intentos_solicitud'];
$token = $metodo === 'POST' ? ($_POST['intento'] ?? null) : ($_GET['intento'] ?? null);
$error_formulario = '';
$productos_resumen = [];
$puede_enviar = false;
$confirmada = false;
$cliente = ['cliente_nombre' => '', 'cliente_whatsapp' => '', 'cliente_correo' => '', 'observaciones' => ''];
if ($metodo === 'POST') {
    foreach ($cliente as $campo => $valor) {
        if (is_string($_POST[$campo] ?? null)) { $cliente[$campo] = mb_substr($_POST[$campo], 0, 2000, 'UTF-8'); }
    }
}
try {
    if ($metodo === 'POST' && !validar_token_csrf($_POST['csrf'] ?? null)) {
        http_response_code(403);
        throw new InvalidArgumentException('La sesión del formulario cambió. Vuelve a Mi solicitud y continúa nuevamente; tu lista se conserva.');
    }
    if (!intento_solicitud_valido($intentos, $token)) {
        throw new InvalidArgumentException('Este formulario venció o no está disponible. Vuelve a Mi solicitud para continuar; tu lista se conserva.');
    }
    $borrador = &$intentos[$token];
    if ($metodo === 'POST') {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 40000) {
            throw new InvalidArgumentException('Los datos enviados son demasiado extensos. Revisa el formulario.');
        }
        $accion = $_POST['accion'] ?? null;
        if ($accion === 'preparar') {
            $texto = $_POST['productos'] ?? null;
            if (!is_string($texto) || strlen($texto) > 16000) {
                throw new InvalidArgumentException('La lista enviada no es válida.');
            }
            preparar_borrador_solicitud(obtener_conexion(), $borrador, json_decode($texto, true, 8, JSON_THROW_ON_ERROR));
        } elseif ($accion === 'enviar') {
            if (($_POST['confirmacion'] ?? null) !== '1') {
                throw new InvalidArgumentException('Confirma que revisaste los productos y tus datos de contacto.');
            }
            // Los IDs y las cantidades provienen del borrador validado, nunca de este POST.
            if ($borrador['id_solicitud'] === null) {
                confirmar_borrador_solicitud(obtener_conexion(), $borrador, $_POST);
            }
        } else {
            throw new InvalidArgumentException('La acción solicitada no es válida.');
        }
        session_write_close();
        header('Location: solicitar_proforma.php?intento=' . rawurlencode($token), true, 303);
        exit;
    }
} catch (InvalidArgumentException | JsonException $error) {
    if (http_response_code() !== 403) { http_response_code(422); }
    $error_formulario = $error instanceof JsonException ? 'La lista enviada no es válida.' : $error->getMessage();
} catch (Throwable $error) {
    http_response_code(503);
    error_log('No se pudo procesar la solicitud de proforma. Código: ' . $error->getCode());
    $error_formulario = 'No pudimos completar el registro. Tu lista y los datos del formulario se conservan. Inténtalo nuevamente.';
}

if (intento_solicitud_valido($intentos, $token)) {
    $borrador = &$intentos[$token];
    $confirmada = $borrador['id_solicitud'] !== null;
    if (!$confirmada && $borrador['lista'] !== null) {
        try {
            $resumen = validar_lista_solicitud(obtener_conexion(), $borrador['lista']);
            if ($resumen['retirados']) {
                throw new InvalidArgumentException('Algunos productos ya no están disponibles. Vuelve a Mi solicitud para revisar la lista.');
            }
            $productos_resumen = $resumen['productos'];
            $puede_enviar = count($productos_resumen) > 0 && http_response_code() !== 403;
        } catch (Throwable $error) {
            $error_formulario = $error instanceof InvalidArgumentException ? $error->getMessage()
                : 'No pudimos verificar los productos. Tu lista se conserva; vuelve a intentarlo.';
        }
    }
}
session_write_close();
$pagina_actual = 'solicitud';
$categorias_navegacion = [];
$error_categorias_navegacion = false;
try { $categorias_navegacion = categorias_catalogo(obtener_conexion()); }
catch (Throwable $error) { $error_categorias_navegacion = true; }
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?= $confirmada ? 'Solicitud registrada' : 'Datos de la solicitud' ?> | FERRETERÍA EL CRUCE</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/estilos.css">
    <link rel="stylesheet" href="assets/css/solicitud.css">
    <script src="assets/js/navegacion.js" defer></script>
    <script src="assets/js/registro_solicitud.js" defer></script>
</head>
<body class="sitio-publico">
    <a class="salto-contenido" href="#contenido">Saltar al contenido</a>
    <?php require __DIR__ . '/../app/Vistas/plantillas/cabecera_publica.php'; ?>
    <main class="solicitud contenedor" id="contenido" data-registro-solicitud>
    <?php if ($confirmada): ?>
        <section class="solicitud__vacia" aria-labelledby="titulo-confirmacion">
            <p class="solicitud__etiqueta">REGISTRO COMPLETADO</p>
            <h1 id="titulo-confirmacion">Solicitud n.º <?= (int) $borrador['id_solicitud'] ?> registrada</h1>
            <p>La ferretería responderá por WhatsApp después de revisar los productos. Tu solicitud se encuentra en estado Pendiente.</p>
            <p>El equipo preparará la proforma oficial en su sistema comercial y te la enviará manualmente.</p>
            <p id="solicitud-limpieza" role="status">Actualizando tu lista…</p>
            <noscript><p>La solicitud está registrada. Activa JavaScript para actualizar la lista guardada en tu navegador.</p></noscript>
            <a class="solicitud-boton" href="catalogo.php">Volver al catálogo</a>
            <a class="solicitud__volver" href="mi_solicitud.php">Revisar Mi solicitud</a>
        </section>
        <script type="application/json" id="solicitud-confirmada"><?= json_encode(['id' => (int) $borrador['id_solicitud'], 'productos' => $borrador['lista']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
    <?php else: ?>
        <header class="solicitud__titulo">
            <p class="solicitud__etiqueta">SOLICITUD DE PROFORMA</p>
            <h1>Revisa y confirma tu solicitud</h1>
            <p>Completa tus datos de contacto. La ferretería revisará los productos y te responderá por WhatsApp.</p>
        </header>
        <div class="alert alert-warning" role="alert" id="registro-error" tabindex="-1" <?= $error_formulario === '' ? 'hidden' : '' ?>><?= escapar_catalogo($error_formulario) ?></div>
        <?php if ($productos_resumen): ?>
        <div class="solicitud__registro">
            <section class="solicitud__panel" aria-labelledby="resumen-titulo">
                <h2 id="resumen-titulo">Productos de tu solicitud</h2>
                <p class="solicitud__ayuda">Revisa las cantidades antes de enviar. Para cambiarlas, regresa a Mi solicitud.</p>
                <ul class="solicitud__resumen-lista">
                <?php foreach ($productos_resumen as $producto): ?>
                    <li class="solicitud__resumen-producto">
                        <img src="<?= escapar_catalogo($producto['imagen']) ?>" alt="" width="64" height="64">
                        <div><strong><?= escapar_catalogo($producto['nombre']) ?></strong>
                            <span>Código: <?= escapar_catalogo($producto['codigo']) ?></span>
                            <span>Cantidad: <?= (int) $producto['cantidad'] ?></span>
                        </div>
                    </li>
                <?php endforeach; ?>
                </ul>
                <a class="solicitud__volver" href="mi_solicitud.php">Volver a Mi solicitud</a>
            </section>
            <form class="solicitud__panel solicitud__formulario" id="registro-solicitud" action="solicitar_proforma.php" method="post">
                <h2>Tus datos de contacto</h2>
                <p class="solicitud__ayuda">Todos los campos son obligatorios salvo las observaciones.</p>
                <input type="hidden" name="accion" value="enviar">
                <input type="hidden" name="csrf" value="<?= escapar_catalogo($csrf) ?>">
                <input type="hidden" name="intento" value="<?= escapar_catalogo($token) ?>">
                <label for="cliente_nombre">Nombre completo</label>
                <input class="form-control" id="cliente_nombre" name="cliente_nombre" autocomplete="name" required minlength="3" maxlength="120" value="<?= escapar_catalogo($cliente['cliente_nombre']) ?>">
                <label for="cliente_whatsapp">Número de WhatsApp</label>
                <input class="form-control" id="cliente_whatsapp" name="cliente_whatsapp" type="tel" autocomplete="tel" required maxlength="25" aria-describedby="ayuda-whatsapp" value="<?= escapar_catalogo($cliente['cliente_whatsapp']) ?>">
                <small id="ayuda-whatsapp">Entre 8 y 15 dígitos. Puedes incluir el indicativo del país; por ejemplo, +506 8888 8888.</small>
                <label for="cliente_correo">Correo electrónico</label>
                <input class="form-control" id="cliente_correo" name="cliente_correo" type="email" autocomplete="email" required maxlength="150" value="<?= escapar_catalogo($cliente['cliente_correo']) ?>">
                <label for="observaciones">Observaciones <span class="solicitud__ayuda">(opcional)</span></label>
                <textarea class="form-control" id="observaciones" name="observaciones" rows="4" maxlength="2000" placeholder="Puedes indicar detalles que debamos considerar."><?= escapar_catalogo($cliente['observaciones']) ?></textarea>
                <label class="solicitud__confirmar"><input type="checkbox" name="confirmacion" value="1" required> <span>Revisé los productos, las cantidades y mis datos de contacto.</span></label>
                <p class="solicitud__ayuda">Al confirmar se registrará la solicitud para que el equipo la revise. La proforma oficial se preparará posteriormente y se enviará manualmente por WhatsApp.</p>
                <button class="solicitud-boton" type="submit" id="registro-enviar" <?= $puede_enviar ? '' : 'disabled' ?>>Confirmar y enviar solicitud</button>
                <p class="solicitud__ayuda" id="registro-estado" role="status"></p>
            </form>
        </div>
        <?php else: ?>
            <a class="solicitud-boton" href="mi_solicitud.php">Volver a Mi solicitud</a>
        <?php endif; ?>
    <?php endif; ?>
    </main>
    <?php require __DIR__ . '/../app/Vistas/plantillas/pie_publico.php'; ?>
</body>
</html>

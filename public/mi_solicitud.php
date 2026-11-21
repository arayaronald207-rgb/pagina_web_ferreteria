<?php

declare(strict_types=1);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../app/Servicios/catalogo.php';
require_once __DIR__ . '/../app/Servicios/registro_solicitud.php';
require_once __DIR__ . '/../app/Servicios/autenticacion.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD'); http_response_code(405); exit('Método no permitido.');
}
$pagina_actual = 'solicitud';
$csrf = obtener_token_csrf();
$_SESSION['intentos_solicitud'] ??= [];
$intento = crear_intento_solicitud($_SESSION['intentos_solicitud']);
session_write_close();
$categorias_navegacion = [];
$error_categorias_navegacion = false;
try { $categorias_navegacion = categorias_catalogo(obtener_conexion()); }
catch (Throwable $error) { $error_categorias_navegacion = true; error_log('No se pudieron consultar las categorías de Mi solicitud.'); }
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Mi solicitud | FERRETERÍA EL CRUCE</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/estilos.css">
    <link rel="stylesheet" href="assets/css/solicitud.css">
    <script src="assets/js/navegacion.js" defer></script>
</head>
<body class="sitio-publico">
    <a class="salto-contenido" href="#contenido">Saltar al contenido</a>
    <?php require __DIR__ . '/../app/Vistas/plantillas/cabecera_publica.php'; ?>
    <main class="solicitud contenedor" id="contenido">
        <header class="solicitud__titulo">
            <p class="solicitud__etiqueta">PREPARA TU CONSULTA</p>
            <h1>Mi solicitud</h1>
            <p>Reúne los productos y las cantidades que necesitas para solicitar una proforma a nuestro equipo.</p>
            <p class="solicitud__ayuda">Tu lista se guarda en este navegador, sin iniciar sesión. Admite hasta 100 productos distintos y de 1 a 999 unidades por producto.</p>
        </header>
        <noscript><p class="alert alert-warning">Activa JavaScript para revisar tu lista. Las consultas individuales por WhatsApp siguen disponibles en el catálogo.</p></noscript>
        <section id="mi-solicitud" aria-label="Lista de productos">
            <p id="solicitud-cargando" role="status">Verificando tu lista…</p>
            <div class="alert alert-warning" id="solicitud-error" hidden>
                <p id="solicitud-error-texto" role="alert"></p>
                <button class="btn btn-outline-primary" type="button" id="solicitud-reintentar">Volver a verificar</button>
            </div>
            <div class="solicitud__vacia" id="solicitud-vacia" hidden>
                <h2>Tu lista está vacía</h2>
                <p>Explora el catálogo y utiliza «Agregar a solicitud» para comenzar.</p>
                <a class="solicitud-boton" href="catalogo.php">Explorar productos</a>
            </div>
            <div class="solicitud__barra">
                <p id="solicitud-resumen" role="status"></p>
                <button class="btn btn-outline-danger" type="button" id="solicitud-vaciar" disabled>Vaciar lista</button>
            </div>
            <div class="solicitud__productos" id="solicitud-productos"></div>
            <div class="solicitud__siguiente">
                <div>
                    <h2>El siguiente paso</h2>
                    <p id="solicitud-siguiente-ayuda">Completa tus datos y revisa el resumen antes de confirmar el envío a la ferretería.</p>
                    <p>Nuestro equipo revisará los productos, preparará la proforma en su sistema comercial y te responderá por WhatsApp.</p>
                </div>
                <form method="post" action="solicitar_proforma.php" id="solicitud-preparar">
                    <input type="hidden" name="csrf" value="<?= escapar_catalogo($csrf) ?>">
                    <input type="hidden" name="intento" value="<?= escapar_catalogo($intento) ?>">
                    <input type="hidden" name="accion" value="preparar">
                    <input type="hidden" name="productos" id="solicitud-lista-envio">
                    <button class="solicitud-boton" type="submit" id="solicitud-continuar" aria-describedby="solicitud-siguiente-ayuda" disabled>Continuar con la solicitud</button>
                </form>
                <a class="solicitud__volver" href="catalogo.php">Seguir explorando productos</a>
            </div>
        </section>
    </main>
    <?php require __DIR__ . '/../app/Vistas/plantillas/pie_publico.php'; ?>
</body>
</html>

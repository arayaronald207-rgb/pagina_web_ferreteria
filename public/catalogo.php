<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'conexion.php';

function escapar_catalogo(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function obtener_ruta_imagen_catalogo(?string $ruta_imagen): ?string
{
    if ($ruta_imagen === null || trim($ruta_imagen) === '') {
        return null;
    }

    $ruta_imagen = str_replace('\\', '/', trim($ruta_imagen));

    if (str_starts_with($ruta_imagen, 'public/')) {
        $ruta_imagen = substr($ruta_imagen, strlen('public/'));
    }

    if (str_starts_with($ruta_imagen, '/')) {
        $ruta_imagen = ltrim($ruta_imagen, '/');
    }

    if (!str_starts_with($ruta_imagen, 'assets/images/') && !str_starts_with($ruta_imagen, 'uploads/')) {
        return null;
    }

    $segmentos_ruta = explode('/', $ruta_imagen);

    foreach ($segmentos_ruta as $segmento_ruta) {
        if ($segmento_ruta === '' || $segmento_ruta === '.' || $segmento_ruta === '..') {
            return null;
        }
    }

    if (!preg_match('/\A[A-Za-z0-9._\/-]+\z/', $ruta_imagen)) {
        return null;
    }

    $extension_imagen = strtolower(pathinfo($ruta_imagen, PATHINFO_EXTENSION));

    if (!in_array($extension_imagen, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        return null;
    }

    $archivo_imagen = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $ruta_imagen);

    if (!is_file($archivo_imagen) || !is_readable($archivo_imagen)) {
        return null;
    }

    return $ruta_imagen;
}

$busqueda = $_GET['buscar'] ?? '';

if (!is_string($busqueda) || strlen($busqueda) > 480 || preg_match('//u', $busqueda) !== 1) {
    $busqueda = '';
}

$busqueda = trim($busqueda);
$valor_categoria = $_GET['categoria'] ?? '';
$categoria_seleccionada = null;

if (is_string($valor_categoria) && strlen($valor_categoria) <= 400 && preg_match('//u', $valor_categoria) === 1) {
    $categoria_validada = trim($valor_categoria);

    if ($categoria_validada !== '') {
        $categoria_seleccionada = $categoria_validada;
    }
}

$pagina_actual = 'productos';
$termino_busqueda = $busqueda;
$categorias = [];
$productos = [];
$error_catalogo = false;

try {
    $conexion = obtener_conexion();

    $consulta_categorias = $conexion->prepare(
        'SELECT DISTINCT c.nombre
        FROM categorias AS c
        INNER JOIN productos AS p ON p.id_categoria = c.id_categoria
        WHERE p.visible = :visible
        ORDER BY c.nombre ASC'
    );
    $consulta_categorias->bindValue(':visible', 1, PDO::PARAM_INT);
    $consulta_categorias->execute();
    $categorias = $consulta_categorias->fetchAll();

    $sql_productos = 'SELECT p.nombre, p.descripcion, p.imagen, c.nombre AS categoria_nombre
        FROM productos AS p
        INNER JOIN categorias AS c ON c.id_categoria = p.id_categoria
        WHERE p.visible = :visible';
    $parametros_busqueda = [];

    if ($busqueda !== '') {
        $sql_productos .= ' AND p.nombre LIKE :buscar';
        $parametros_busqueda['buscar'] = '%' . $busqueda . '%';
    }

    if ($categoria_seleccionada !== null) {
        $sql_productos .= ' AND c.nombre = :categoria';
        $parametros_busqueda['categoria'] = $categoria_seleccionada;
    }

    $sql_productos .= ' ORDER BY p.nombre ASC';
    $consulta_productos = $conexion->prepare($sql_productos);
    $consulta_productos->bindValue(':visible', 1, PDO::PARAM_INT);

    if ($busqueda !== '') {
        $consulta_productos->bindValue(':buscar', $parametros_busqueda['buscar'], PDO::PARAM_STR);
    }

    if ($categoria_seleccionada !== null) {
        $consulta_productos->bindValue(':categoria', $parametros_busqueda['categoria'], PDO::PARAM_STR);
    }

    $consulta_productos->execute();
    $productos = $consulta_productos->fetchAll();
} catch (Throwable $error) {
    error_log('No se pudo cargar el catálogo público: ' . $error->getMessage());
    $error_catalogo = true;
}

$filtros_activos = $busqueda !== '' || $categoria_seleccionada !== null;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Consulta los productos publicados por FERRETERÍA EL CRUCE.">
    <title>Catálogo | FERRETERÍA EL CRUCE</title>
    <link rel="stylesheet" href="assets/css/estilos.css">
    <link rel="stylesheet" href="assets/css/catalogo.css">
    <script src="assets/js/navegacion.js" defer></script>
</head>
<body>
    <a class="salto-contenido" href="#contenido">Saltar al contenido</a>

    <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Vistas' . DIRECTORY_SEPARATOR . 'plantillas' . DIRECTORY_SEPARATOR . 'cabecera_publica.php'; ?>

    <main class="catalogo" id="contenido">
        <section class="catalogo__introduccion contenedor" aria-labelledby="titulo-catalogo">
            <p class="catalogo__ceja">FERRETERÍA EL CRUCE</p>
            <h1 id="titulo-catalogo">Catálogo de productos</h1>
            <p>Consulta los productos publicados por la ferretería.</p>
        </section>

        <div class="contenedor">
            <form class="catalogo__panel-filtros" id="filtros" method="get" action="catalogo.php">
                <div class="catalogo__campo">
                    <label for="buscar_catalogo">Buscar por nombre</label>
                    <input id="buscar_catalogo" name="buscar" type="search" value="<?= escapar_catalogo($busqueda) ?>" maxlength="120" placeholder="Nombre del producto">
                </div>
                <div class="catalogo__campo">
                    <label for="categoria_catalogo">Categoría</label>
                    <select id="categoria_catalogo" name="categoria">
                        <option value="">Todas las categorías</option>
                        <?php foreach ($categorias as $categoria): ?>
                            <option value="<?= escapar_catalogo($categoria['nombre']) ?>"<?= $categoria_seleccionada === $categoria['nombre'] ? ' selected' : '' ?>><?= escapar_catalogo($categoria['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="catalogo__boton" type="submit">Buscar</button>
                <a class="catalogo__limpiar" href="catalogo.php">Limpiar</a>
            </form>

            <section class="catalogo__resultados" id="productos" aria-label="Resultados del catálogo" aria-live="polite">
                <?php if ($error_catalogo): ?>
                    <div class="catalogo__estado catalogo__estado--error" role="alert">
                        <p>El catálogo no está disponible en este momento. Inténtalo más tarde.</p>
                    </div>
                <?php elseif ($productos === [] && $filtros_activos): ?>
                    <div class="catalogo__estado">
                        <p>No encontramos productos que coincidan con la búsqueda o categoría seleccionada.</p>
                    </div>
                <?php elseif ($productos === []): ?>
                    <div class="catalogo__estado">
                        <p>Aún no hay productos publicados. Vuelve pronto para consultar el catálogo.</p>
                    </div>
                <?php else: ?>
                    <div class="catalogo__rejilla">
                        <?php foreach ($productos as $producto): ?>
                            <?php $ruta_imagen = obtener_ruta_imagen_catalogo($producto['imagen']); ?>
                            <article class="producto">
                                <div class="producto__imagen<?= $ruta_imagen === null ? ' producto__imagen--vacia' : '' ?>">
                                    <?php if ($ruta_imagen !== null): ?>
                                        <img src="<?= escapar_catalogo($ruta_imagen) ?>" alt="<?= escapar_catalogo($producto['nombre']) ?>" loading="lazy">
                                    <?php else: ?>
                                        <span>Imagen no disponible</span>
                                    <?php endif; ?>
                                </div>
                                <div class="producto__contenido">
                                    <p class="producto__categoria"><?= escapar_catalogo($producto['categoria_nombre']) ?></p>
                                    <h2><?= escapar_catalogo($producto['nombre']) ?></h2>
                                    <?php if (is_string($producto['descripcion']) && trim($producto['descripcion']) !== ''): ?>
                                        <p class="producto__descripcion"><?= nl2br(escapar_catalogo($producto['descripcion'])) ?></p>
                                    <?php endif; ?>
                                    <?php $mensaje_whatsapp = 'Hola, quisiera consultar sobre ' . $producto['nombre'] . '.'; ?>
                                    <a class="producto__consulta" href="https://wa.me/50670988980?text=<?= rawurlencode($mensaje_whatsapp) ?>" target="_blank" rel="noopener noreferrer">Consultar por WhatsApp</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Vistas' . DIRECTORY_SEPARATOR . 'plantillas' . DIRECTORY_SEPARATOR . 'pie_publico.php'; ?>
</body>
</html>

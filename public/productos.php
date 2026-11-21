<?php

declare(strict_types=1);
require_once __DIR__ . '/../app/Servicios/panel_administrativo.php';
require_once __DIR__ . '/../app/Servicios/productos.php';
[$conexion, $usuario] = iniciar_panel();
$titulo_pagina = 'Productos';
$seccion_actual = 'productos';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($metodo, ['GET', 'POST'], true)) { header('Allow: GET, POST'); http_response_code(405); exit('Método no permitido.'); }
$mensaje = $_SESSION['mensaje_productos'] ?? '';
unset($_SESSION['mensaje_productos']);
$error_formulario = '';
$disponible = true;
$categorias = [];
$listado = ['productos' => [], 'total' => 0, 'pagina' => 1, 'paginas' => 1];
$formulario = null;
$confirmar = null;
$id_edicion = null;
$busqueda = '';
$categoria_filtro = null;
$estado = '';
$pagina = 1;
$filtros = [];
$detalles_habilitados = false;
$especificaciones_formulario = [];
$galeria_formulario = [];

function url_productos(array $parametros = []): string
{
    return 'productos.php' . ($parametros ? '?' . http_build_query($parametros, '', '&', PHP_QUERY_RFC3986) : '');
}

try {
    $conexion->query('SELECT codigo FROM productos LIMIT 0');
    $categorias = listar_categorias($conexion);
    $detalles_habilitados = detalles_productos_disponibles($conexion);
} catch (Throwable $error) {
    error_log('No se pudo preparar la administración de productos.');
    http_response_code(503);
    $disponible = false;
    $error_formulario = 'La administración de productos no está disponible. Contacta con la persona responsable del sistema.';
}

if ($disponible) {
    try {
        $busqueda = $_GET['buscar'] ?? '';
        if (!is_string($busqueda) || !mb_check_encoding($busqueda, 'UTF-8') || mb_strlen($busqueda) > 150) {
            $busqueda = '';
            throw new InvalidArgumentException('La búsqueda no es válida.');
        }
        $busqueda = trim($busqueda);
        $estado = $_GET['estado'] ?? '';
        if (!in_array($estado, ['', '0', '1'], true)) { $estado = ''; throw new InvalidArgumentException('El filtro de estado no es válido.'); }
        if (isset($_GET['categoria']) && $_GET['categoria'] !== '') { $categoria_filtro = validar_id_categoria($_GET['categoria']); }
        if (isset($_GET['pagina'])) { $pagina = validar_id_producto($_GET['pagina']); }
        $filtros = array_filter(['buscar' => $busqueda, 'categoria' => $categoria_filtro, 'estado' => $estado], fn($v) => $v !== '' && $v !== null);

        if ($metodo === 'POST') {
            if (!validar_token_csrf($_POST['token_csrf'] ?? null)) {
                http_response_code(400);
                $error_formulario = 'La solicitud venció o no es válida. Actualiza la página e inténtalo nuevamente.';
            } else {
                reintentar_limpieza_productos($conexion);
                $accion = $_POST['accion'] ?? null;
                if ($accion === 'crear' || $accion === 'editar') {
                    $id_edicion = $accion === 'editar' ? validar_id_producto($_POST['id_producto'] ?? null) : null;
                    $formulario = [];
                    foreach (['nombre', 'codigo', 'descripcion', 'id_categoria', 'visible'] as $campo) {
                        $formulario[$campo] = is_string($_POST[$campo] ?? null) ? $_POST[$campo] : '';
                    }
                    foreach (CAMPOS_ESPECIFICACIONES as $campo => $etiqueta) {
                        $especificaciones_formulario[$campo] = is_array($_POST['especificaciones'] ?? null) && is_string($_POST['especificaciones'][$campo] ?? null) ? $_POST['especificaciones'][$campo] : '';
                    }
                    $formulario['imagen'] = null;
                    if ($id_edicion !== null) { $formulario['imagen'] = buscar_producto($conexion, $id_edicion)['imagen']; }
                    $quitar = $_POST['quitar_foto'] ?? '0';
                    if (!in_array($quitar, ['0', '1'], true)) { throw new InvalidArgumentException('La opción de fotografía no es válida.'); }
                    if (isset($_FILES['fotografia']) && !is_array($_FILES['fotografia'])) { throw new InvalidArgumentException('La fotografía no es válida.'); }
                    if (isset($_FILES['fotografias']) && !is_array($_FILES['fotografias'])) { throw new InvalidArgumentException('Las fotografías no son válidas.'); }
                    guardar_producto($conexion, $_POST, $id_edicion, $_FILES['fotografia'] ?? null, $quitar === '1', $_FILES['fotografias'] ?? null);
                    $_SESSION['mensaje_productos'] = $id_edicion === null ? 'Producto registrado correctamente.' : 'Producto actualizado correctamente.';
                    header('Location: ' . url_productos($filtros), true, 303);
                    exit;
                } elseif ($accion === 'publicacion') {
                    cambiar_publicacion_producto($conexion, validar_id_producto($_POST['id_producto'] ?? null), $_POST['visible'] ?? null);
                    $_SESSION['mensaje_productos'] = $_POST['visible'] === '1' ? 'Producto publicado.' : 'Producto ocultado del catálogo público.';
                } elseif ($accion === 'confirmar_eliminacion') {
                    unset($_SESSION['producto_eliminar']);
                    $producto = buscar_producto($conexion, validar_id_producto($_POST['id_producto'] ?? null));
                    if (producto_tiene_solicitudes($conexion, (int) $producto['id_producto'])) {
                        throw new InvalidArgumentException('El producto tiene solicitudes asociadas. Puedes ocultarlo para conservar su historial.');
                    }
                    $confirmar = $producto;
                    $_SESSION['producto_eliminar'] = ['id' => (int) $producto['id_producto'], 'fecha' => time()];
                } elseif ($accion === 'eliminar') {
                    $id = validar_id_producto($_POST['id_producto'] ?? null);
                    $confirmacion = $_SESSION['producto_eliminar'] ?? null;
                    unset($_SESSION['producto_eliminar']);
                    if (!is_array($confirmacion) || ($confirmacion['id'] ?? null) !== $id || !is_int($confirmacion['fecha'] ?? null)
                        || time() - $confirmacion['fecha'] > 300) {
                        throw new InvalidArgumentException('La confirmación venció o no es válida. Selecciona Eliminar nuevamente.');
                    }
                    eliminar_producto($conexion, $id);
                    $_SESSION['mensaje_productos'] = 'Producto eliminado correctamente.';
                } else { throw new InvalidArgumentException('La operación solicitada no es válida.'); }
                if ($confirmar === null) { header('Location: ' . url_productos($filtros), true, 303); exit; }
            }
        } else {
            unset($_SESSION['producto_eliminar']);
            if (isset($_GET['editar'])) {
                $id_edicion = validar_id_producto($_GET['editar']);
                $formulario = buscar_producto($conexion, $id_edicion);
                $especificaciones_formulario = leer_especificaciones($formulario['especificaciones'] ?? null);
            } elseif (isset($_GET['nuevo'])) {
                $formulario = ['nombre' => '', 'codigo' => '', 'descripcion' => '', 'id_categoria' => '', 'visible' => '0', 'imagen' => null];
            }
        }
    } catch (InvalidArgumentException $error) {
        http_response_code(422);
        $error_formulario = $error->getMessage();
    } catch (Throwable $error) {
        error_log('No se pudo completar una operación de productos.');
        http_response_code(500);
        $error_formulario = 'No fue posible completar la operación. Revisa el listado antes de volver a intentarlo.';
    }
    try {
        $listado = listar_productos_panel($conexion, $busqueda, $categoria_filtro, $estado, $pagina);
        if ($detalles_habilitados && $formulario !== null && $id_edicion !== null) {
            $galeria_formulario = imagenes_adicionales_producto($conexion, $id_edicion);
        }
    }
    catch (Throwable $error) {
        http_response_code(503);
        $disponible = false;
        $error_formulario = 'No fue posible cargar los productos. Inténtalo más tarde.';
        error_log('No se pudo consultar el listado de productos.');
    }
}
$accion_formulario = escapar_panel(url_productos($filtros));
require __DIR__ . '/../app/Vistas/plantillas/cabecera_administrativa.php';
?>
<div class="panel-titulo">
    <p class="panel-etiqueta">CATÁLOGO</p><h1>Productos</h1>
    <p>Administra los artículos y las fotografías que aparecen en el catálogo público.</p>
    <p class="form-text">Un producto publicado solo aparece públicamente si su categoría también está visible. Las categorías ocultas siguen disponibles para asignar productos.</p>
</div>
<p><a class="btn btn-outline-primary panel-boton" href="importar_productos.php">Importar Excel</a></p>
<?php if (is_string($mensaje) && $mensaje !== ''): ?><p class="alert alert-success panel-aviso" role="status"><?= escapar_panel($mensaje) ?></p><?php endif; ?>
<?php if ($error_formulario !== ''): ?><p class="alert alert-danger panel-aviso panel-aviso--error" role="alert"><?= escapar_panel($error_formulario) ?></p><?php endif; ?>
<?php if ($disponible): ?>
<?php if ($confirmar !== null): ?>
<section class="card panel-tarjeta panel-confirmacion" aria-labelledby="confirmar-titulo">
    <h2 id="confirmar-titulo">¿Eliminar este producto?</h2>
    <p>Se eliminará <strong><?= escapar_panel($confirmar['nombre']) ?></strong> (<?= escapar_panel($confirmar['codigo']) ?>) y sus fotografías administradas. Esta acción no se puede deshacer. También puedes cancelar y ocultarlo.</p>
    <form class="panel-acciones" action="<?= $accion_formulario ?>" method="post">
        <input type="hidden" name="token_csrf" value="<?= escapar_panel(obtener_token_csrf()) ?>">
        <input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id_producto" value="<?= escapar_panel($confirmar['id_producto']) ?>">
        <button class="btn btn-danger panel-boton" type="submit">Sí, eliminar producto</button><a class="btn btn-outline-primary panel-boton" href="<?= $accion_formulario ?>">Cancelar</a>
    </form>
</section>
<?php endif; ?>
<?php if ($formulario !== null): ?>
<section class="card panel-tarjeta panel-producto-formulario" aria-labelledby="formulario-titulo">
    <h2 id="formulario-titulo"><?= $id_edicion === null ? 'Nuevo producto' : 'Editar producto' ?></h2>
    <?php if ($categorias === []): ?><p class="alert alert-warning">Primero debes <a href="categorias.php">registrar una categoría</a>.</p><?php endif; ?>
    <form action="<?= $accion_formulario ?>" method="post" enctype="multipart/form-data" class="row g-3">
        <input type="hidden" name="token_csrf" value="<?= escapar_panel(obtener_token_csrf()) ?>">
        <input type="hidden" name="accion" value="<?= $id_edicion === null ? 'crear' : 'editar' ?>">
        <?php if ($id_edicion !== null): ?><input type="hidden" name="id_producto" value="<?= escapar_panel($id_edicion) ?>"><?php endif; ?>
        <div class="col-12 col-md-5"><label class="form-label" for="codigo_producto">Código *</label><input class="form-control" id="codigo_producto" name="codigo" required maxlength="64" value="<?= escapar_panel($formulario['codigo']) ?>" aria-describedby="codigo-ayuda"><div id="codigo-ayuda" class="form-text">Código único; letras, números, punto, guion, barra o guion bajo.</div></div>
        <div class="col-12 col-md-7"><label class="form-label" for="nombre_producto">Nombre *</label><input class="form-control" id="nombre_producto" name="nombre" required maxlength="150" value="<?= escapar_panel($formulario['nombre']) ?>"></div>
        <div class="col-12 col-md-7"><label class="form-label" for="categoria_producto">Categoría *</label><select class="form-select" id="categoria_producto" name="id_categoria" required><option value="">Selecciona una categoría</option><?php foreach ($categorias as $categoria): ?><option value="<?= escapar_panel($categoria['id_categoria']) ?>"<?= (string) $formulario['id_categoria'] === (string) $categoria['id_categoria'] ? ' selected' : '' ?>><?= escapar_panel($categoria['nombre']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-5"><label class="form-label" for="visible_producto">Publicación *</label><select class="form-select" id="visible_producto" name="visible" required><option value="0"<?= (string) $formulario['visible'] === '0' ? ' selected' : '' ?>>Oculto</option><option value="1"<?= (string) $formulario['visible'] === '1' ? ' selected' : '' ?>>Publicado</option></select></div>
        <div class="col-12"><label class="form-label" for="descripcion_producto">Descripción</label><textarea class="form-control" id="descripcion_producto" name="descripcion" rows="4" maxlength="5000"><?= escapar_panel($formulario['descripcion']) ?></textarea></div>
        <div class="col-12"><label class="form-label" for="fotografia_producto">Fotografía</label><input class="form-control" id="fotografia_producto" name="fotografia" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" aria-describedby="foto-ayuda"><p class="form-text" id="foto-ayuda">Opcional. JPG, PNG o WebP, hasta 5 MB. Si no eliges otro archivo, se conserva la fotografía actual. Tras un error debes volver a seleccionar el archivo.</p>
            <img class="panel-producto-preview" src="<?= escapar_panel(ruta_imagen_producto($formulario['imagen']) ?? 'assets/images/producto_sin_imagen.svg') ?>" alt="Fotografía actual del producto">
            <?php if ($formulario['imagen'] !== null): ?><div class="form-check mt-2"><input class="form-check-input" id="quitar_foto" type="checkbox" name="quitar_foto" value="1"><label class="form-check-label" for="quitar_foto">Eliminar fotografía actual</label></div><?php endif; ?>
        </div>
        <?php if ($detalles_habilitados): ?>
        <fieldset class="col-12">
            <legend class="fs-5">Especificaciones técnicas</legend>
            <p class="form-text">Opcionales. Completa únicamente los datos conocidos; los campos vacíos no se muestran en el catálogo.</p>
            <div class="row g-3">
                <?php foreach (CAMPOS_ESPECIFICACIONES as $campo => $etiqueta): ?>
                    <div class="col-12 col-md-6"><label class="form-label" for="especificacion_<?= $campo ?>"><?= escapar_panel($etiqueta) ?></label><input class="form-control" id="especificacion_<?= $campo ?>" name="especificaciones[<?= $campo ?>]" maxlength="150" value="<?= escapar_panel($especificaciones_formulario[$campo] ?? '') ?>"></div>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <div class="col-12">
            <label class="form-label" for="fotografias_producto">Fotografías adicionales</label>
            <input class="form-control" id="fotografias_producto" type="file" name="fotografias[]" multiple accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" aria-describedby="galeria-ayuda">
            <p class="form-text" id="galeria-ayuda">Hasta cinco fotografías adicionales, JPG, PNG o WebP, de hasta 5 MB cada una. Si no seleccionas archivos, se conservan las actuales.</p>
            <div class="d-flex flex-wrap gap-3">
                <?php foreach ($galeria_formulario as $imagen): ?>
                    <div><img class="panel-producto-preview" src="<?= escapar_panel(ruta_imagen_producto($imagen['imagen']) ?? 'assets/images/producto_sin_imagen.svg') ?>" alt="Fotografía adicional">
                        <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="quitar_imagen_<?= (int) $imagen['id_imagen'] ?>" name="quitar_imagenes[]" value="<?= (int) $imagen['id_imagen'] ?>"><label class="form-check-label" for="quitar_imagen_<?= (int) $imagen['id_imagen'] ?>">Eliminar esta fotografía</label></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <div class="col-12 panel-acciones"><button class="btn btn-primary panel-boton" type="submit"<?= $categorias === [] ? ' disabled' : '' ?>><?= $id_edicion === null ? 'Registrar producto' : 'Guardar cambios' ?></button><a class="btn btn-outline-primary panel-boton" href="<?= $accion_formulario ?>">Cancelar</a></div>
    </form>
</section>
<?php else: ?><p><a class="btn btn-primary panel-boton" href="<?= escapar_panel(url_productos($filtros + ['nuevo' => '1'])) ?>">Agregar producto</a></p><?php endif; ?>
<section class="card panel-tarjeta" aria-labelledby="listado-titulo">
    <h2 id="listado-titulo">Productos registrados</h2>
    <form action="productos.php" method="get" class="row g-3 align-items-end mb-4">
        <div class="col-12 col-lg-5"><label class="form-label" for="buscar_productos">Nombre, código o descripción</label><input class="form-control" id="buscar_productos" type="search" name="buscar" maxlength="150" value="<?= escapar_panel($busqueda) ?>"></div>
        <div class="col-12 col-sm-6 col-lg-4"><label class="form-label" for="categoria_filtro">Categoría</label><select class="form-select" id="categoria_filtro" name="categoria"><option value="">Todas</option><?php foreach ($categorias as $categoria): ?><option value="<?= escapar_panel($categoria['id_categoria']) ?>"<?= $categoria_filtro === (int) $categoria['id_categoria'] ? ' selected' : '' ?>><?= escapar_panel($categoria['nombre']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-sm-6 col-lg-3"><label class="form-label" for="estado_filtro">Estado</label><select class="form-select" id="estado_filtro" name="estado"><option value="">Todos</option><option value="1"<?= $estado === '1' ? ' selected' : '' ?>>Publicado</option><option value="0"<?= $estado === '0' ? ' selected' : '' ?>>Oculto</option></select></div>
        <div class="col-12 panel-acciones"><button class="btn btn-primary panel-boton" type="submit">Buscar productos</button><a class="btn btn-outline-primary panel-boton" href="productos.php">Limpiar filtros</a></div>
    </form>
    <p><?= escapar_panel($listado['total']) ?> productos encontrados.</p>
    <?php foreach ($categorias as $categoria): if (mb_strtolower(trim($categoria['nombre']), 'UTF-8') === 'sin clasificar'): ?>
        <p><a class="badge text-bg-warning" href="<?= escapar_panel(url_productos(['categoria' => $categoria['id_categoria']])) ?>">Sin clasificar: <?= (int) $categoria['productos'] ?> productos</a></p>
    <?php endif; endforeach; ?>
    <?php require __DIR__ . '/../app/Vistas/plantillas/acciones_productos.php'; ?>
    <?php if ($listado['productos'] === []): ?><p class="panel-vacio">No hay productos que mostrar. Agrega uno o cambia los filtros.</p><?php else: ?>
    <div class="table-responsive" role="region" aria-labelledby="listado-titulo" tabindex="0">
        <table class="table table-hover align-middle panel-tabla panel-productos-tabla"><caption class="visually-hidden">Productos y acciones administrativas</caption>
            <thead><tr><?php if ($usuario['rol'] === 'administrador'): ?><th scope="col"><input type="checkbox" class="form-check-input" id="seleccionar-pagina" aria-label="Seleccionar los productos de esta página" disabled></th><?php endif; ?><th scope="col">Foto</th><th scope="col">Código</th><th scope="col">Nombre</th><th scope="col">Categoría</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead><tbody>
            <?php foreach ($listado['productos'] as $producto): ?><tr>
                <?php if ($usuario['rol'] === 'administrador'): ?><td><input type="checkbox" class="form-check-input" data-seleccionar-producto="<?= (int) $producto['id_producto'] ?>" aria-label="Seleccionar <?= escapar_panel($producto['codigo']) ?>" disabled></td><?php endif; ?>
                <td><img class="panel-producto-miniatura" src="<?= escapar_panel(ruta_imagen_producto($producto['imagen']) ?? 'assets/images/producto_sin_imagen.svg') ?>" alt="" loading="lazy" width="64" height="64"></td>
                <td><?= escapar_panel($producto['codigo']) ?></td><th scope="row"><?= escapar_panel($producto['nombre']) ?></th><td><?= escapar_panel($producto['categoria_nombre']) ?></td><td><span class="badge <?= (int) $producto['visible'] === 1 ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= (int) $producto['visible'] === 1 ? 'Publicado' : 'Oculto' ?></span></td>
                <td><div class="panel-acciones"><a class="btn btn-outline-primary panel-boton" href="<?= escapar_panel(url_productos($filtros + ['editar' => $producto['id_producto']])) ?>" aria-label="Editar <?= escapar_panel($producto['nombre']) ?>">Editar</a>
                    <form action="<?= $accion_formulario ?>" method="post"><input type="hidden" name="token_csrf" value="<?= escapar_panel(obtener_token_csrf()) ?>"><input type="hidden" name="accion" value="publicacion"><input type="hidden" name="id_producto" value="<?= escapar_panel($producto['id_producto']) ?>"><input type="hidden" name="visible" value="<?= (int) $producto['visible'] === 1 ? '0' : '1' ?>"><button class="btn btn-outline-primary panel-boton" type="submit" aria-label="Cambiar publicación de <?= escapar_panel($producto['nombre']) ?>"><?= (int) $producto['visible'] === 1 ? 'Ocultar' : 'Publicar' ?></button></form>
                    <?php if ((int) $producto['solicitudes'] === 0): ?><form action="<?= $accion_formulario ?>" method="post"><input type="hidden" name="token_csrf" value="<?= escapar_panel(obtener_token_csrf()) ?>"><input type="hidden" name="accion" value="confirmar_eliminacion"><input type="hidden" name="id_producto" value="<?= escapar_panel($producto['id_producto']) ?>"><button class="btn btn-outline-danger panel-boton" type="submit" aria-label="Eliminar <?= escapar_panel($producto['nombre']) ?>">Eliminar</button></form><?php else: ?><small>Con solicitudes; puedes ocultarlo.</small><?php endif; ?>
                </div></td>
            </tr><?php endforeach; ?>
            </tbody></table>
    </div>
    <?php endif; ?>
    <?php if ($listado['paginas'] > 1): ?><nav class="panel-acciones mt-3" aria-label="Paginación de productos">
        <?php if ($listado['pagina'] > 1): ?><a class="btn btn-outline-primary panel-boton" href="<?= escapar_panel(url_productos($filtros + ['pagina' => $listado['pagina'] - 1])) ?>">Anterior</a><?php endif; ?>
        <span>Página <?= escapar_panel($listado['pagina']) ?> de <?= escapar_panel($listado['paginas']) ?></span>
        <?php if ($listado['pagina'] < $listado['paginas']): ?><a class="btn btn-outline-primary panel-boton" href="<?= escapar_panel(url_productos($filtros + ['pagina' => $listado['pagina'] + 1])) ?>">Siguiente</a><?php endif; ?>
    </nav><?php endif; ?>
</section>
<?php endif; ?>
<script src="assets/js/productos_masivos.js" defer></script>
<?php require __DIR__ . '/../app/Vistas/plantillas/pie_administrativo.php'; ?>

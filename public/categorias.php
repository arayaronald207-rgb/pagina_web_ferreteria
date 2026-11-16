<?php

declare(strict_types=1);
require_once __DIR__ . '/../app/Servicios/panel_administrativo.php';
require_once __DIR__ . '/../app/Servicios/categorias.php';
[$conexion, $usuario] = iniciar_panel();

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($metodo, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método no permitido.');
}
$titulo_pagina = 'Categorías';
$seccion_actual = 'categorias';
$error_formulario = '';
$nombre_formulario = '';
$id_edicion = null;
$confirmar = null;
$mensaje = $_SESSION['mensaje_categorias'] ?? '';
unset($_SESSION['mensaje_categorias']);

try {
    if ($metodo === 'POST') {
        if (!validar_token_csrf($_POST['token_csrf'] ?? null)) {
            http_response_code(400);
            $error_formulario = 'La solicitud venció o no es válida. Revisa los datos e inténtalo nuevamente.';
        } else {
            $accion = $_POST['accion'] ?? null;
            if ($accion === 'crear' || $accion === 'editar') {
                $id_edicion = $accion === 'editar' ? validar_id_categoria($_POST['id_categoria'] ?? null) : null;
                $nombre_formulario = is_string($_POST['nombre'] ?? null) ? $_POST['nombre'] : '';
                $nombre = validar_nombre_categoria($_POST['nombre'] ?? null);
                guardar_categoria($conexion, $nombre, $id_edicion);
                $_SESSION['mensaje_categorias'] = $id_edicion === null ? 'Categoría creada correctamente.' : 'Categoría actualizada correctamente.';
                header('Location: categorias.php', true, 303);
                exit;
            } elseif ($accion === 'confirmar_eliminacion') {
                unset($_SESSION['categoria_eliminar']);
                $categoria = buscar_categoria($conexion, validar_id_categoria($_POST['id_categoria'] ?? null));
                if ((int) $categoria['productos'] > 0) {
                    throw new InvalidArgumentException('No puedes eliminar esta categoría porque tiene productos asociados, incluidos los ocultos.');
                }
                $confirmar = $categoria;
                $_SESSION['categoria_eliminar'] = ['id' => (int) $categoria['id_categoria'], 'fecha' => time()];
            } elseif ($accion === 'eliminar') {
                $id = validar_id_categoria($_POST['id_categoria'] ?? null);
                $confirmacion = $_SESSION['categoria_eliminar'] ?? null;
                unset($_SESSION['categoria_eliminar']);
                if (!is_array($confirmacion) || ($confirmacion['id'] ?? null) !== $id
                    || !is_int($confirmacion['fecha'] ?? null) || time() - $confirmacion['fecha'] > 300) {
                    throw new InvalidArgumentException('La confirmación venció o no es válida. Selecciona Eliminar nuevamente.');
                }
                eliminar_categoria($conexion, $id);
                $_SESSION['mensaje_categorias'] = 'Categoría eliminada correctamente.';
                header('Location: categorias.php', true, 303);
                exit;
            } else {
                throw new InvalidArgumentException('La operación solicitada no es válida.');
            }
        }
    } else {
        unset($_SESSION['categoria_eliminar']);
        // GET únicamente selecciona el formulario de edición; nunca guarda ni elimina.
        if (isset($_GET['editar'])) {
            $categoria = buscar_categoria($conexion, validar_id_categoria($_GET['editar']));
            $id_edicion = (int) $categoria['id_categoria'];
            $nombre_formulario = $categoria['nombre'];
        }
    }
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    $error_formulario = $error->getMessage();
} catch (Throwable $error) {
    error_log('No se pudo completar una operación de categorías.');
    http_response_code(500);
    $error_formulario = 'No fue posible completar la operación. Actualiza el listado antes de volver a intentarlo.';
}

$categorias = null;
try {
    $categorias = listar_categorias($conexion);
} catch (Throwable $error) {
    error_log('No se pudo consultar el listado de categorías.');
    http_response_code(503);
    $error_formulario = 'No fue posible cargar las categorías. Inténtalo más tarde.';
}
require __DIR__ . '/../app/Vistas/plantillas/cabecera_administrativa.php';
?>
<div class="panel-titulo">
    <p class="panel-etiqueta">CATÁLOGO</p>
    <h1>Categorías</h1>
    <p>Organiza los productos para que tus clientes encuentren lo que necesitan.</p>
</div>
<?php if (is_string($mensaje) && $mensaje !== ''): ?>
    <p class="alert alert-success panel-aviso" role="status"><?= escapar_panel($mensaje) ?></p>
<?php endif; ?>
<?php if ($error_formulario !== ''): ?>
    <p class="alert alert-danger panel-aviso panel-aviso--error" role="alert"><?= escapar_panel($error_formulario) ?></p>
<?php endif; ?>
<?php if ($confirmar !== null): ?>
    <section class="card panel-tarjeta panel-confirmacion" aria-labelledby="confirmar-titulo">
        <h2 id="confirmar-titulo">¿Eliminar esta categoría?</h2>
        <p>Vas a eliminar <strong><?= escapar_panel($confirmar['nombre']) ?></strong>. Esta acción no se puede deshacer.</p>
        <form class="panel-acciones" action="categorias.php" method="post">
            <input type="hidden" name="token_csrf" value="<?= escapar_panel(obtener_token_csrf()) ?>">
            <input type="hidden" name="accion" value="eliminar">
            <input type="hidden" name="id_categoria" value="<?= escapar_panel($confirmar['id_categoria']) ?>">
            <button class="btn btn-danger panel-boton panel-boton--peligro" type="submit">Sí, eliminar categoría</button>
            <a class="btn btn-outline-primary panel-boton panel-boton--secundario" href="categorias.php">Cancelar</a>
        </form>
    </section>
<?php endif; ?>
<div class="row g-4 panel-categorias">
    <div class="col-12 col-xl-5"><section class="card panel-tarjeta panel-formulario" aria-labelledby="formulario-titulo">
        <h2 id="formulario-titulo"><?= $id_edicion === null ? 'Nueva categoría' : 'Editar categoría' ?></h2>
        <form action="categorias.php" method="post">
            <input type="hidden" name="token_csrf" value="<?= escapar_panel(obtener_token_csrf()) ?>">
            <input type="hidden" name="accion" value="<?= $id_edicion === null ? 'crear' : 'editar' ?>">
            <?php if ($id_edicion !== null): ?><input type="hidden" name="id_categoria" value="<?= escapar_panel($id_edicion) ?>"><?php endif; ?>
            <label class="form-label" for="nombre_categoria">Nombre de la categoría</label>
            <input class="form-control" id="nombre_categoria" name="nombre" type="text" maxlength="100" required value="<?= escapar_panel($nombre_formulario) ?>" aria-describedby="nombre-ayuda">
            <p id="nombre-ayuda" class="form-text panel-ayuda">Hasta 100 caracteres. El nombre debe ser único.</p>
            <div class="panel-acciones">
                <button class="btn btn-primary panel-boton" type="submit"<?= $categorias === null ? ' disabled' : '' ?>><?= $id_edicion === null ? 'Crear categoría' : 'Guardar cambios' ?></button>
                <?php if ($id_edicion !== null): ?><a href="categorias.php" class="btn btn-outline-primary panel-boton panel-boton--secundario">Cancelar</a><?php endif; ?>
            </div>
        </form>
    </section></div>
    <div class="col-12 col-xl-7"><section class="card panel-tarjeta" aria-labelledby="listado-titulo">
        <h2 id="listado-titulo">Categorías registradas</h2>
        <p class="form-text panel-ayuda">Solo se pueden eliminar categorías sin productos asociados.</p>
        <?php if ($categorias === null): ?>
            <p>El listado no está disponible.</p>
        <?php elseif ($categorias === []): ?>
            <div class="panel-vacio"><strong>Aún no hay categorías</strong><p>Crea la primera para comenzar a organizar el catálogo.</p></div>
        <?php else: ?>
            <div class="table-responsive" role="region" aria-labelledby="listado-titulo" tabindex="0">
            <table class="table table-hover align-middle panel-tabla">
                <caption class="visually-hidden">Categorías, productos asociados y acciones disponibles</caption>
                <thead><tr><th scope="col">Categoría</th><th scope="col">Productos</th><th scope="col">Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($categorias as $categoria): ?>
                    <tr>
                        <th scope="row"><?= escapar_panel($categoria['nombre']) ?></th>
                        <td><?= escapar_panel($categoria['productos']) ?></td>
                        <td>
                        <div class="panel-acciones">
                            <a class="btn btn-outline-primary panel-boton panel-boton--secundario" href="categorias.php?editar=<?= escapar_panel($categoria['id_categoria']) ?>" aria-label="Editar <?= escapar_panel($categoria['nombre']) ?>">Editar</a>
                            <?php if ((int) $categoria['productos'] === 0): ?>
                                <form action="categorias.php" method="post">
                                    <input type="hidden" name="token_csrf" value="<?= escapar_panel(obtener_token_csrf()) ?>">
                                    <input type="hidden" name="accion" value="confirmar_eliminacion">
                                    <input type="hidden" name="id_categoria" value="<?= escapar_panel($categoria['id_categoria']) ?>">
                                    <button class="btn btn-outline-danger panel-boton panel-boton--eliminar" type="submit" aria-label="Eliminar <?= escapar_panel($categoria['nombre']) ?>">Eliminar</button>
                                </form>
                            <?php else: ?>
                                <span class="panel-conservada">Con productos</span>
                            <?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            </table></div>
        <?php endif; ?>
    </section></div>
</div>
<?php require __DIR__ . '/../app/Vistas/plantillas/pie_administrativo.php'; ?>

<div class="panel-titulo">
    <p class="panel-etiqueta">ATENCIÓN AL CLIENTE</p>
    <h1>Solicitudes de proforma</h1>
    <p>Revisa los productos solicitados y da seguimiento a la atención de cada cliente.</p>
</div>
<?php if ($error_formulario !== ''): ?>
    <p class="alert alert-danger panel-aviso panel-aviso--error" role="alert"><?= escapar_panel($error_formulario) ?></p>
<?php endif; ?>
<section class="card panel-tarjeta panel-formulario" aria-labelledby="filtros-titulo">
    <h2 id="filtros-titulo">Buscar solicitudes</h2>
    <form method="get" action="solicitudes.php" class="row g-3 align-items-end">
        <div class="col-12 col-lg-6">
            <label for="solicitudes-buscar">Número o nombre del cliente</label>
            <input class="form-control" id="solicitudes-buscar" name="buscar" type="search" maxlength="120" value="<?= escapar_panel($filtros['buscar']) ?>" placeholder="Ejemplo: 1 o nombre del cliente">
        </div>
        <div class="col-12 col-lg-6">
            <label for="solicitudes-estado">Estado</label>
            <select class="form-select" id="solicitudes-estado" name="estado">
                <option value="">Todos los estados</option>
                <?php foreach (ESTADOS_SOLICITUD as $estado): ?>
                    <option value="<?= escapar_panel($estado) ?>"<?= $filtros['estado'] === $estado ? ' selected' : '' ?>><?= escapar_panel($estado) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 panel-acciones">
            <button class="btn btn-primary panel-boton" type="submit">Buscar</button>
            <a class="btn btn-outline-primary panel-boton" href="solicitudes.php">Limpiar filtros</a>
        </div>
    </form>
</section>
<?php if ($listado !== null): ?>
<section class="card panel-tarjeta" aria-labelledby="listado-titulo">
    <h2 id="listado-titulo">Solicitudes registradas</h2>
    <p><?= (int) $listado['total'] ?> <?= $listado['total'] === 1 ? 'solicitud encontrada' : 'solicitudes encontradas' ?>. La cantidad de productos indica las líneas de cada solicitud.</p>
    <?php if (!$listado['solicitudes']): ?>
        <div class="panel-vacio"><strong>No hay solicitudes para mostrar</strong><p>Prueba con otro nombre, número o estado.</p></div>
    <?php else: ?>
        <div class="table-responsive" role="region" aria-labelledby="listado-titulo" tabindex="0">
            <table class="table table-hover align-middle panel-tabla panel-solicitudes-tabla">
                <caption class="visually-hidden">Número, fecha, cliente, cantidad de productos y estado de cada solicitud</caption>
                <thead><tr><th scope="col">Número</th><th scope="col">Fecha de registro</th><th scope="col">Cliente</th><th scope="col">Productos</th><th scope="col">Estado</th><th scope="col">Detalle</th></tr></thead>
                <tbody>
                <?php foreach ($listado['solicitudes'] as $fila): ?>
                    <tr>
                        <th scope="row">N.º <?= (int) $fila['id_solicitud'] ?></th>
                        <td><time datetime="<?= escapar_panel(str_replace(' ', 'T', $fila['fecha_creacion'])) ?>"><?= escapar_panel(fecha_solicitud_panel($fila['fecha_creacion'])) ?></time></td>
                        <td><?= escapar_panel($fila['cliente_nombre']) ?></td>
                        <td><?= (int) $fila['productos'] ?></td>
                        <td><span class="badge <?= clase_estado_solicitud($fila['estado']) ?>"><?= escapar_panel($fila['estado']) ?></span></td>
                        <td><a class="btn btn-outline-primary panel-boton" href="solicitud.php?<?= escapar_panel(http_build_query(['id' => $fila['id_solicitud']] + array_replace($filtros, ['pagina' => $listado['pagina']]), '', '&', PHP_QUERY_RFC3986)) ?>" aria-label="Ver solicitud <?= (int) $fila['id_solicitud'] ?>">Ver detalle</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($listado['paginas'] > 1): ?>
            <nav class="panel-acciones mt-3" aria-label="Páginas de solicitudes">
                <?php if ($listado['pagina'] > 1): ?><a class="btn btn-outline-primary panel-boton" href="<?= escapar_panel(url_solicitudes(array_replace($filtros, ['pagina' => $listado['pagina'] - 1]))) ?>">Anterior</a><?php endif; ?>
                <span>Página <?= (int) $listado['pagina'] ?> de <?= (int) $listado['paginas'] ?></span>
                <?php if ($listado['pagina'] < $listado['paginas']): ?><a class="btn btn-outline-primary panel-boton" href="<?= escapar_panel(url_solicitudes(array_replace($filtros, ['pagina' => $listado['pagina'] + 1]))) ?>">Siguiente</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>

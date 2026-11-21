<div class="panel-titulo">
    <p class="panel-etiqueta">ATENCIÓN AL CLIENTE</p>
    <h1><?= $solicitud === null ? 'Detalle de solicitud' : 'Solicitud n.º ' . (int) $solicitud['id_solicitud'] ?></h1>
    <p>Prepara la proforma en el sistema comercial y responde al cliente por WhatsApp.</p>
</div>
<p><a class="btn btn-outline-primary panel-boton" href="<?= escapar_panel(url_solicitudes($filtros)) ?>">Volver a solicitudes</a></p>
<?php if ($mensaje !== ''): ?><p class="alert alert-success panel-aviso" role="status"><?= escapar_panel($mensaje) ?></p><?php endif; ?>
<?php if ($error_formulario !== ''): ?><p class="alert alert-danger panel-aviso panel-aviso--error" role="alert"><?= escapar_panel($error_formulario) ?></p><?php endif; ?>
<?php if ($solicitud !== null): ?>
    <?php if ($confirmar !== null): ?>
    <section class="card panel-tarjeta panel-confirmacion" aria-labelledby="confirmar-estado-titulo">
        <h2 id="confirmar-estado-titulo">Confirmar cambio de estado</h2>
        <p>La solicitud n.º <strong><?= (int) $solicitud['id_solicitud'] ?></strong> pasará de <strong><?= escapar_panel($confirmar['anterior']) ?></strong> a <strong><?= escapar_panel($confirmar['nuevo']) ?></strong>.</p>
        <p>Los datos del cliente y los productos solicitados se conservarán.</p>
        <form class="panel-acciones" action="<?= escapar_panel($url_detalle) ?>" method="post" id="confirmar-estado-solicitud">
            <input type="hidden" name="token_csrf" value="<?= escapar_panel(obtener_token_csrf()) ?>">
            <input type="hidden" name="accion" value="guardar_estado">
            <input type="hidden" name="confirmacion" value="<?= escapar_panel($token_confirmacion) ?>">
            <button class="btn btn-primary panel-boton" type="submit">Sí, cambiar estado</button>
            <a class="btn btn-outline-primary panel-boton" href="<?= escapar_panel($url_detalle) ?>">Cancelar cambio</a>
        </form>
    </section>
    <?php endif; ?>
    <div class="row g-4">
        <div class="col-12 col-xl-7">
            <section class="card panel-tarjeta" aria-labelledby="cliente-titulo">
                <h2 id="cliente-titulo">Datos del cliente</h2>
                <dl class="panel-solicitud-datos">
                    <dt>Nombre completo</dt><dd><?= escapar_panel($solicitud['cliente_nombre']) ?></dd>
                    <dt>WhatsApp</dt><dd><?= escapar_panel($solicitud['cliente_whatsapp']) ?></dd>
                    <dt>Correo electrónico</dt><dd><?= escapar_panel($solicitud['cliente_correo']) ?></dd>
                    <dt>Fecha de registro</dt><dd><?= escapar_panel(fecha_solicitud_panel($solicitud['fecha_creacion'])) ?></dd>
                    <dt>Observaciones</dt><dd class="panel-solicitud-observaciones"><?= escapar_panel($solicitud['observaciones'] === null || $solicitud['observaciones'] === '' ? 'Sin observaciones.' : $solicitud['observaciones']) ?></dd>
                </dl>
                <?php $whatsapp = enlace_whatsapp_solicitud($solicitud['cliente_whatsapp']); ?>
                <?php if ($whatsapp !== null): ?>
                    <a class="btn btn-success panel-boton" id="responder-whatsapp" href="<?= escapar_panel($whatsapp) ?>" target="_blank" rel="noopener noreferrer" aria-label="Responder por WhatsApp, abre una pestaña nueva">Responder por WhatsApp ↗</a>
                    <p class="panel-ayuda mt-3">Abre la conversación para responder manualmente. No envía mensajes ni cambia el estado. Los números locales de ocho dígitos se abren con el indicativo de Costa Rica (+506).</p>
                <?php else: ?>
                    <p class="alert alert-warning">El número guardado no permite crear un enlace válido de WhatsApp. Verifica el contacto con el cliente antes de responder.</p>
                <?php endif; ?>
            </section>
        </div>
        <div class="col-12 col-xl-5">
            <section class="card panel-tarjeta panel-formulario" aria-labelledby="estado-titulo">
                <h2 id="estado-titulo">Seguimiento</h2>
                <p>Estado actual: <span class="badge <?= clase_estado_solicitud($solicitud['estado']) ?>" id="estado-actual-solicitud"><?= escapar_panel($solicitud['estado']) ?></span></p>
                <?php if ($confirmar === null): ?>
                <form action="<?= escapar_panel($url_detalle) ?>" method="post" id="revisar-estado-solicitud">
                    <input type="hidden" name="token_csrf" value="<?= escapar_panel(obtener_token_csrf()) ?>">
                    <input type="hidden" name="accion" value="revisar_estado">
                    <label for="nuevo-estado">Nuevo estado</label>
                    <select class="form-select" id="nuevo-estado" name="estado" required>
                        <option value="">Selecciona un estado</option>
                        <?php foreach (ESTADOS_SOLICITUD as $estado): ?>
                            <option value="<?= escapar_panel($estado) ?>"<?= $estado === $solicitud['estado'] ? ' disabled' : '' ?>><?= escapar_panel($estado) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-primary panel-boton mt-3" type="submit">Revisar cambio de estado</button>
                </form>
                <?php else: ?><p>Revisa la confirmación antes de guardar el cambio.</p><?php endif; ?>
            </section>
        </div>
    </div>
    <section class="card panel-tarjeta" aria-labelledby="productos-titulo">
        <h2 id="productos-titulo">Productos solicitados</h2>
        <p>El nombre y la cantidad corresponden al registro original de la solicitud.</p>
        <?php if (!$productos): ?><p>No hay productos asociados a esta solicitud.</p><?php else: ?>
        <div class="table-responsive" role="region" aria-labelledby="productos-titulo" tabindex="0">
            <table class="table align-middle panel-tabla panel-solicitud-productos">
                <caption class="visually-hidden">Productos y cantidades registrados</caption>
                <thead><tr><th scope="col">Producto</th><th scope="col">Código actual</th><th scope="col">Cantidad</th></tr></thead>
                <tbody>
                <?php foreach ($productos as $producto): ?>
                    <tr><th scope="row"><?= escapar_panel($producto['producto_nombre']) ?></th><td><?= escapar_panel($producto['codigo'] ?? '—') ?></td><td><?= (int) $producto['cantidad'] ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

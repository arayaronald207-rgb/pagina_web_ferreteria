<?php if ($usuario['rol'] === 'administrador'): ?>
<section class="border rounded p-3 mb-3" id="gestion-masiva" aria-labelledby="masiva-titulo" data-token="<?= escapar_panel(obtener_token_csrf()) ?>" data-usuario="<?= (int) $usuario['id_usuario'] ?>">
    <h3 class="h5" id="masiva-titulo">Gestión de productos seleccionados</h3>
    <p id="seleccion-cantidad" role="status">0 productos seleccionados.</p>
    <p class="form-text">La selección se conserva entre páginas y filtros en esta pestaña. Solo se incluyen los productos que marques, aunque después no estén visibles. Máximo 10.000.</p>
    <noscript><p class="alert alert-warning">Activa JavaScript para usar la selección múltiple. Las acciones individuales siguen disponibles.</p></noscript>
    <div class="row g-3 align-items-end">
        <div class="col-12 col-md-4"><label class="form-label" for="masiva-operacion">Acción masiva</label><select class="form-select" id="masiva-operacion"><option value="categoria">Cambiar categoría</option><option value="publicar">Publicar</option><option value="ocultar">Ocultar</option></select></div>
        <div class="col-12 col-md-4" id="masiva-destino"><label class="form-label" for="masiva-categoria">Categoría destino</label><select class="form-select" id="masiva-categoria"><option value="">Selecciona una categoría</option><?php foreach ($categorias as $categoria): ?><option value="<?= (int) $categoria['id_categoria'] ?>"><?= escapar_panel($categoria['nombre']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 panel-acciones"><button type="button" class="btn btn-primary panel-boton" id="masiva-revisar" disabled>Revisar acción</button><button type="button" class="btn btn-outline-primary panel-boton" id="masiva-limpiar">Limpiar selección</button><a href="categorias.php" class="btn btn-outline-primary panel-boton">Administrar categorías</a></div>
    </div>
    <p class="alert alert-danger mt-3" id="masiva-error" role="alert" hidden></p>
    <section class="mt-3" id="masiva-previa" aria-labelledby="masiva-previa-titulo" hidden>
        <h4 class="h5" id="masiva-previa-titulo" tabindex="-1">Vista previa de la acción</h4>
        <p id="masiva-resumen"></p>
        <div class="table-responsive"><table class="table table-sm"><caption>Situación al preparar la vista previa</caption><thead><tr><th>Categoría actual</th><th>Estado actual</th><th>Cantidad</th></tr></thead><tbody id="masiva-grupos"></tbody></table></div>
        <p class="form-text">Cambiar categoría conserva la publicación. Publicar u ocultar conserva la categoría. Si cambia la categoría o el estado de un producto después de esta revisión, se omitirá. Cada lote se confirma por separado; detener no revierte los anteriores.</p>
        <div class="form-check mb-3" id="masiva-consentimiento"><input class="form-check-input" type="checkbox" id="masiva-aceptar"><label class="form-check-label" for="masiva-aceptar">Revisé la cantidad, la categoría y el estado; autorizo aplicar esta acción a los productos seleccionados.</label></div>
        <progress id="masiva-progreso" class="w-100" value="0" max="1" aria-label="Productos procesados"></progress>
        <p id="masiva-resultado" role="status"></p>
        <div class="panel-acciones"><button type="button" class="btn btn-danger panel-boton" id="masiva-confirmar" disabled>Confirmar y aplicar</button><button type="button" class="btn btn-primary panel-boton" id="masiva-continuar" hidden>Continuar lotes confirmados</button><button type="button" class="btn btn-outline-primary panel-boton" id="masiva-pausar" hidden>Pausar después de este lote</button><button type="button" class="btn btn-outline-primary panel-boton" id="masiva-cancelar">Cerrar operación</button><a href="<?= $accion_formulario ?>" class="btn btn-outline-primary panel-boton">Actualizar listado</a></div>
    </section>
</section>
<?php endif; ?>

<?php

declare(strict_types=1);
require_once __DIR__ . '/../app/Servicios/panel_administrativo.php';
require_once __DIR__ . '/../app/Servicios/importacion_productos.php';
[$conexion, $usuario] = iniciar_panel();
$titulo_pagina = 'Importar Excel'; $seccion_actual = 'productos';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($metodo, ['GET', 'POST'], true)) { header('Allow: GET, POST'); http_response_code(405); exit; }
$accion = $_POST['accion'] ?? '';
$json = $metodo === 'POST' && $accion === 'lote' && str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
$error_formulario = ''; $trabajo = null; $candado = null; $categorias = [];
$filtros = ['buscar' => '', 'solo_excluidos' => '0'];
$id = $metodo === 'POST' ? ($_POST['trabajo'] ?? null) : ($_GET['trabajo'] ?? null);
try {
    if ($metodo === 'POST') {
        if (!validar_token_csrf($_POST['token_csrf'] ?? null)) { http_response_code(400); throw new InvalidArgumentException('La solicitud venció o no es válida. Actualiza la página.'); }
        if ($accion === 'analizar') {
            if (!is_array($_FILES['excel'] ?? null)) { throw new InvalidArgumentException('Selecciona un archivo Excel.'); }
            $id = crear_previa_importacion($conexion, $_FILES['excel'], (int) $usuario['id_usuario']);
            header('Location: importar_productos.php?trabajo=' . $id, true, 303); exit;
        }
        // Serializa selecciones, confirmaciones y lotes, incluso entre cuentas distintas.
        $candado = @fopen(directorio_importaciones() . '/operaciones.lock', 'c');
        if ($candado === false || !flock($candado, LOCK_EX | LOCK_NB)) {
            http_response_code(409); throw new InvalidArgumentException('Hay otra operación de importación en curso. Espera y vuelve a intentarlo.');
        }
    }
    if ($id !== null) { $trabajo = abrir_importacion($id, (int) $usuario['id_usuario']); }
    if ($metodo === 'POST') {
        if ($trabajo === null) { throw new InvalidArgumentException('Selecciona una vista previa.'); }
        if ($accion === 'seleccion' || $accion === 'confirmar') {
            if ($trabajo['meta']['estado'] !== 'previa') { throw new InvalidArgumentException('La selección ya está confirmada.'); }
            if ($accion === 'seleccion') { seleccion_importacion($trabajo['db'], $_POST); }
            else { $trabajo['meta'] = confirmar_importacion($conexion, $trabajo['db'], $trabajo['meta'], $_POST, $usuario); }
        } elseif ($accion === 'lote') {
            $trabajo['meta'] = ejecutar_lote_importacion($conexion, $trabajo['db'], $trabajo['meta'], $usuario);
        } else { throw new InvalidArgumentException('Operación no válida.'); }
        if ($json) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['estado' => $trabajo['meta']['estado'], 'resumen' => resumen_importacion($trabajo['db'])], JSON_THROW_ON_ERROR); exit;
        }
        $pagina = filter_var($_POST['pagina'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 201]]) ?: 1;
        if ($accion === 'seleccion') { $filtros = filtros_previa_importacion($_POST); }
        header('Location: importar_productos.php?' . http_build_query(['trabajo' => $id, 'pagina' => $pagina] + $filtros), true, 303); exit;
    }
    if ($trabajo !== null && ($_GET['reporte'] ?? null) === '1') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="reporte-importacion-' . $trabajo['id'] . '.csv"');
        $salida = fopen('php://output', 'w'); fwrite($salida, "\xEF\xBB\xBF");
        fputcsv($salida, ['Fila Excel', 'Codigo', 'Nombre', 'Familia origen', 'Excluido', 'Estado', 'Error', 'Advertencia', 'Resultado'], ';', '"', '');
        foreach ($trabajo['db']->query('SELECT * FROM filas ORDER BY fila') as $fila) {
            $estado = $fila['estado'] === 'pendiente' && $fila['existente'] ? 'existente en vista previa' : $fila['estado'];
            $error = $fila['error'] ?: ($fila['duplicado'] ? 'Código duplicado dentro del archivo.' : '');
            fputcsv($salida, array_map('celda_reporte_importacion', [(string) $fila['fila'], $fila['codigo'], $fila['nombre'], $fila['familia'], $fila['excluido'] ? 'Sí' : 'No', $estado, $error, $fila['advertencia'], $fila['mensaje']]), ';', '"', '');
        }
        fclose($salida); exit;
    }
    $categorias = listar_categorias($conexion);
    $filtros = filtros_previa_importacion($metodo === 'POST' ? $_POST : $_GET);
} catch (InvalidArgumentException $error) {
    if (http_response_code() < 400) { http_response_code(422); }
    $error_formulario = $error->getMessage();
} catch (Throwable $error) {
    http_response_code(500);
    error_log('No se pudo completar una operación del importador de productos.');
    $error_formulario = 'No se pudo completar la operación. Los lotes anteriores se conservan. Actualiza la página y revisa el progreso antes de reintentar.';
} finally {
    if (is_resource($candado)) { flock($candado, LOCK_UN); fclose($candado); }
}
if ($json) { header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['error' => $error_formulario]); exit; }
$resumen = $trabajo ? resumen_importacion($trabajo['db']) : null;
$pagina = filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 201]]) ?: 1;
$paginas = 1; $filas = []; $coincidencias = 0;
if ($trabajo) {
    $resultado = filas_previa_importacion($trabajo['db'], $filtros, $pagina);
    $filas = $resultado['filas']; $pagina = $resultado['pagina'];
    $paginas = $resultado['paginas']; $coincidencias = $resultado['total'];
}
$token = escapar_panel(obtener_token_csrf());
require __DIR__ . '/../app/Vistas/plantillas/cabecera_administrativa.php';
?>
<div class="panel-titulo"><p class="panel-etiqueta">CATÁLOGO</p><h1>Importar Excel</h1><p>Solo crear nuevos. Los productos existentes se conservan y los nuevos quedan ocultos.</p></div>
<p><a class="btn btn-outline-primary panel-boton" href="productos.php">Volver a productos</a></p>
<?php if ($error_formulario !== ''): ?><p class="alert alert-danger" role="alert"><?= escapar_panel($error_formulario) ?></p><?php endif; ?>
<?php if ($trabajo === null): ?>
<section class="card panel-tarjeta"><h2>Preparar vista previa</h2><p>Este paso no crea productos ni categorías. Revisa las filas y decide cuáles incluir antes de confirmar.</p>
    <form action="importar_productos.php" method="post" enctype="multipart/form-data">
        <input type="hidden" name="token_csrf" value="<?= $token ?>"><input type="hidden" name="accion" value="analizar">
        <label class="form-label" for="excel">Archivo de inventario (.xlsx)</label>
        <input class="form-control mb-2" id="excel" name="excel" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required aria-describedby="excel-ayuda">
        <p id="excel-ayuda" class="form-text">Máximo 10 MB y 10.000 registros, en una sola hoja con Codigo y Descripcion. Los códigos deben estar guardados como texto. No se importan precios, costos, impuestos ni existencias.</p>
        <button class="btn btn-primary panel-boton" type="submit">Analizar y ver vista previa</button>
    </form>
</section>
<?php else: $previa = $trabajo['meta']['estado'] === 'previa'; ?>
<section class="card panel-tarjeta" aria-labelledby="resumen-titulo">
    <h2 id="resumen-titulo"><?= $previa ? 'Vista previa: todavía no se importó ningún producto' : 'Progreso de importación' ?></h2>
    <p>Hoja: <?= escapar_panel($trabajo['meta']['hoja']) ?>. <?= $resumen['total'] ?> registros. <?= $resumen['excluidos'] ?> excluidos.</p>
    <?php if ($previa): ?>
        <p><?= $resumen['nuevos'] ?> candidatos nuevos; <?= $resumen['existentes_previa'] ?> existentes; <?= $resumen['errores'] ?> filas con errores o duplicados sin excluir.</p>
        <p class="form-text">Comprueba los nombres, servicios y categorías. Los códigos conservan ceros iniciales y minúsculas. Las exclusiones se guardan al buscar o navegar por los resultados. Sin JavaScript, usa Guardar exclusiones antes de navegar. Guarda siempre antes de confirmar.</p>
    <?php else: $objetivo = $resumen['total'] - $resumen['excluidos']; ?>
        <progress id="progreso-importacion" class="w-100" max="<?= max(1, $objetivo) ?>" value="<?= $resumen['procesados'] ?>" aria-label="Filas procesadas"></progress>
        <p id="estado-importacion" role="status" aria-live="polite"><?= $resumen['procesados'] ?> de <?= $objetivo ?> procesados. Creados ocultos: <?= $resumen['creados'] ?>. Existentes omitidos: <?= $resumen['omitidos'] ?>. Por revisar tras interrupción: <?= $resumen['revisar'] ?>.</p>
        <?php if ($trabajo['meta']['estado'] === 'procesando'): ?>
            <form action="importar_productos.php" method="post" id="procesar-importacion" class="panel-acciones">
                <input type="hidden" name="token_csrf" value="<?= $token ?>"><input type="hidden" name="trabajo" value="<?= $trabajo['id'] ?>"><input type="hidden" name="accion" value="lote">
                <button class="btn btn-primary panel-boton" type="submit">Iniciar o continuar procesamiento</button>
                <button class="btn btn-outline-primary panel-boton" id="pausar-importacion" type="button" hidden>Detener después de este lote</button>
            </form>
            <p class="form-text">Se crean productos ocultos en lotes de 200. Si sales de esta página, revisa el progreso y continúa desde aquí. Sin JavaScript se procesa un lote por pulsación.</p>
        <?php else: ?><p class="alert alert-success">Procesamiento terminado. Los productos creados permanecen ocultos.</p><?php endif; ?>
    <?php endif; ?>
    <p><a href="importar_productos.php?trabajo=<?= $trabajo['id'] ?>&amp;reporte=1">Descargar reporte CSV sin información financiera</a></p>
    <p class="form-text">Al abrir el reporte en Excel, selecciona texto para la columna Codigo. Esta vista privada pertenece a tu cuenta y vence a las 24 horas.</p>
</section>
<section class="card panel-tarjeta"><h2>Filas del archivo</h2>
    <form action="importar_productos.php" method="get" id="filtros-importacion" class="row g-3 align-items-end mb-3">
        <input type="hidden" name="trabajo" value="<?= $trabajo['id'] ?>">
        <div class="col-12 col-lg-6"><label class="form-label" for="buscar-importacion">Buscar por código o nombre</label>
            <input class="form-control" type="search" id="buscar-importacion" name="buscar" maxlength="150" value="<?= escapar_panel($filtros['buscar']) ?>">
        </div>
        <div class="col-12 col-lg-auto"><div class="form-check"><input class="form-check-input" type="checkbox" id="solo-excluidos" name="solo_excluidos" value="1"<?= $filtros['solo_excluidos'] === '1' ? ' checked' : '' ?>><label class="form-check-label" for="solo-excluidos">Solo productos excluidos</label></div></div>
        <div class="col-12 col-lg-auto panel-acciones"><button class="btn btn-primary panel-boton" type="submit">Buscar</button><a class="btn btn-outline-primary panel-boton" data-navegar-previa href="importar_productos.php?trabajo=<?= $trabajo['id'] ?>">Limpiar búsqueda y filtros</a></div>
    </form>
    <p id="coincidencias-importacion" role="status"><?= $coincidencias ?> coincidencias de <?= $resumen['total'] ?> registros analizados.</p>
    <form action="importar_productos.php" method="post" id="seleccion-importacion">
        <input type="hidden" name="token_csrf" value="<?= $token ?>"><input type="hidden" name="trabajo" value="<?= $trabajo['id'] ?>"><input type="hidden" name="accion" value="seleccion"><input type="hidden" name="pagina" value="<?= $pagina ?>">
        <input type="hidden" name="buscar" value="<?= escapar_panel($filtros['buscar']) ?>"><input type="hidden" name="solo_excluidos" value="<?= $filtros['solo_excluidos'] ?>">
        <div class="table-responsive" role="region" aria-label="Vista previa del inventario" tabindex="0">
            <table class="table table-hover align-middle panel-tabla"><thead><tr><?php if ($previa): ?><th>Excluir</th><?php endif; ?><th>Fila</th><th>Código original</th><th>Nombre propuesto</th><th>Familia origen</th><th>Validación y resultado</th></tr></thead><tbody>
                <?php foreach ($filas as $fila): ?><tr>
                    <?php if ($previa): ?><td><input class="form-check-input" type="checkbox" name="excluir[]" value="<?= $fila['fila'] ?>" aria-label="Excluir fila <?= $fila['fila'] ?>"<?= $fila['excluido'] ? ' checked' : '' ?>></td><?php endif; ?>
                    <td><?= $fila['fila'] ?></td><td><?= escapar_panel($fila['codigo']) ?></td>
                    <th scope="row"><?= escapar_panel($fila['nombre']) ?><?php if ($fila['nombre'] !== $fila['original']): ?><small class="d-block fw-normal">Original: <?= escapar_panel($fila['original']) ?></small><?php endif; ?></th>
                    <td><?= escapar_panel($fila['familia']) ?></td>
                    <td><?php if ($fila['excluido']): ?>Excluido. <?php endif; ?><?= escapar_panel($fila['error']) ?><?= $fila['duplicado'] ? ' Código duplicado dentro del archivo.' : '' ?><?= $fila['existente'] ? ' Ya existe; se omitirá.' : '' ?> <?= escapar_panel($fila['advertencia']) ?> <?= escapar_panel($fila['mensaje']) ?></td>
                </tr><?php endforeach; ?>
                <?php if (!$filas): ?><tr><td colspan="<?= $previa ? 6 : 5 ?>">No hay registros que coincidan con estos filtros.</td></tr><?php endif; ?>
            </tbody></table>
        </div>
        <?php if ($previa): ?><p class="alert alert-warning" id="exclusiones-pendientes" role="alert" hidden>Guarda las exclusiones de esta página antes de confirmar.</p><button class="btn btn-outline-primary panel-boton" type="submit">Guardar exclusiones de esta página</button><?php endif; ?>
    </form>
    <nav class="panel-acciones mt-3" aria-label="Paginación de vista previa">
        <?php if ($pagina > 1): ?><a class="btn btn-outline-primary panel-boton" data-navegar-previa href="importar_productos.php?<?= escapar_panel(http_build_query(['trabajo' => $trabajo['id'], 'pagina' => $pagina - 1] + $filtros)) ?>">Anterior</a><?php endif; ?>
        <span>Página <?= $pagina ?> de <?= $paginas ?></span>
        <?php if ($pagina < $paginas): ?><a class="btn btn-outline-primary panel-boton" data-navegar-previa href="importar_productos.php?<?= escapar_panel(http_build_query(['trabajo' => $trabajo['id'], 'pagina' => $pagina + 1] + $filtros)) ?>">Siguiente</a><?php endif; ?>
    </nav>
</section>
<?php if ($previa): ?>
<section class="card panel-tarjeta"><h2>Confirmar importación</h2>
    <p>Todos los productos nuevos incluidos usarán la categoría seleccionada. No se actualizarán ni eliminarán productos existentes.</p>
    <form action="importar_productos.php" method="post" id="confirmar-importacion">
        <input type="hidden" name="token_csrf" value="<?= $token ?>"><input type="hidden" name="trabajo" value="<?= $trabajo['id'] ?>"><input type="hidden" name="accion" value="confirmar">
        <label class="form-label" for="categoria_importacion">Categoría destino</label>
        <select class="form-select mb-3" id="categoria_importacion" name="categoria" required><option value="">Selecciona una categoría</option>
            <?php foreach ($categorias as $categoria): ?><option value="<?= (int) $categoria['id_categoria'] ?>"><?= escapar_panel($categoria['nombre']) ?></option><?php endforeach; ?>
            <?php if ($usuario['rol'] === 'administrador'): ?><option value="sin_clasificar">Sin clasificar (provisional)</option><?php endif; ?>
        </select>
        <?php if ($usuario['rol'] === 'administrador'): ?><div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="autorizar_provisional" id="autorizar_provisional" value="1"><label class="form-check-label" for="autorizar_provisional">Autorizo expresamente crear o usar la categoría provisional «Sin clasificar», si la selecciono.</label></div><?php endif; ?>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="confirmar" id="confirmar_ocultos" value="1" required><label class="form-check-label" for="confirmar_ocultos">Revisé la selección y confirmo importar los productos nuevos como ocultos.</label></div>
        <button class="btn btn-primary panel-boton" type="submit"<?= $resumen['errores'] > 0 || $resumen['nuevos'] === 0 ? ' disabled' : '' ?>>Confirmar importación de productos ocultos</button>
    </form>
</section>
<?php endif; endif; ?>
<script src="assets/js/importar_productos.js" defer></script>
<?php require __DIR__ . '/../app/Vistas/plantillas/pie_administrativo.php'; ?>

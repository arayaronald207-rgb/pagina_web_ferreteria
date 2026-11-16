<?php

declare(strict_types=1);
require_once __DIR__ . '/productos.php';
require_once __DIR__ . '/lector_inventario.php';

const TAMANO_LOTE_IMPORTACION = 200;

function directorio_importaciones(): string
{
    $ruta = dirname(__DIR__, 2) . '/almacenamiento/importaciones';
    if (!is_dir($ruta) && !@mkdir($ruta, 0700, true) && !is_dir($ruta)) { throw new RuntimeException('No se pudo preparar el almacenamiento privado.'); }
    return $ruta;
}

function guardar_meta_importacion(PDO $trabajo, array $meta): void
{
    $consulta = $trabajo->prepare('UPDATE meta SET datos = :datos WHERE id = 1');
    $consulta->execute(['datos' => json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
}

function abrir_importacion(mixed $id, int $usuario): array
{
    if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/', $id)) { throw new InvalidArgumentException('La vista previa no está disponible.'); }
    $ruta = directorio_importaciones() . '/' . $id . '.sqlite';
    if (!is_file($ruta)) { throw new InvalidArgumentException('La vista previa no está disponible.'); }
    $db = new PDO('sqlite:' . $ruta, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA busy_timeout = 5000');
    $meta = json_decode($db->query('SELECT datos FROM meta WHERE id = 1')->fetchColumn(), true, 16, JSON_THROW_ON_ERROR);
    if ($meta['usuario'] !== $usuario || $meta['vence'] < time()) { throw new InvalidArgumentException('La vista previa venció o pertenece a otra cuenta.'); }
    return ['id' => $id, 'db' => $db, 'meta' => $meta];
}

function duplicados_importacion(PDO $trabajo): void
{
    $trabajo->exec('UPDATE filas SET duplicado = 0');
    $trabajo->exec("UPDATE filas SET duplicado = 1 WHERE excluido = 0 AND error = '' AND UPPER(codigo) IN
        (SELECT UPPER(codigo) FROM filas WHERE excluido = 0 AND error = '' GROUP BY UPPER(codigo) HAVING COUNT(*) > 1)");
}

function crear_previa_importacion(PDO $conexion, array $archivo, int $usuario): string
{
    if (($archivo['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($archivo['tmp_name'] ?? null)
        || !is_uploaded_file($archivo['tmp_name']) || !is_string($archivo['name'] ?? null)
        || strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
        throw new InvalidArgumentException('Selecciona un archivo .xlsx válido. Revisa también el límite de subida del servidor.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
    if (!in_array($mime, ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true)) {
        throw new InvalidArgumentException('El contenido del archivo no corresponde a un libro XLSX.');
    }
    $datos = leer_inventario_xlsx($archivo['tmp_name']);
    $id = bin2hex(random_bytes(16));
    $db = new PDO('sqlite:' . directorio_importaciones() . '/' . $id . '.sqlite', null, null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    // Solo el trabajo privado usa SQLite. No se crea ni altera ninguna tabla de MySQL.
    $db->exec('CREATE TABLE meta (id INTEGER PRIMARY KEY, datos TEXT NOT NULL)');
    $db->exec("INSERT INTO meta VALUES (1, '{}')");
    $db->exec("CREATE TABLE filas (fila INTEGER PRIMARY KEY, codigo TEXT NOT NULL, original TEXT NOT NULL,
        nombre TEXT NOT NULL, familia TEXT NOT NULL, error TEXT NOT NULL, advertencia TEXT NOT NULL,
        duplicado INTEGER NOT NULL DEFAULT 0, existente INTEGER NOT NULL DEFAULT 0,
        excluido INTEGER NOT NULL DEFAULT 0, estado TEXT NOT NULL DEFAULT 'pendiente', mensaje TEXT NOT NULL DEFAULT '')");
    $db->exec('CREATE INDEX filas_estado ON filas (estado, fila)');
    $meta = ['usuario' => $usuario, 'creado' => time(), 'vence' => time() + 86400, 'estado' => 'previa',
        'hoja' => $datos['hoja'], 'hash' => hash_file('sha256', $archivo['tmp_name']), 'categoria' => null, 'provisional' => false];
    $db->beginTransaction();
    $insertar = $db->prepare('INSERT INTO filas (fila, codigo, original, nombre, familia, error, advertencia) VALUES (:fila, :codigo, :original, :nombre, :familia, :error, :advertencia)');
    foreach ($datos['filas'] as $fila) {
        $fila['nombre'] = $fila['original'];
        try {
            $validado = datos_producto(['codigo' => $fila['codigo'], 'nombre' => $fila['original'], 'descripcion' => '', 'id_categoria' => '1', 'visible' => '0']);
            $fila['nombre'] = $validado['nombre'];
        } catch (InvalidArgumentException $error) { $fila['error'] = $fila['error'] ?: $error->getMessage(); }
        $avisos = [];
        if ($fila['nombre'] !== $fila['original']) { $avisos[] = 'Se normalizarán los espacios del nombre.'; }
        if (in_array(mb_strtoupper(trim($fila['nombre'])), ['FLETE', 'SERVICIO CORTE EN BODEGA', 'ALQUILER CASA DE HABITACION'], true)) { $avisos[] = 'Revisar si corresponde al catálogo comercial.'; }
        $fila['advertencia'] = implode(' ', $avisos);
        $insertar->execute($fila);
    }
    duplicados_importacion($db);
    // Comparar con la intercalación real de MySQL; no convertir el valor que se guardará.
    $codigos = $db->query("SELECT DISTINCT codigo FROM filas WHERE error = ''")->fetchAll(PDO::FETCH_COLUMN);
    $marcar = $db->prepare('UPDATE filas SET existente = 1 WHERE UPPER(codigo) = :codigo');
    foreach (array_chunk($codigos, 200) as $grupo) {
        $consulta = $conexion->prepare('SELECT codigo FROM productos WHERE codigo IN (' . implode(',', array_fill(0, count($grupo), '?')) . ')');
        $consulta->execute($grupo);
        foreach ($consulta->fetchAll(PDO::FETCH_COLUMN) as $codigo) { $marcar->execute(['codigo' => strtoupper($codigo)]); }
    }
    guardar_meta_importacion($db, $meta);
    $db->commit();
    return $id;
}

function resumen_importacion(PDO $db): array
{
    $fila = $db->query("SELECT COUNT(*) AS total,
        COALESCE(SUM(excluido = 1), 0) AS excluidos,
        COALESCE(SUM(excluido = 0 AND (error <> '' OR duplicado = 1)), 0) AS errores,
        COALESCE(SUM(excluido = 0 AND error = '' AND duplicado = 0 AND existente = 0), 0) AS nuevos,
        COALESCE(SUM(excluido = 0 AND existente = 1), 0) AS existentes_previa,
        COALESCE(SUM(estado = 'creado'), 0) AS creados,
        COALESCE(SUM(estado = 'existente'), 0) AS omitidos,
        COALESCE(SUM(estado = 'revisar'), 0) AS revisar,
        COALESCE(SUM(excluido = 0 AND estado IN ('creado', 'existente', 'revisar')), 0) AS procesados
        FROM filas")->fetch();
    return array_map('intval', $fila);
}

function filtros_previa_importacion(array $entrada): array
{
    $buscar = $entrada['buscar'] ?? '';
    $excluidos = $entrada['solo_excluidos'] ?? '0';
    if (!is_string($buscar) || !mb_check_encoding($buscar, 'UTF-8') || mb_strlen($buscar, 'UTF-8') > 150
        || !in_array($excluidos, ['0', '1'], true)) {
        throw new InvalidArgumentException('Los filtros de la vista previa no son válidos.');
    }
    return ['buscar' => trim($buscar), 'solo_excluidos' => $excluidos];
}

function filas_previa_importacion(PDO $db, array $filtros, int $pagina): array
{
    // SQLite LOWER no convierte las letras acentuadas. Solo comparar; nunca alterar los códigos.
    $db->sqliteCreateFunction('minusculas_previa', static fn(string $valor): string => mb_strtolower($valor, 'UTF-8'), 1);
    $condiciones = []; $parametros = [];
    if ($filtros['buscar'] !== '') {
        $condiciones[] = '(instr(minusculas_previa(codigo), :buscar) > 0 OR instr(minusculas_previa(nombre), :buscar) > 0)';
        $parametros[':buscar'] = mb_strtolower($filtros['buscar'], 'UTF-8');
    }
    if ($filtros['solo_excluidos'] === '1') { $condiciones[] = 'excluido = 1'; }
    $where = $condiciones ? ' WHERE ' . implode(' AND ', $condiciones) : '';
    $contar = $db->prepare('SELECT COUNT(*) FROM filas' . $where);
    $contar->execute($parametros); $total = (int) $contar->fetchColumn();
    $paginas = max(1, (int) ceil($total / 50));
    $pagina = min(max(1, $pagina), $paginas);
    $consulta = $db->prepare('SELECT * FROM filas' . $where . ' ORDER BY fila LIMIT 50 OFFSET :inicio');
    foreach ($parametros as $clave => $valor) { $consulta->bindValue($clave, $valor, PDO::PARAM_STR); }
    $consulta->bindValue(':inicio', ($pagina - 1) * 50, PDO::PARAM_INT);
    $consulta->execute();
    return ['filas' => $consulta->fetchAll(), 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
}

function seleccion_importacion(PDO $db, array $entrada): void
{
    $pagina = filter_var($entrada['pagina'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 201]]);
    $excluir = $entrada['excluir'] ?? [];
    if ($pagina === false || !is_array($excluir) || count($excluir) > 50) { throw new InvalidArgumentException('La selección no es válida.'); }
    $ids = array_map('validar_id_producto', $excluir);
    $resultado = filas_previa_importacion($db, filtros_previa_importacion($entrada), $pagina);
    if ($resultado['pagina'] !== $pagina) { throw new InvalidArgumentException('Actualiza la página antes de guardar la selección.'); }
    $visibles = array_map('intval', array_column($resultado['filas'], 'fila'));
    if (array_diff($ids, $visibles)) { throw new InvalidArgumentException('La selección no corresponde a esta página.'); }
    $db->beginTransaction();
    try {
        $guardar = $db->prepare('UPDATE filas SET excluido = :excluido WHERE fila = :fila');
        foreach ($visibles as $fila) { $guardar->execute(['fila' => $fila, 'excluido' => in_array($fila, $ids, true) ? 1 : 0]); }
        duplicados_importacion($db); $db->commit();
    } catch (Throwable $error) { $db->rollBack(); throw $error; }
}

function confirmar_importacion(PDO $conexion, PDO $db, array $meta, array $entrada, array $usuario): array
{
    if (($entrada['confirmar'] ?? null) !== '1') { throw new InvalidArgumentException('Debes confirmar expresamente la importación de productos ocultos.'); }
    $resumen = resumen_importacion($db);
    if ($resumen['errores'] > 0) { throw new InvalidArgumentException('Excluye las filas inválidas o duplicadas antes de confirmar.'); }
    if ($resumen['nuevos'] === 0) { throw new InvalidArgumentException('No hay productos nuevos seleccionados para importar.'); }
    $categoria = $entrada['categoria'] ?? null;
    $provisional = $categoria === 'sin_clasificar';
    if (!$provisional) {
        $categoria = validar_id_categoria($categoria);
        $registro = buscar_categoria($conexion, $categoria);
        $provisional = mb_strtolower(trim($registro['nombre'])) === 'sin clasificar';
    }
    if ($provisional && ($usuario['rol'] !== 'administrador' || ($entrada['autorizar_provisional'] ?? null) !== '1')) {
        throw new InvalidArgumentException('Solo un administrador puede autorizar expresamente la categoría Sin clasificar.');
    }
    $meta['categoria'] = $categoria;
    $meta['provisional'] = $provisional;
    $meta['estado'] = 'procesando';
    $meta['confirmado'] = time();
    $db->beginTransaction();
    try {
        $db->exec("UPDATE filas SET estado = 'excluido', mensaje = 'Excluido por el usuario.' WHERE excluido = 1");
        guardar_meta_importacion($db, $meta); $db->commit();
    } catch (Throwable $error) { $db->rollBack(); throw $error; }
    return $meta;
}

function es_codigo_duplicado_importacion(PDOException $error): bool
{
    return (int) ($error->errorInfo[1] ?? 0) === 1062
        || ((int) ($error->errorInfo[1] ?? 0) === 19 && str_contains($error->getMessage(), 'productos.codigo'));
}

function ejecutar_lote_importacion(PDO $conexion, PDO $db, array $meta, array $usuario): array
{
    if ($meta['estado'] === 'previa') { throw new InvalidArgumentException('Primero debes confirmar la importación.'); }
    if ($meta['estado'] !== 'procesando') { return $meta; }
    if ($meta['provisional'] && $usuario['rol'] !== 'administrador') { throw new InvalidArgumentException('Este trabajo requiere un administrador.'); }
    $buscar = $conexion->prepare('SELECT id_producto FROM productos WHERE codigo = :codigo');
    $resultado = $db->prepare('UPDATE filas SET estado = :estado, mensaje = :mensaje WHERE fila = :fila');
    // Recuperación conservadora de un corte entre el COMMIT de MySQL y el registro local.
    foreach ($db->query("SELECT fila, codigo FROM filas WHERE estado = 'en_lote'")->fetchAll() as $fila) {
        $buscar->execute(['codigo' => $fila['codigo']]);
        $presente = $buscar->fetchColumn() !== false;
        $resultado->execute(['fila' => $fila['fila'], 'estado' => $presente ? 'revisar' : 'pendiente',
            'mensaje' => $presente ? 'Presente tras una interrupción. No se modificó; revisar su procedencia.' : '']);
    }
    $filas = $db->query("SELECT * FROM filas WHERE excluido = 0 AND estado = 'pendiente' ORDER BY fila LIMIT " . TAMANO_LOTE_IMPORTACION)->fetchAll();
    $db->beginTransaction();
    foreach ($filas as $fila) { $resultado->execute(['fila' => $fila['fila'], 'estado' => 'en_lote', 'mensaje' => '']); }
    $db->commit();
    $salidas = []; $confirmado = false;
    try {
        $conexion->beginTransaction();
        $categoria = $meta['categoria'] === 'sin_clasificar' ? null : (int) $meta['categoria'];
        if ($categoria !== null) { buscar_categoria($conexion, $categoria); }
        $insertar = preparar_insercion_producto($conexion);
        foreach ($filas as $fila) {
            $buscar->execute(['codigo' => $fila['codigo']]);
            if ($buscar->fetchColumn() !== false) {
                $salidas[] = ['fila' => $fila['fila'], 'estado' => 'existente', 'mensaje' => 'Código existente; no se modificó.']; continue;
            }
            if ($categoria === null) {
                $consulta = $conexion->prepare('SELECT id_categoria FROM categorias WHERE nombre = :nombre');
                $consulta->execute(['nombre' => 'Sin clasificar']);
                $encontrada = $consulta->fetchColumn();
                if ($encontrada === false) {
                    try { guardar_categoria($conexion, 'Sin clasificar'); }
                    catch (InvalidArgumentException $error) {
                        $consulta->execute(['nombre' => 'Sin clasificar']);
                        if ($consulta->fetchColumn() === false) { throw $error; }
                    }
                    $consulta->execute(['nombre' => 'Sin clasificar']); $encontrada = $consulta->fetchColumn();
                }
                $categoria = (int) $encontrada;
                if ($categoria < 1) { throw new RuntimeException('No se pudo resolver la categoría.'); }
            }
            $datos = datos_producto(['codigo' => $fila['codigo'], 'nombre' => $fila['nombre'], 'descripcion' => '', 'id_categoria' => $categoria, 'visible' => '0']);
            $datos['descripcion'] = null; $datos['imagen'] = null;
            try {
                insertar_producto_validado($conexion, $datos, $insertar);
                $salidas[] = ['fila' => $fila['fila'], 'estado' => 'creado', 'mensaje' => 'Creado oculto.'];
            } catch (PDOException $error) {
                if (!es_codigo_duplicado_importacion($error)) { throw $error; }
                $salidas[] = ['fila' => $fila['fila'], 'estado' => 'existente', 'mensaje' => 'Código registrado por otra operación; no se modificó.'];
            }
        }
        $conexion->commit(); $confirmado = true;
        $db->beginTransaction();
        foreach ($salidas as $salida) { $resultado->execute($salida); }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) { $db->rollBack(); }
        if (!$confirmado && $conexion->inTransaction()) {
            $conexion->rollBack();
            foreach ($filas as $fila) { $resultado->execute(['fila' => $fila['fila'], 'estado' => 'pendiente', 'mensaje' => 'Lote revertido; pendiente de reintento.']); }
        }
        // Si se desconoce el resultado del COMMIT, mantener en_lote y conciliar al reintentar.
        throw $error;
    }
    if ((int) $db->query("SELECT COUNT(*) FROM filas WHERE excluido = 0 AND estado IN ('pendiente', 'en_lote')")->fetchColumn() === 0) {
        $meta['estado'] = 'terminado'; guardar_meta_importacion($db, $meta);
    }
    return $meta;
}

function celda_reporte_importacion(string $valor): string
{
    // El reporte CSV protege fórmulas; los códigos deben abrirse como texto en Excel.
    return preg_match('/\A[\s]*[=+@-]/u', $valor) ? "'" . $valor : $valor;
}

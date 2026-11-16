<?php

declare(strict_types=1);
require_once __DIR__ . '/productos.php';

const LIMITE_SELECCION_PRODUCTOS = 10000;
const LOTE_PRODUCTOS_MASIVOS = 100;

function preparar_accion_productos(PDO $db, array $entrada): array
{
    $ids = $entrada['ids'] ?? null;
    $accion = $entrada['operacion'] ?? null;
    if (!is_array($ids) || !$ids || count($ids) > LIMITE_SELECCION_PRODUCTOS
        || !in_array($accion, ['categoria', 'publicar', 'ocultar'], true)) {
        throw new InvalidArgumentException('Selecciona entre 1 y 10.000 productos y una acción válida.');
    }
    $ids = array_values(array_unique(array_map('validar_id_producto', $ids)));
    sort($ids, SORT_NUMERIC);
    $destino = $accion === 'categoria' ? buscar_categoria($db, validar_id_categoria($entrada['categoria'] ?? null)) : null;
    $filas = []; $grupos = []; $cambios = 0;
    foreach (array_chunk($ids, 200) as $grupo) {
        $q = $db->prepare('SELECT p.id_producto, p.id_categoria, p.visible, c.nombre AS categoria
            FROM productos p JOIN categorias c ON c.id_categoria=p.id_categoria
            WHERE p.id_producto IN (' . implode(',', array_fill(0, count($grupo), '?')) . ') ORDER BY p.id_producto');
        $q->execute($grupo);
        foreach ($q->fetchAll() as $fila) {
            $filas[] = ['id' => (int) $fila['id_producto'], 'categoria' => (int) $fila['id_categoria'], 'visible' => (int) $fila['visible']];
            $clave = $fila['id_categoria'] . ':' . $fila['visible'];
            if (!isset($grupos[$clave])) { $grupos[$clave] = ['categoria' => $fila['categoria'], 'visible' => (int) $fila['visible'], 'cantidad' => 0]; }
            $grupos[$clave]['cantidad']++;
            $cambios += $accion === 'categoria' ? (int) ((int) $fila['id_categoria'] !== (int) $destino['id_categoria'])
                : (int) ((int) $fila['visible'] !== ($accion === 'publicar' ? 1 : 0));
        }
    }
    if (count($filas) !== count($ids)) { throw new InvalidArgumentException('Hay productos que ya no existen. Limpia la selección y revisa el listado.'); }
    return ['id' => bin2hex(random_bytes(16)), 'vence' => time() + 3600, 'estado' => 'previa',
        'operacion' => $accion, 'destino' => $destino ? ['id' => (int) $destino['id_categoria'], 'nombre' => $destino['nombre']] : null,
        'filas' => $filas, 'grupos' => array_values($grupos), 'cambios_previstos' => $cambios,
        'procesados' => 0, 'modificados' => 0, 'sin_cambios' => 0, 'omitidos' => 0];
}

function resumen_accion_productos(array $trabajo): array
{
    $resumen = $trabajo;
    unset($resumen['filas'], $resumen['vence']);
    $resumen['total'] = count($trabajo['filas']);
    return $resumen;
}

function procesar_accion_productos(PDO $db, array $trabajo, mixed $inicio): array
{
    if (!is_int($inicio) || $inicio < 0 || $inicio > $trabajo['procesados']) { throw new InvalidArgumentException('El progreso no es válido. Actualiza la vista previa.'); }
    if (!in_array($trabajo['estado'], ['procesando', 'terminado'], true)) { throw new InvalidArgumentException('Confirma la vista previa antes de procesar.'); }
    // Reintentar una respuesta perdida no debe ejecutar el siguiente lote.
    if ($inicio < $trabajo['procesados'] || $trabajo['estado'] === 'terminado') { return $trabajo; }
    $filas = array_slice($trabajo['filas'], $inicio, LOTE_PRODUCTOS_MASIVOS);
    $db->beginTransaction();
    try {
        if ($trabajo['destino']) { buscar_categoria($db, $trabajo['destino']['id']); }
        $buscar = $db->prepare('SELECT id_categoria, visible FROM productos WHERE id_producto = :id'
            . ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
        // El nombre de columna procede de la lista cerrada de operaciones, nunca del cliente.
        $campo = $trabajo['operacion'] === 'categoria' ? 'id_categoria' : 'visible';
        $valor = $trabajo['destino']['id'] ?? ($trabajo['operacion'] === 'publicar' ? 1 : 0);
        $actualizar = $db->prepare('UPDATE productos SET ' . $campo . ' = :valor WHERE id_producto = :id');
        foreach ($filas as $fila) {
            $buscar->execute(['id' => $fila['id']]); $actual = $buscar->fetch();
            if (!$actual || (int) $actual['id_categoria'] !== $fila['categoria'] || (int) $actual['visible'] !== $fila['visible']) {
                $trabajo['omitidos']++;
            } elseif ((int) $actual[$campo] === $valor) {
                $trabajo['sin_cambios']++;
            } else {
                $actualizar->execute(['valor' => $valor, 'id' => $fila['id']]); $trabajo['modificados']++;
            }
            $trabajo['procesados']++;
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) { $db->rollBack(); }
        throw $error;
    }
    if ($trabajo['procesados'] === count($trabajo['filas'])) { $trabajo['estado'] = 'terminado'; }
    return $trabajo;
}

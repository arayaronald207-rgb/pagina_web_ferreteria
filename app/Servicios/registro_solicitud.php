<?php

declare(strict_types=1);
require_once __DIR__ . '/lista_solicitud.php';

function validar_cliente_solicitud(array $entrada): array
{
    $datos = [];
    foreach (['cliente_nombre' => 120, 'cliente_whatsapp' => 25, 'cliente_correo' => 150, 'observaciones' => 2000] as $campo => $limite) {
        $valor = $entrada[$campo] ?? '';
        if (!is_string($valor) || !mb_check_encoding($valor, 'UTF-8') || mb_strlen($valor, 'UTF-8') > $limite
            || preg_match($campo === 'observaciones' ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', $valor)) {
            throw new InvalidArgumentException('Revisa los datos del cliente y la longitud de los campos.');
        }
        $datos[$campo] = trim($valor);
    }
    if (mb_strlen($datos['cliente_nombre'], 'UTF-8') < 3 || !preg_match('/\p{L}.*\s+\p{L}/u', $datos['cliente_nombre'])) {
        throw new InvalidArgumentException('Escribe tu nombre y apellidos.');
    }
    $telefono = $datos['cliente_whatsapp'];
    $digitos = preg_replace('/\D/', '', $telefono);
    if (!preg_match('/\A\+?[0-9][0-9 ()-]*\z/', $telefono) || strlen($digitos) < 8 || strlen($digitos) > 15) {
        throw new InvalidArgumentException('Escribe un WhatsApp válido, con entre 8 y 15 dígitos e indicativo del país cuando corresponda.');
    }
    $datos['cliente_whatsapp'] = (str_starts_with($telefono, '+') ? '+' : '') . $digitos;
    if (!filter_var($datos['cliente_correo'], FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Escribe un correo electrónico válido.');
    }
    return $datos;
}

// Borradores y recibos privados en la sesión anónima; no escriben en MySQL.
function crear_intento_solicitud(array &$intentos): string
{
    foreach ($intentos as $token => $intento) {
        if (($intento['creado'] ?? 0) < time() - 7200) { unset($intentos[$token]); }
    }
    if (count($intentos) >= 30) { array_shift($intentos); }
    $token = bin2hex(random_bytes(32));
    $intentos[$token] = ['creado' => time(), 'lista' => null, 'id_solicitud' => null];
    return $token;
}

function intento_solicitud_valido(array $intentos, mixed $token): bool
{
    return is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token) === 1
        && isset($intentos[$token]) && $intentos[$token]['creado'] >= time() - 7200;
}

function preparar_borrador_solicitud(PDO $db, array &$borrador, mixed $entrada): void
{
    $lista = normalizar_lista_solicitud($entrada);
    if (!$lista) { throw new InvalidArgumentException('Agrega al menos un producto a Mi solicitud.'); }
    if ($borrador['lista'] !== null) {
        if ($borrador['lista'] !== $lista) {
            throw new InvalidArgumentException('La lista cambió. Vuelve a Mi solicitud y continúa nuevamente.');
        }
        return;
    }
    $resultado = validar_lista_solicitud($db, $lista);
    if ($resultado['retirados']) {
        throw new InvalidArgumentException('Algunos productos ya no están disponibles. Revisa Mi solicitud antes de continuar.');
    }
    $borrador['lista'] = $lista;
}

function registrar_solicitud(PDO $db, array $entrada, mixed $productos): int
{
    $cliente = validar_cliente_solicitud($entrada);
    $lista = normalizar_lista_solicitud($productos);
    if (!$lista) { throw new InvalidArgumentException('Agrega al menos un producto a Mi solicitud.'); }
    $condicion = condicion_categoria_publica($db);
    $db->beginTransaction();
    try {
        // Bloquea productos y categorías durante la comprobación y el registro.
        $consulta = $db->prepare('SELECT p.id_producto, p.nombre FROM productos p
            INNER JOIN categorias c ON c.id_categoria = p.id_categoria
            WHERE p.visible = 1 AND ' . $condicion . ' AND p.id_producto IN ('
            . implode(',', array_fill(0, count($lista), '?')) . ') FOR UPDATE');
        $consulta->execute(array_column($lista, 'id_producto'));
        $publicados = [];
        foreach ($consulta->fetchAll() as $producto) { $publicados[(int) $producto['id_producto']] = $producto['nombre']; }
        foreach ($lista as $linea) {
            if (!isset($publicados[$linea['id_producto']])) {
                throw new InvalidArgumentException('Algunos productos ya no están disponibles. No se registró la solicitud; revisa tu lista.');
            }
        }
        $cabecera = $db->prepare("INSERT INTO solicitudes
            (cliente_nombre, cliente_whatsapp, cliente_correo, observaciones, estado, fecha_creacion)
            VALUES (:cliente_nombre, :cliente_whatsapp, :cliente_correo, :observaciones, 'Pendiente', CURRENT_TIMESTAMP)");
        $cliente['observaciones'] = $cliente['observaciones'] === '' ? null : $cliente['observaciones'];
        $cabecera->execute($cliente);
        $id = (int) $db->lastInsertId();
        if ($id < 1) { throw new RuntimeException('No se obtuvo el identificador del registro.'); }
        $detalle = $db->prepare('INSERT INTO detalle_solicitud (id_solicitud, id_producto, producto_nombre, cantidad) VALUES (?, ?, ?, ?)');
        foreach ($lista as $linea) {
            $detalle->execute([$id, $linea['id_producto'], $publicados[$linea['id_producto']], $linea['cantidad']]);
        }
        $db->commit();
        return $id;
    } catch (Throwable $error) {
        if ($db->inTransaction()) { $db->rollBack(); }
        throw $error;
    }
}

function confirmar_borrador_solicitud(PDO $db, array &$borrador, array $cliente): int
{
    // La sesión permanece bloqueada hasta guardar el recibo: dos POST concurrentes
    // del mismo formulario comparten el resultado y no vuelven a insertar.
    if ($borrador['id_solicitud'] !== null) { return $borrador['id_solicitud']; }
    $id = registrar_solicitud($db, $cliente, $borrador['lista']);
    $borrador['id_solicitud'] = $id;
    return $id;
}

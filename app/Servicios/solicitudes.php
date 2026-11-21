<?php

declare(strict_types=1);

const ESTADOS_SOLICITUD = ['Pendiente', 'En proceso', 'Atendida', 'Cancelada'];

function validar_id_solicitud(mixed $valor): int
{
    if ((!is_string($valor) && !is_int($valor)) || !preg_match('/\A[1-9][0-9]*\z/', (string) $valor)) {
        throw new InvalidArgumentException('El número de solicitud no es válido.');
    }
    $id = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    if ($id === false) { throw new InvalidArgumentException('El número de solicitud no es válido.'); }
    return $id;
}

function validar_estado_solicitud(mixed $estado): string
{
    if (!is_string($estado) || !in_array($estado, ESTADOS_SOLICITUD, true)) {
        throw new InvalidArgumentException('El estado seleccionado no es válido.');
    }
    return $estado;
}

function filtros_solicitudes(array $entrada): array
{
    $busqueda = $entrada['buscar'] ?? '';
    if (!is_string($busqueda) || !mb_check_encoding($busqueda, 'UTF-8') || mb_strlen($busqueda, 'UTF-8') > 120
        || preg_match('/[\x00-\x1F\x7F]/u', $busqueda)) {
        throw new InvalidArgumentException('La búsqueda admite hasta 120 caracteres.');
    }
    $estado = $entrada['estado'] ?? '';
    if ($estado !== '') { $estado = validar_estado_solicitud($estado); }
    return ['buscar' => trim($busqueda), 'estado' => $estado, 'pagina' => validar_id_solicitud($entrada['pagina'] ?? 1)];
}

function url_solicitudes(array $parametros = []): string
{
    return 'solicitudes.php' . ($parametros ? '?' . http_build_query($parametros, '', '&', PHP_QUERY_RFC3986) : '');
}

function listar_solicitudes(PDO $db, array $entrada): array
{
    $filtros = filtros_solicitudes($entrada);
    $where = [];
    $parametros = [];
    if ($filtros['buscar'] !== '') {
        // Los comodines se buscan literalmente. El número se compara de forma exacta.
        $parametros['nombre'] = '%' . strtr($filtros['buscar'], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $busqueda = "s.cliente_nombre LIKE :nombre ESCAPE '!'";
        if (preg_match('/\A[1-9][0-9]{0,9}\z/', $filtros['buscar']) && (int) $filtros['buscar'] <= 2147483647) {
            $busqueda .= ' OR s.id_solicitud = :numero';
            $parametros['numero'] = (int) $filtros['buscar'];
        }
        $where[] = '(' . $busqueda . ')';
    }
    if ($filtros['estado'] !== '') { $where[] = 's.estado = :estado'; $parametros['estado'] = $filtros['estado']; }
    $condicion = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $consulta = $db->prepare('SELECT COUNT(*) FROM solicitudes s' . $condicion);
    $consulta->execute($parametros);
    $total = (int) $consulta->fetchColumn();
    $paginas = max(1, (int) ceil($total / 20));
    $pagina = min($filtros['pagina'], $paginas);
    $consulta = $db->prepare('SELECT s.id_solicitud, s.fecha_creacion, s.cliente_nombre, s.estado,
        (SELECT COUNT(*) FROM detalle_solicitud d WHERE d.id_solicitud = s.id_solicitud) AS productos
        FROM solicitudes s' . $condicion . ' ORDER BY s.fecha_creacion DESC, s.id_solicitud DESC LIMIT :limite OFFSET :desplazamiento');
    foreach ($parametros as $nombre => $valor) { $consulta->bindValue(':' . $nombre, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR); }
    $consulta->bindValue(':limite', 20, PDO::PARAM_INT);
    $consulta->bindValue(':desplazamiento', ($pagina - 1) * 20, PDO::PARAM_INT);
    $consulta->execute();
    return ['solicitudes' => $consulta->fetchAll(), 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
}

function buscar_solicitud(PDO $db, mixed $id): ?array
{
    $consulta = $db->prepare('SELECT id_solicitud, fecha_creacion, cliente_nombre, cliente_whatsapp,
        cliente_correo, observaciones, estado FROM solicitudes WHERE id_solicitud = :id');
    $consulta->execute(['id' => validar_id_solicitud($id)]);
    return $consulta->fetch() ?: null;
}

function detalle_solicitud_panel(PDO $db, mixed $id): array
{
    // El nombre y la cantidad son los registrados originalmente, incluso si el producto se oculta o cambia.
    $consulta = $db->prepare('SELECT d.id_producto, d.producto_nombre, d.cantidad, p.codigo
        FROM detalle_solicitud d LEFT JOIN productos p ON p.id_producto = d.id_producto
        WHERE d.id_solicitud = :id ORDER BY d.id_detalle');
    $consulta->execute(['id' => validar_id_solicitud($id)]);
    return $consulta->fetchAll();
}

function enlace_whatsapp_solicitud(mixed $telefono): ?string
{
    if (!is_string($telefono) || strlen($telefono) > 25) { return null; }
    $telefono = trim($telefono);
    if (!preg_match('/\A\+?[0-9][0-9 ()-]*\z/', $telefono)) { return null; }
    $numero = preg_replace('/\D/', '', $telefono);
    // Los números locales sin indicativo se interpretan como Costa Rica.
    if (strlen($numero) === 8 && !str_starts_with($telefono, '+')) { $numero = '506' . $numero; }
    if (!preg_match('/\A[1-9][0-9]{7,14}\z/', $numero)) { return null; }
    return 'https://wa.me/' . $numero;
}

function clase_estado_solicitud(string $estado): string
{
    return match ($estado) {
        'Pendiente' => 'text-bg-warning', 'En proceso' => 'text-bg-primary',
        'Atendida' => 'text-bg-success', default => 'text-bg-secondary',
    };
}

function fecha_solicitud_panel(string $fecha): string
{
    $valor = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $fecha);
    return $valor ? $valor->format('d/m/Y H:i') : $fecha;
}

function preparar_estado_solicitud(PDO $db, mixed $id, mixed $nuevo, array &$confirmaciones): string
{
    $id = validar_id_solicitud($id);
    $nuevo = validar_estado_solicitud($nuevo);
    $solicitud = buscar_solicitud($db, $id);
    if ($solicitud === null) { throw new InvalidArgumentException('La solicitud ya no está disponible.'); }
    if ($solicitud['estado'] === $nuevo) { throw new InvalidArgumentException('La solicitud ya tiene ese estado. No se realizaron cambios.'); }
    foreach ($confirmaciones as $clave => $valor) {
        if ($valor['fecha'] < time() - 300) { unset($confirmaciones[$clave]); }
    }
    if (count($confirmaciones) >= 20) { array_shift($confirmaciones); }
    $token = bin2hex(random_bytes(32));
    $confirmaciones[$token] = ['id' => $id, 'anterior' => $solicitud['estado'], 'nuevo' => $nuevo, 'fecha' => time()];
    return $token;
}

function confirmar_estado_solicitud(PDO $db, mixed $id, mixed $token, array &$confirmaciones): string
{
    $id = validar_id_solicitud($id);
    $confirmacion = is_string($token) ? ($confirmaciones[$token] ?? null) : null;
    if (!is_array($confirmacion) || $confirmacion['id'] !== $id || $confirmacion['fecha'] < time() - 300) {
        throw new InvalidArgumentException('La confirmación venció o no es válida. Revisa el estado y vuelve a intentarlo.');
    }
    unset($confirmaciones[$token]);
    $anterior = validar_estado_solicitud($confirmacion['anterior']);
    $nuevo = validar_estado_solicitud($confirmacion['nuevo']);
    // Una única escritura atómica; no modifica cliente, fecha, asignación ni detalle.
    $consulta = $db->prepare('UPDATE solicitudes SET estado = :nuevo WHERE id_solicitud = :id AND estado = :anterior');
    $consulta->execute(['nuevo' => $nuevo, 'id' => $id, 'anterior' => $anterior]);
    if ($consulta->rowCount() !== 1) {
        throw new DomainException('El estado cambió desde que lo revisaste o la solicitud ya no está disponible. Revisa la información actual antes de continuar.');
    }
    return $nuevo;
}

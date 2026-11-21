<?php

declare(strict_types=1);

class PermisoUsuariosDenegado extends RuntimeException {}
class CredencialAdministradorInvalida extends InvalidArgumentException {}

function validar_id_usuario(mixed $valor): int
{
    if ((!is_int($valor) && !is_string($valor)) || !preg_match('/\A[1-9][0-9]*\z/', (string) $valor)) {
        throw new InvalidArgumentException('La cuenta seleccionada no es válida.');
    }
    $id = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    if ($id === false) { throw new InvalidArgumentException('La cuenta seleccionada no es válida.'); }
    return $id;
}

function validar_datos_usuario(array $entrada): array
{
    $nombre = $entrada['nombre'] ?? null;
    $correo = $entrada['correo'] ?? null;
    $rol = $entrada['rol'] ?? null;
    if (!is_string($nombre) || !mb_check_encoding($nombre, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/u', $nombre)
        || mb_strlen(trim($nombre), 'UTF-8') < 2 || mb_strlen($nombre, 'UTF-8') > 100) {
        throw new InvalidArgumentException('El nombre debe tener entre 2 y 100 caracteres válidos.');
    }
    if (!is_string($correo) || strlen($correo) > 150 || !filter_var(trim($correo), FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Escribe un correo válido de hasta 150 caracteres.');
    }
    if (!in_array($rol, ['administrador', 'empleado'], true)) { throw new InvalidArgumentException('El rol seleccionado no es válido.'); }
    return ['nombre' => trim($nombre), 'correo' => strtolower(trim($correo)), 'rol' => $rol];
}

function hash_nueva_clave_usuario(mixed $clave, mixed $repetida): string
{
    // PASSWORD_DEFAULT usa bcrypt actualmente: se rechazan entradas que exceden sus 72 bytes.
    if (!is_string($clave) || !mb_check_encoding($clave, 'UTF-8') || mb_strlen($clave, 'UTF-8') < 12
        || strlen($clave) > 72 || preg_match('/[\x00-\x1F\x7F]/u', $clave)
        || !preg_match('/\p{Lu}/u', $clave) || !preg_match('/\p{Ll}/u', $clave) || !preg_match('/[0-9]/', $clave)) {
        throw new InvalidArgumentException('La contraseña debe tener al menos 12 caracteres, mayúsculas, minúsculas y números; máximo 72 bytes (las letras con tilde ocupan más de uno).');
    }
    if (!is_string($repetida) || !hash_equals($clave, $repetida)) { throw new InvalidArgumentException('Las contraseñas nuevas no coinciden.'); }
    return password_hash($clave, PASSWORD_DEFAULT);
}

function listar_usuarios(PDO $db): array
{
    return $db->query('SELECT id_usuario, nombre, correo, rol, activo FROM usuarios ORDER BY nombre, id_usuario')->fetchAll();
}

function buscar_usuario_panel(PDO $db, int $id, bool $con_clave = false): ?array
{
    $q = $db->prepare('SELECT id_usuario, nombre, correo, rol, activo' . ($con_clave ? ', clave_hash' : '') . ' FROM usuarios WHERE id_usuario = :id');
    $q->execute(['id' => $id]);
    return $q->fetch() ?: null;
}

function comprobar_administrador_usuarios(PDO $db, int $actor): array
{
    $cuenta = buscar_usuario_panel($db, $actor, true);
    if (!$cuenta || $cuenta['rol'] !== 'administrador' || (int) $cuenta['activo'] !== 1) {
        throw new PermisoUsuariosDenegado('Solo un administrador activo puede gestionar cuentas.');
    }
    return $cuenta;
}

function comprobar_correo_usuario(PDO $db, string $correo, ?int $id): void
{
    $q = $db->prepare('SELECT id_usuario FROM usuarios WHERE correo = :correo');
    $q->execute(['correo' => $correo]);
    $existente = $q->fetch();
    if ($existente && (int) $existente['id_usuario'] !== $id) { throw new InvalidArgumentException('Ya existe una cuenta con ese correo electrónico.'); }
}

function huella_cuenta_usuario(array $cuenta): string
{
    return hash('sha256', json_encode($cuenta, JSON_THROW_ON_ERROR));
}

function preparar_cambio_usuario(PDO $db, int $actor, array $entrada, array &$pendientes): string
{
    comprobar_administrador_usuarios($db, $actor);
    $accion = $entrada['operacion'] ?? null;
    if (!in_array($accion, ['crear', 'editar', 'estado', 'clave'], true)) { throw new InvalidArgumentException('La operación no es válida.'); }
    $id = $accion === 'crear' ? null : validar_id_usuario($entrada['id_usuario'] ?? null);
    $cuenta = $id === null ? null : buscar_usuario_panel($db, $id, true);
    if ($id !== null && !$cuenta) { throw new InvalidArgumentException('La cuenta ya no está disponible.'); }
    $datos = [];
    if ($accion === 'crear' || $accion === 'editar') {
        $datos = validar_datos_usuario($entrada);
        comprobar_correo_usuario($db, $datos['correo'], $id);
    }
    if ($accion === 'crear' || $accion === 'clave') {
        $datos['clave_hash'] = hash_nueva_clave_usuario($entrada['clave_nueva'] ?? null, $entrada['clave_repetida'] ?? null);
    }
    if ($accion === 'estado') {
        if (!in_array($entrada['activo'] ?? null, ['0', '1'], true)) { throw new InvalidArgumentException('El estado de la cuenta no es válido.'); }
        $datos['activo'] = (int) $entrada['activo'];
        if ($id === $actor && $datos['activo'] === 0) { throw new InvalidArgumentException('No puedes desactivar tu propia cuenta.'); }
        if ((int) $cuenta['activo'] === $datos['activo']) { throw new InvalidArgumentException('La cuenta ya tiene ese estado.'); }
    }
    foreach ($pendientes as $token => $cambio) { if ($cambio['fecha'] < time() - 600) { unset($pendientes[$token]); } }
    if (count($pendientes) >= 10) { array_shift($pendientes); }
    $token = bin2hex(random_bytes(32));
    $pendientes[$token] = ['actor' => $actor, 'id' => $id, 'operacion' => $accion, 'datos' => $datos,
        'nombre_anterior' => $cuenta['nombre'] ?? '', 'correo_anterior' => $cuenta['correo'] ?? '',
        'huella' => $cuenta === null ? null : huella_cuenta_usuario($cuenta), 'fecha' => time()];
    return $token;
}

function obtener_cambio_usuario(array $pendientes, mixed $token, int $actor): array
{
    $cambio = is_string($token) ? ($pendientes[$token] ?? null) : null;
    if (!is_array($cambio) || $cambio['actor'] !== $actor || $cambio['fecha'] < time() - 600) {
        throw new InvalidArgumentException('La confirmación venció o no es válida. Revisa la cuenta y comienza de nuevo.');
    }
    return $cambio;
}

function confirmar_cambio_usuario(PDO $db, int $actor, mixed $token, mixed $clave_actual, array &$pendientes): array
{
    $cambio = obtener_cambio_usuario($pendientes, $token, $actor);
    $db->beginTransaction();
    try {
        // Todas las escrituras de cuentas toman el mismo bloqueo en orden de PK.
        // Serializa cambios concurrentes para conservar al menos un administrador activo.
        $cuentas = $db->query('SELECT id_usuario, rol, activo FROM usuarios ORDER BY id_usuario FOR UPDATE')->fetchAll();
        $administrador = comprobar_administrador_usuarios($db, $actor);
        if (!is_string($clave_actual) || $clave_actual === '' || strlen($clave_actual) > 4096
            || !password_verify($clave_actual, $administrador['clave_hash'])) {
            throw new CredencialAdministradorInvalida('La contraseña del administrador no es correcta. No se guardaron cambios.');
        }
        $id = $cambio['id']; $datos = $cambio['datos']; $accion = $cambio['operacion'];
        $cuenta = $id === null ? null : buscar_usuario_panel($db, $id, true);
        if ($id !== null && (!$cuenta || !hash_equals($cambio['huella'], huella_cuenta_usuario($cuenta)))) {
            throw new DomainException('La cuenta cambió desde que la revisaste. Vuelve al listado y revisa los datos actuales.');
        }
        $activa = $accion === 'estado' ? $datos['activo'] : (int) ($cuenta['activo'] ?? 1);
        $rol = $accion === 'editar' ? $datos['rol'] : ($cuenta['rol'] ?? $datos['rol'] ?? 'empleado');
        if ($id === $actor && !$activa) { throw new InvalidArgumentException('No puedes desactivar tu propia cuenta.'); }
        if ($cuenta && $cuenta['rol'] === 'administrador' && (int) $cuenta['activo'] === 1 && (!$activa || $rol !== 'administrador')) {
            $otros = array_filter($cuentas, fn($fila) => (int) $fila['id_usuario'] !== $id && $fila['rol'] === 'administrador' && (int) $fila['activo'] === 1);
            if (!$otros) { throw new InvalidArgumentException('Debe permanecer al menos un administrador activo. No se guardaron cambios.'); }
        }
        if ($accion === 'crear' || $accion === 'editar') { comprobar_correo_usuario($db, $datos['correo'], $id); }
        if ($accion === 'crear') {
            $q = $db->prepare('INSERT INTO usuarios (nombre, correo, rol, clave_hash, activo) VALUES (:nombre, :correo, :rol, :clave_hash, 1)');
            $q->execute($datos); $id = (int) $db->lastInsertId();
        } elseif ($accion === 'editar') {
            $q = $db->prepare('UPDATE usuarios SET nombre = :nombre, correo = :correo, rol = :rol WHERE id_usuario = :id');
            $q->execute($datos + ['id' => $id]);
        } elseif ($accion === 'estado') {
            $q = $db->prepare('UPDATE usuarios SET activo = :activo WHERE id_usuario = :id');
            $q->execute(['activo' => $datos['activo'], 'id' => $id]);
        } elseif ($accion === 'clave') {
            $q = $db->prepare('UPDATE usuarios SET clave_hash = :clave_hash WHERE id_usuario = :id');
            $q->execute(['clave_hash' => $datos['clave_hash'], 'id' => $id]);
        } else { throw new InvalidArgumentException('La operación no es válida.'); }
        $db->commit();
        unset($pendientes[$token]);
        return ['id' => $id, 'operacion' => $accion, 'rol_propio' => $id === $actor ? $rol : null,
            'credencial_propia' => $id === $actor && $accion === 'clave' ? hash('sha256', $datos['clave_hash']) : null];
    } catch (Throwable $error) {
        if ($db->inTransaction()) { $db->rollBack(); }
        if (!$error instanceof CredencialAdministradorInvalida) { unset($pendientes[$token]); }
        if ($error instanceof PDOException && ($error->errorInfo[1] ?? null) === 1062) {
            throw new InvalidArgumentException('Ya existe una cuenta con ese correo electrónico.');
        }
        throw $error;
    }
}

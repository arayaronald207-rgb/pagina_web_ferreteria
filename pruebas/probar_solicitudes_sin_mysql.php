<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/Servicios/solicitudes.php';
require_once __DIR__ . '/../app/Servicios/panel_administrativo.php';

// Sesión y PDO en memoria: no conecta con MySQL ni crea cuentas o servidores.
session_set_save_handler(new class implements SessionHandlerInterface {
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false { return ''; }
    public function write(string $id, string $data): bool { return true; }
    public function destroy(string $id): bool { return true; }
    public function gc(int $max_lifetime): int|false { return 0; }
}, true);
$_SERVER['SCRIPT_NAME'] = '/pagina_web_ferreteria/public/solicitudes.php';
iniciar_sesion_segura();

class SentenciaSolicitudesPrueba extends PDOStatement
{
    public array $parametros = [], $tipos = [];
    private int $afectadas = 0;
    public function __construct(private ConexionSolicitudesPrueba $db, public string $sql) {}
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    { $this->parametros[ltrim((string) $param, ':')] = $value; $this->tipos[$param] = $type; return true; }
    public function execute(?array $params = null): bool
    {
        if ($params !== null) { $this->parametros = $params; }
        if (str_starts_with($this->sql, 'UPDATE')) {
            if ($this->sql !== 'UPDATE solicitudes SET estado = :nuevo WHERE id_solicitud = :id AND estado = :anterior') {
                throw new RuntimeException('Se intentó modificar campos originales');
            }
            if ($this->db->fallar) { throw new PDOException('Fallo ficticio'); }
            $fila = &$this->db->solicitudes[$this->parametros['id']];
            if ($fila !== null && $fila['estado'] === $this->parametros['anterior']) {
                $fila['estado'] = $this->parametros['nuevo']; $this->afectadas = 1;
            }
        } elseif (!str_starts_with($this->sql, 'SELECT')) { throw new RuntimeException('Escritura inesperada'); }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    { return $this->db->solicitudes[$this->parametros['id']] ?? false; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    { return str_contains($this->sql, 'FROM detalle_solicitud d LEFT JOIN') ? $this->db->detalle : array_values($this->db->solicitudes); }
    public function fetchColumn(int $column = 0): mixed { return $this->db->total; }
    public function rowCount(): int { return $this->afectadas; }
}
class ConexionSolicitudesPrueba extends PDO
{
    public array $solicitudes, $consultas = [];
    public array $detalle = [['id_producto' => 8, 'producto_nombre' => '<Producto original> & prueba', 'codigo' => '008', 'cantidad' => 3]];
    public int $total = 41;
    public bool $fallar = false;
    public function __construct()
    {
        $this->solicitudes = [7 => ['id_solicitud' => 7, 'fecha_creacion' => '2026-11-17 10:15:00',
            'cliente_nombre' => 'Cliente <script>window.inyectado=1</script>', 'cliente_whatsapp' => '8888-8888',
            'cliente_correo' => 'ficticio@example.test', 'observaciones' => "Primera línea\n<script>window.inyectado=1</script>",
            'estado' => 'Pendiente', 'id_usuario_asignado' => null, 'productos' => 1]];
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    { $sentencia = new SentenciaSolicitudesPrueba($this, $query); $this->consultas[] = $sentencia; return $sentencia; }
}

$modo = $argv[1] ?? 'pruebas';
if ($modo === 'acceso') {
    $_SESSION['usuario'] = ['id_usuario' => 7, 'nombre' => 'Ficticio', 'correo' => 'ficticio@example.test', 'rol' => $argv[2] ?? 'visitante'];
    $_SESSION['ultima_actividad'] = ($argv[3] ?? '') === 'vencida' ? time() - 1801 : time();
    exigir_autenticacion(['administrador', 'empleado']);
    echo 'ACCESO AUTORIZADO'; exit;
}

if ($modo === 'vista') {
    $tipo = $argv[2] ?? 'listado';
    $db = new ConexionSolicitudesPrueba();
    $usuario = ['nombre' => 'Personal de prueba', 'rol' => $argv[3] ?? 'administrador'];
    $titulo_pagina = 'Solicitudes'; $seccion_actual = 'solicitudes';
    $filtros = ['buscar' => '', 'estado' => '', 'pagina' => 1];
    $error_formulario = ''; $mensaje = ''; $confirmar = null; $token_confirmacion = null;
    $solicitud = $db->solicitudes[7]; $productos = $db->detalle;
    $listado = ['solicitudes' => [$solicitud], 'total' => 41, 'paginas' => 3, 'pagina' => 1];
    $url_detalle = 'solicitud.php?id=7';
    if ($tipo === 'confirmacion') { $confirmar = ['anterior' => 'Pendiente', 'nuevo' => 'En proceso']; $token_confirmacion = 'ficticio'; }
    if ($tipo === 'resultado') { $mensaje = 'Solicitud n.º 7: estado actualizado a En proceso.'; $solicitud['estado'] = 'En proceso'; }
    if ($tipo === 'vacio') { $listado = ['solicitudes' => [], 'total' => 0, 'paginas' => 1, 'pagina' => 1]; }
    if ($tipo === 'error') { $solicitud = null; $error_formulario = 'La solicitud no existe o ya no está disponible.'; }
    require __DIR__ . '/../app/Vistas/plantillas/cabecera_administrativa.php';
    require __DIR__ . '/../app/Vistas/solicitudes/' . (in_array($tipo, ['listado', 'vacio'], true) ? 'listado.php' : 'detalle.php');
    require __DIR__ . '/../app/Vistas/plantillas/pie_administrativo.php';
    exit;
}

$casos = 0;
function verificar_solicitudes(bool $valor, string $caso): void
{
    global $casos;
    if (!$valor) { throw new RuntimeException('Falló: ' . $caso); }
    $casos++;
}
function rechazar_solicitudes(callable $accion, string $clase = InvalidArgumentException::class): void
{
    try { $accion(); } catch (Throwable $error) {
        if (!$error instanceof $clase) { throw $error; }
        verificar_solicitudes(true, 'Rechazo seguro'); return;
    }
    throw new RuntimeException('Se aceptó una operación inválida');
}
foreach ([0, -1, '', '1 OR 1=1', '1.2', '01', [], true, null, 2147483648] as $valor) { rechazar_solicitudes(fn() => validar_id_solicitud($valor)); }
foreach (['', 'pendiente', 'Eliminada', [], null, 0] as $valor) { rechazar_solicitudes(fn() => validar_estado_solicitud($valor)); }
foreach ([['buscar' => []], ['buscar' => str_repeat('á', 121)], ['buscar' => "\xFF"], ['buscar' => "A\0B"], ['estado' => []], ['pagina' => '0']] as $filtro) { rechazar_solicitudes(fn() => filtros_solicitudes($filtro)); }
verificar_solicitudes(validar_id_solicitud('2147483647') === 2147483647, 'ID máximo');
verificar_solicitudes(filtros_solicitudes(['buscar' => '  María  '])['buscar'] === 'María', 'Normalización de búsqueda');
foreach (['8888-8888' => '50688888888', '+506 8888 8888' => '50688888888', '50688888888' => '50688888888', '+1 (202) 555-0123' => '12025550123'] as $telefono => $numero) {
    verificar_solicitudes(enlace_whatsapp_solicitud((string) $telefono) === 'https://wa.me/' . $numero, 'WhatsApp normalizado');
}
foreach (['javascript:alert(1)', '123', '+00000000', '001234567890', str_repeat('9', 16), [], null, '+50688888888?text=hola'] as $telefono) {
    verificar_solicitudes(enlace_whatsapp_solicitud($telefono) === null, 'WhatsApp inválido sin enlace');
}
$db = new ConexionSolicitudesPrueba();
$listado = listar_solicitudes($db, ['buscar' => '7', 'estado' => 'Pendiente', 'pagina' => 999]);
verificar_solicitudes($listado['pagina'] === 3 && $listado['paginas'] === 3 && $listado['total'] === 41, 'Paginación y última página');
verificar_solicitudes($db->consultas[0]->parametros === ['nombre' => '%7%', 'numero' => 7, 'estado' => 'Pendiente'], 'Búsqueda parametrizada combinada');
verificar_solicitudes($db->consultas[1]->parametros['desplazamiento'] === 40 && $db->consultas[1]->tipos[':limite'] === PDO::PARAM_INT, 'Límites enteros');
verificar_solicitudes(str_contains($db->consultas[0]->sql, '(s.cliente_nombre LIKE :nombre') && str_contains($db->consultas[0]->sql, ') AND s.estado = :estado'), 'Precedencia de filtros');
verificar_solicitudes(str_contains($db->consultas[1]->sql, 'COUNT(*) FROM detalle_solicitud'), 'Cuenta líneas, no unidades');
$db = new ConexionSolicitudesPrueba();
listar_solicitudes($db, ['buscar' => "%_!'; DROP TABLE solicitudes;--"]);
verificar_solicitudes(!str_contains($db->consultas[0]->sql, 'DROP TABLE'), 'Búsqueda sin interpolación');
verificar_solicitudes(str_starts_with($db->consultas[0]->parametros['nombre'], '%!%!_!!'), 'Comodines literales');
$db->total = 0;
verificar_solicitudes(listar_solicitudes($db, [])['paginas'] === 1, 'Paginación vacía');
verificar_solicitudes(buscar_solicitud($db, 99) === null, 'Solicitud inexistente');
verificar_solicitudes(detalle_solicitud_panel($db, 7)[0]['producto_nombre'] === '<Producto original> & prueba', 'Nombre histórico');
verificar_solicitudes(!str_contains(end($db->consultas)->sql, 'visible'), 'Detalle conserva productos ocultos');

foreach (ESTADOS_SOLICITUD as $anterior) {
    foreach (ESTADOS_SOLICITUD as $nuevo) {
        $db = new ConexionSolicitudesPrueba(); $db->solicitudes[7]['estado'] = $anterior;
        $original = $db->solicitudes[7]; $confirmaciones = [];
        if ($anterior === $nuevo) {
            rechazar_solicitudes(function () use ($db, $nuevo, &$confirmaciones) { preparar_estado_solicitud($db, 7, $nuevo, $confirmaciones); });
            continue;
        }
        $token = preparar_estado_solicitud($db, 7, $nuevo, $confirmaciones);
        verificar_solicitudes($db->solicitudes[7] === $original, 'Revisión no escribe');
        verificar_solicitudes(confirmar_estado_solicitud($db, 7, $token, $confirmaciones) === $nuevo, 'Transición permitida');
        verificar_solicitudes($db->solicitudes[7] === array_replace($original, ['estado' => $nuevo]), 'Solo cambia estado');
        verificar_solicitudes($db->detalle[0]['cantidad'] === 3, 'Detalle intacto');
        rechazar_solicitudes(function () use ($db, $token, &$confirmaciones) { confirmar_estado_solicitud($db, 7, $token, $confirmaciones); });
    }
}
$db = new ConexionSolicitudesPrueba(); $confirmaciones = [];
$token = preparar_estado_solicitud($db, 7, 'En proceso', $confirmaciones);
foreach ([[], 'incorrecto'] as $invalido) { rechazar_solicitudes(function () use ($db, $invalido, &$confirmaciones) { confirmar_estado_solicitud($db, 7, $invalido, $confirmaciones); }); }
rechazar_solicitudes(function () use ($db, $token, &$confirmaciones) { confirmar_estado_solicitud($db, 8, $token, $confirmaciones); });
$confirmaciones[$token]['fecha'] = time() - 301;
rechazar_solicitudes(function () use ($db, $token, &$confirmaciones) { confirmar_estado_solicitud($db, 7, $token, $confirmaciones); });
$token = preparar_estado_solicitud($db, 7, 'En proceso', $confirmaciones);
$db->solicitudes[7]['estado'] = 'Cancelada';
rechazar_solicitudes(function () use ($db, $token, &$confirmaciones) { confirmar_estado_solicitud($db, 7, $token, $confirmaciones); }, DomainException::class);
verificar_solicitudes($db->solicitudes[7]['estado'] === 'Cancelada', 'No sobrescribe cambios concurrentes');
$token = preparar_estado_solicitud($db, 7, 'Atendida', $confirmaciones); $db->fallar = true;
rechazar_solicitudes(function () use ($db, $token, &$confirmaciones) { confirmar_estado_solicitud($db, 7, $token, $confirmaciones); }, PDOException::class);
verificar_solicitudes($db->solicitudes[7]['estado'] === 'Cancelada' && !isset($confirmaciones[$token]), 'Fallo conserva datos y exige nueva revisión');
for ($i = 0; $i < 25; $i++) { preparar_estado_solicitud($db, 7, 'Atendida', $confirmaciones); }
verificar_solicitudes(count($confirmaciones) === 20, 'Límite de confirmaciones');
$csrf = obtener_token_csrf();
verificar_solicitudes(validar_token_csrf($csrf), 'CSRF válido');
foreach ([null, [], 'incorrecto'] as $token) { verificar_solicitudes(!validar_token_csrf($token), 'CSRF inválido'); }
verificar_solicitudes(str_contains(escapar_panel('<script>"&'), '&lt;script&gt;'), 'Escape HTML');
echo "OK: $casos comprobaciones de solicitudes, estados, concurrencia, validaciones, WhatsApp y CSRF. Sin MySQL.\n";

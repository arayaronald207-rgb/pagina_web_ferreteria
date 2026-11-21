<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/Servicios/registro_solicitud.php';

// Dobles en memoria. No carga conexion.php ni abre MySQL.
class ConsultaRegistroPrueba extends PDOStatement
{
    public function __construct(private ConexionRegistroPrueba $db, private string $sql) {}
    public function execute(?array $params = null): bool
    {
        $this->db->ejecuciones[] = [$this->sql, $params];
        if (str_starts_with($this->sql, 'INSERT INTO solicitudes')) {
            $this->db->cabeceras[] = $params;
        } elseif (str_starts_with($this->sql, 'INSERT INTO detalle_solicitud')) {
            if ($this->db->fallarDetalle && count($this->db->detalles) === 1) { throw new PDOException('Fallo ficticio'); }
            $this->db->detalles[] = $params;
        }
        return true;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->db->publicados; }
}
class ConexionRegistroPrueba extends PDO
{
    public array $publicados = [
        ['id_producto' => 7, 'nombre' => 'Nombre original', 'codigo' => '007', 'imagen' => null],
        ['id_producto' => 9, 'nombre' => 'Otro producto', 'codigo' => '009', 'imagen' => null],
    ];
    public array $cabeceras = [], $detalles = [], $ejecuciones = [];
    public bool $activa = false, $fallarDetalle = false, $fallarCommit = false;
    public int $commits = 0, $reversiones = 0, $inicios = 0;
    public function __construct() {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($query !== 'SELECT visible FROM categorias LIMIT 0') { throw new RuntimeException('Consulta inesperada'); }
        return new ConsultaRegistroPrueba($this, $query);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false { return new ConsultaRegistroPrueba($this, $query); }
    public function beginTransaction(): bool { $this->inicios++; return $this->activa = true; }
    public function inTransaction(): bool { return $this->activa; }
    public function commit(): bool
    {
        if ($this->fallarCommit) { throw new PDOException('Commit ficticio fallido'); }
        $this->commits++; $this->activa = false; return true;
    }
    public function rollBack(): bool { $this->reversiones++; $this->cabeceras = $this->detalles = []; $this->activa = false; return true; }
    public function lastInsertId(?string $name = null): string|false { return '314'; }
}
$casos = 0;
function comprobar_registro(bool $valor, string $caso): void
{
    global $casos;
    if (!$valor) { throw new RuntimeException($caso); }
    $casos++;
}
function rechazar_registro(callable $operacion): void
{
    try { $operacion(); } catch (InvalidArgumentException $error) { comprobar_registro(true, 'Validación'); return; }
    throw new RuntimeException('Se aceptó una entrada inválida');
}
$cliente = ['cliente_nombre' => 'María Prueba', 'cliente_whatsapp' => '+506 8888-8888', 'cliente_correo' => 'ficticio@example.test', 'observaciones' => "Primera línea\nSegunda línea"];
$lista = [['id_producto' => 7, 'cantidad' => 2], ['id_producto' => 9, 'cantidad' => 3], ['id_producto' => 7, 'cantidad' => 1]];
comprobar_registro(validar_cliente_solicitud($cliente)['cliente_whatsapp'] === '+50688888888', 'Normalización teléfono');
foreach ([
    ['cliente_nombre', ''], ['cliente_nombre', 'María'], ['cliente_nombre', []], ['cliente_nombre', str_repeat('A', 121)],
    ['cliente_nombre', "Nombre\0Apellido"], ['cliente_nombre', "\xFF"], ['cliente_whatsapp', '123'], ['cliente_whatsapp', '1234567890123456'],
    ['cliente_whatsapp', 'abcdefgh'], ['cliente_whatsapp', '1 OR 1=1'], ['cliente_whatsapp', []], ['cliente_correo', ''],
    ['cliente_correo', 'no-es-correo'], ['cliente_correo', []], ['cliente_correo', str_repeat('a', 145) . '@test.cr'],
    ['observaciones', []], ['observaciones', str_repeat('á', 2001)], ['observaciones', "Observación\0"]
] as [$campo, $valor]) { rechazar_registro(fn() => validar_cliente_solicitud(array_replace($cliente, [$campo => $valor]))); }
comprobar_registro(validar_cliente_solicitud(array_replace($cliente, ['observaciones' => str_repeat('á', 2000)]))['observaciones'] === str_repeat('á', 2000), 'Límite Unicode');
$db = new ConexionRegistroPrueba();
$id = registrar_solicitud($db, array_replace($cliente, ['observaciones' => '']), $lista);
comprobar_registro($id === 314 && $db->commits === 1 && !$db->activa, 'ID verdadero después de commit');
comprobar_registro(count($db->cabeceras) === 1 && count($db->detalles) === 2, 'Una cabecera y dos líneas sin duplicados');
comprobar_registro($db->detalles[0] === [314, 7, 'Nombre original', 3], 'Nombre desde SQL y cantidad agrupada');
comprobar_registro($db->cabeceras[0]['observaciones'] === null, 'Observaciones opcionales');
comprobar_registro(str_contains($db->ejecuciones[0][0], 'p.visible = 1') && str_contains($db->ejecuciones[0][0], 'c.visible = 1') && str_contains($db->ejecuciones[0][0], 'FOR UPDATE'), 'Visibilidades bloqueadas');
comprobar_registro($db->ejecuciones[0][1] === [7, 9], 'IDs parametrizados');
comprobar_registro(str_contains($db->ejecuciones[1][0], "'Pendiente', CURRENT_TIMESTAMP"), 'Estado y fecha en SQL');
foreach ([[], [['id_producto' => 7, 'cantidad' => 0]], [['id_producto' => 7, 'cantidad' => 1, 'nombre' => 'Falsificado']]] as $invalida) {
    $db = new ConexionRegistroPrueba();
    rechazar_registro(fn() => registrar_solicitud($db, $cliente, $invalida));
    comprobar_registro($db->inicios === 0, 'No inicia transacción con datos inválidos');
}
$db = new ConexionRegistroPrueba();
$db->publicados = [$db->publicados[0]]; // Segundo producto oculto, eliminado o categoría oculta.
rechazar_registro(fn() => registrar_solicitud($db, $cliente, $lista));
comprobar_registro(!$db->cabeceras && !$db->detalles && $db->reversiones === 1, 'Rechazo total de producto no público');
foreach (['fallarDetalle', 'fallarCommit'] as $fallo) {
    $db = new ConexionRegistroPrueba(); $db->$fallo = true;
    $borrador = ['lista' => $lista, 'id_solicitud' => null];
    try { confirmar_borrador_solicitud($db, $borrador, $cliente); throw new RuntimeException('No falló'); }
    catch (PDOException $error) {}
    comprobar_registro(!$db->cabeceras && !$db->detalles && $db->reversiones === 1, 'Rollback completo');
    comprobar_registro($borrador['id_solicitud'] === null && $borrador['lista'] === $lista, 'Conserva borrador sin confirmar');
    $db->$fallo = false;
    comprobar_registro(confirmar_borrador_solicitud($db, $borrador, $cliente) === 314, 'Permite reintento tras fallo');
    $cantidad = count($db->ejecuciones);
    comprobar_registro(confirmar_borrador_solicitud($db, $borrador, []) === 314 && count($db->ejecuciones) === $cantidad, 'Reenvío devuelve recibo sin SQL');
}
$intentos = [];
$token = crear_intento_solicitud($intentos);
comprobar_registro(intento_solicitud_valido($intentos, $token), 'Token válido');
comprobar_registro(!intento_solicitud_valido($intentos, []) && !intento_solicitud_valido($intentos, str_repeat('0', 64)), 'Token ajeno o inválido');
$db = new ConexionRegistroPrueba();
preparar_borrador_solicitud($db, $intentos[$token], $lista);
comprobar_registro(!$db->cabeceras && !$db->inicios, 'Preparación de solo lectura');
$normalizada = normalizar_lista_solicitud($lista);
comprobar_registro($intentos[$token]['lista'] === $normalizada, 'Borrador normalizado');
rechazar_registro(function () use ($db, &$intentos, $token) { preparar_borrador_solicitud($db, $intentos[$token], [['id_producto' => 7, 'cantidad' => 1]]); });
comprobar_registro($intentos[$token]['lista'] === $normalizada, 'Borrador inmutable');
$intentos[$token]['creado'] = time() - 7201;
comprobar_registro(!intento_solicitud_valido($intentos, $token), 'Vencimiento');
for ($i = 0; $i < 35; $i++) { crear_intento_solicitud($intentos); }
comprobar_registro(count($intentos) === 30 && !isset($intentos[$token]), 'Límite de borradores y limpieza');
echo "OK: $casos comprobaciones de registro, validación, transacción, rollback y reenvío; sin MySQL.\n";

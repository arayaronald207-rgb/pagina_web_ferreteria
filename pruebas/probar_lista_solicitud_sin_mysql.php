<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/Servicios/lista_solicitud.php';

// Dobles de PDO en memoria: no abre ninguna base de datos ni crea un entorno web.
class ConsultaListaPrueba extends PDOStatement
{
    public array $parametros = [];
    public function __construct(private array $filas = []) {}
    public function execute(?array $params = null): bool { $this->parametros = $params ?? []; return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->filas; }
}
class ConexionListaPrueba extends PDO
{
    public string $sql = '';
    public ConsultaListaPrueba $consulta;
    public function __construct()
    {
        $this->consulta = new ConsultaListaPrueba([
            ['id_producto' => 7, 'codigo' => '0007', 'nombre' => '<Producto> & prueba', 'imagen' => null],
            ['id_producto' => 9, 'codigo' => 'ABC-9', 'nombre' => 'Segundo ficticio', 'imagen' => null],
        ]);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($query !== 'SELECT visible FROM categorias LIMIT 0') { throw new RuntimeException('Consulta inesperada'); }
        return new ConsultaListaPrueba();
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->sql = $query;
        if (!str_starts_with($query, 'SELECT ')) { throw new RuntimeException('Se intentó escribir'); }
        return $this->consulta;
    }
}

$comprobaciones = 0;
function verificar_lista(bool $condicion, string $caso): void
{
    global $comprobaciones;
    if (!$condicion) { throw new RuntimeException($caso); }
    $comprobaciones++;
}
function lista_rechazada(mixed $lista): void
{
    try { normalizar_lista_solicitud($lista); }
    catch (InvalidArgumentException $error) { verificar_lista(true, 'Rechazo de entrada inválida'); return; }
    throw new RuntimeException('Se aceptó una lista inválida');
}

verificar_lista(normalizar_lista_solicitud([]) === [], 'Lista vacía');
$entrada = [['id_producto' => 9, 'cantidad' => 2], ['id_producto' => 7, 'cantidad' => 1], ['id_producto' => 9, 'cantidad' => 3]];
verificar_lista(normalizar_lista_solicitud($entrada) === [['id_producto' => 9, 'cantidad' => 5], ['id_producto' => 7, 'cantidad' => 1]], 'Agrupa duplicados y conserva orden');
foreach ([null, 'lista', ['clave' => ['id_producto' => 1, 'cantidad' => 1]], [null], [[]]] as $entrada_invalida) { lista_rechazada($entrada_invalida); }
foreach ([0, -1, 1.5, true, '2', null, 2147483648, '1 OR 1=1'] as $id) { lista_rechazada([['id_producto' => $id, 'cantidad' => 1]]); }
foreach ([0, -1, 1000, 1.5, true, '1', null, [], '1e2'] as $cantidad) { lista_rechazada([['id_producto' => 7, 'cantidad' => $cantidad]]); }
lista_rechazada([['id_producto' => 7, 'cantidad' => 1, 'nombre' => 'Nombre falsificado']]);
lista_rechazada([['id_producto' => 7, 'cantidad' => 500], ['id_producto' => 7, 'cantidad' => 500]]);
$cien = array_map(fn($id) => ['id_producto' => $id, 'cantidad' => 999], range(1, 100));
verificar_lista(count(normalizar_lista_solicitud($cien)) === 100, 'Límites máximos aceptados');
$cien[] = ['id_producto' => 101, 'cantidad' => 1];
lista_rechazada($cien);
$db = new ConexionListaPrueba();
$resultado = validar_lista_solicitud($db, array_merge($entrada, [['id_producto' => 15, 'cantidad' => 4]]));
verificar_lista($resultado['retirados'] === [15], 'Retira identificadores que no devuelve la consulta pública');
verificar_lista(array_column($resultado['productos'], 'id_producto') === [9, 7], 'Devuelve todos los productos distintos en orden');
verificar_lista($resultado['productos'][0]['cantidad'] === 5, 'Cantidad agrupada');
verificar_lista($resultado['productos'][1]['codigo'] === '0007', 'Código original sin perder ceros');
verificar_lista($resultado['productos'][1]['imagen'] === 'assets/images/producto_sin_imagen.svg', 'Fotografía de reserva');
verificar_lista($db->consulta->parametros === [9, 7, 15], 'IDs parametrizados');
verificar_lista(str_contains($db->sql, 'p.visible = 1') && str_contains($db->sql, 'c.visible = 1'), 'Ambas visibilidades obligatorias');
verificar_lista(array_keys($resultado['productos'][0]) === ['id_producto', 'codigo', 'nombre', 'imagen', 'cantidad'], 'Sin datos de precios, existencias o clientes');
echo "OK: $comprobaciones comprobaciones de lista, límites, duplicados y validación. Sin MySQL ni servidores.\n";

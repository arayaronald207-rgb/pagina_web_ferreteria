<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/Servicios/categorias.php';
require_once __DIR__ . '/../app/Servicios/panel_administrativo.php';

// Dobles en memoria: este archivo no llama a obtener_conexion() ni accede a MySQL.
class ConsultaCategoriasPrueba extends PDOStatement
{
    public function __construct(private array $respuesta) {}
    public function execute(?array $params = null): bool
    {
        if (isset($this->respuesta['error'])) { throw $this->respuesta['error']; }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    { return $this->respuesta['fila'] ?? false; }
    public function rowCount(): int { return $this->respuesta['afectadas'] ?? 0; }
}
class ConexionCategoriasPrueba extends PDO
{
    public array $sentencias = [];
    public function __construct(private array $respuestas) {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        // Este doble representa el esquema con visibilidad disponible.
        if ($query !== 'SELECT visible FROM categorias LIMIT 0') { throw new LogicException('Consulta inesperada.'); }
        return new ConsultaCategoriasPrueba([]);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->sentencias[] = $query;
        if ($this->respuestas === []) { throw new LogicException('Consulta inesperada.'); }
        return new ConsultaCategoriasPrueba(array_shift($this->respuestas));
    }
}

$comprobaciones = 0;
function comprobar(bool $condicion, string $caso): void
{
    global $comprobaciones;
    if (!$condicion) { throw new RuntimeException('Falló: ' . $caso); }
    $comprobaciones++;
}
function rechaza(callable $operacion, string $mensaje): void
{
    try { $operacion(); } catch (InvalidArgumentException $error) {
        comprobar(str_contains($error->getMessage(), $mensaje), 'mensaje de validación');
        return;
    }
    throw new RuntimeException('Se aceptó una operación que debía rechazarse.');
}

comprobar(validar_nombre_categoria("  Herramientas   eléctricas  ") === 'Herramientas eléctricas', 'normalización');
comprobar(validar_nombre_categoria(str_repeat('á', 100)) === str_repeat('á', 100), '100 caracteres Unicode');
foreach (['', '   ', [], str_repeat('á', 101), "Nombre\0oculto", "\xFF"] as $nombre) {
    rechaza(fn() => validar_nombre_categoria($nombre), 'nombre');
}
foreach ([0, '-1', '1 OR 1=1', [], '1.2', '2147483648'] as $id) {
    rechaza(fn() => validar_id_categoria($id), 'categoría');
}
comprobar(validar_id_categoria('12') === 12, 'identificador válido');
comprobar(escapar_panel('<script>"&') === '&lt;script&gt;&quot;&amp;', 'escape HTML');

$duplicada = new ConexionCategoriasPrueba([['fila' => ['id_categoria' => 2]]]);
rechaza(fn() => guardar_categoria($duplicada, 'Herramientas'), 'Ya existe');
comprobar(count($duplicada->sentencias) === 1, 'duplicado rechazado antes de escribir');
$errorDuplicado = new PDOException('Detalle interno simulado');
$errorDuplicado->errorInfo = ['23000', 1062, 'Detalle interno'];
rechaza(fn() => guardar_categoria(new ConexionCategoriasPrueba([[], ['error' => $errorDuplicado]]), 'Herramientas'), 'Ya existe');
$categoria = ['id_categoria' => 1, 'nombre' => 'Herramientas', 'productos' => 0];
guardar_categoria(new ConexionCategoriasPrueba([[], ['afectadas' => 1]]), 'Nueva');
guardar_categoria(new ConexionCategoriasPrueba([['fila' => $categoria], [], ['afectadas' => 1]]), 'Editada', 1);
guardar_categoria(new ConexionCategoriasPrueba([['fila' => $categoria], [], ['afectadas' => 0], ['fila' => $categoria]]), 'Herramientas', 1);
rechaza(fn() => guardar_categoria(new ConexionCategoriasPrueba([[]]), 'No existe', 999), 'ya no existe');
rechaza(fn() => eliminar_categoria(new ConexionCategoriasPrueba([['fila' => $categoria], ['afectadas' => 0]]), 1), 'No se eliminó');
$errorRelacion = new PDOException('Detalle interno simulado');
$errorRelacion->errorInfo = ['23000', 1451, 'Detalle interno'];
rechaza(fn() => eliminar_categoria(new ConexionCategoriasPrueba([['fila' => $categoria], ['error' => $errorRelacion]]), 1), 'productos asociados');
eliminar_categoria(new ConexionCategoriasPrueba([['fila' => $categoria], ['afectadas' => 1]]), 1);

ini_set('session.save_path', sys_get_temp_dir());
iniciar_sesion_segura();
$_SESSION = [];
$token = obtener_token_csrf();
comprobar(validar_token_csrf($token), 'CSRF válido');
comprobar(!validar_token_csrf(null) && !validar_token_csrf([]) && !validar_token_csrf('incorrecto'), 'CSRF inválido');
session_destroy();
echo "Pruebas sin MySQL superadas: $comprobaciones comprobaciones, más operaciones simuladas de creación, edición y eliminación.\n";

<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/Servicios/usuarios.php';
require_once __DIR__ . '/../app/Servicios/panel_administrativo.php';

session_set_save_handler(new class implements SessionHandlerInterface {
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false { return ''; }
    public function write(string $id, string $data): bool { return true; }
    public function destroy(string $id): bool { return true; }
    public function gc(int $max_lifetime): int|false { return 0; }
}, true);
$_SERVER['SCRIPT_NAME'] = '/pagina_web_ferreteria/public/usuarios.php';
iniciar_sesion_segura();

const CLAVE_USUARIO_PRUEBA = 'PruebaLocal123!';
class ConsultaUsuariosPrueba extends PDOStatement
{
    private array $filas = [];
    public function __construct(private ConexionUsuariosPrueba $db, public string $sql) {}
    public function execute(?array $params = null): bool
    {
        $p = $params ?? [];
        $this->db->ejecuciones[] = [$this->sql, array_keys($p)];
        if (str_starts_with($this->sql, 'SELECT')) {
            if (isset($p['id'])) { $this->filas = isset($this->db->cuentas[$p['id']]) ? [$this->db->cuentas[$p['id']]] : []; }
            elseif (isset($p['correo'])) { $this->filas = array_values(array_filter($this->db->cuentas, fn($u) => strcasecmp($u['correo'], $p['correo']) === 0)); }
            else { $this->filas = array_values($this->db->cuentas); }
            if (!str_contains($this->sql, 'clave_hash')) {
                $this->filas = array_map(function ($u) { unset($u['clave_hash']); return $u; }, $this->filas);
            }
            return true;
        }
        if (!$this->db->activa) { throw new RuntimeException('Escritura fuera de transacción'); }
        if ($this->db->fallar) { throw new PDOException('Fallo simulado'); }
        if (str_starts_with($this->sql, 'INSERT INTO usuarios ')) {
            $id = ++$this->db->ultimo;
            $this->db->cuentas[$id] = ['id_usuario' => $id, 'activo' => 1] + $p;
        } elseif (str_starts_with($this->sql, 'UPDATE usuarios SET ')) {
            $id = $p['id']; unset($p['id']);
            $this->db->cuentas[$id] = array_replace($this->db->cuentas[$id], $p);
        } else { throw new RuntimeException('Escritura no permitida'); }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->filas[0] ?? false; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->filas; }
}
class ConexionUsuariosPrueba extends PDO
{
    public array $cuentas = [], $ejecuciones = [];
    private array $respaldo = [];
    public bool $activa = false, $fallar = false;
    public int $ultimo = 4, $commits = 0, $rollbacks = 0;
    public function __construct()
    {
        static $hash = null;
        $hash ??= password_hash(CLAVE_USUARIO_PRUEBA, PASSWORD_DEFAULT);
        foreach ([1 => 'administrador', 2 => 'empleado', 3 => 'administrador', 4 => 'empleado'] as $id => $rol) {
            $this->cuentas[$id] = ['id_usuario' => $id, 'nombre' => 'Persona ficticia ' . $id,
                'correo' => 'persona' . $id . '@example.test', 'rol' => $rol, 'activo' => $id === 4 ? 0 : 1, 'clave_hash' => $hash];
        }
    }
    public function prepare(string $query, array $options = []): PDOStatement|false { return new ConsultaUsuariosPrueba($this, $query); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (str_contains($query, 'FOR UPDATE') && !$this->activa) { throw new RuntimeException('Bloqueo sin transacción'); }
        $q = $this->prepare($query); $q->execute(); return $q;
    }
    public function beginTransaction(): bool { $this->respaldo = $this->cuentas; $this->activa = true; return true; }
    public function inTransaction(): bool { return $this->activa; }
    public function commit(): bool { $this->commits++; $this->activa = false; return true; }
    public function rollBack(): bool { $this->rollbacks++; $this->cuentas = $this->respaldo; $this->activa = false; return true; }
    public function lastInsertId(?string $name = null): string|false { return (string) $this->ultimo; }
}

$modo_prueba = $argv[1] ?? 'pruebas';
if ($modo_prueba === 'acceso') {
    establecer_sesion_usuario(['id_usuario' => 1, 'nombre' => 'Prueba', 'correo' => 'prueba@example.test', 'rol' => $argv[2] ?? 'empleado']);
    exigir_autenticacion(['administrador']);
    echo 'ACCESO AUTORIZADO'; exit;
}
if ($modo_prueba === 'vista') {
    $tipo = $argv[2] ?? 'listado';
    $db = new ConexionUsuariosPrueba();
    $db->cuentas[2]['nombre'] = '<script>window.inyectado=1</script> Persona';
    $usuario = $db->cuentas[1]; unset($usuario['clave_hash']);
    $actor = 1; $titulo_pagina = 'Usuarios'; $seccion_actual = 'usuarios';
    $cuentas = listar_usuarios($db); $mensaje = ''; $error_formulario = ''; $formulario = null; $confirmar = null; $token_confirmacion = null;
    $modo = '';
    if ($tipo === 'crear') { $modo = 'crear'; $formulario = ['id_usuario' => null, 'nombre' => '', 'correo' => '', 'rol' => 'empleado']; }
    if ($tipo === 'editar' || $tipo === 'clave') { $modo = $tipo; $formulario = buscar_usuario_panel($db, 2); }
    if ($tipo === 'confirmar' || $tipo === 'error') {
        $pendientes = [];
        $token_confirmacion = preparar_cambio_usuario($db, 1, ['operacion' => 'estado', 'id_usuario' => 2, 'activo' => '0'], $pendientes);
        $confirmar = $pendientes[$token_confirmacion];
        if ($tipo === 'error') { $error_formulario = 'La contraseña del administrador no es correcta. No se guardaron cambios.'; }
    }
    if ($tipo === 'resultado') { $mensaje = 'Cuenta desactivada correctamente.'; $cuentas[1]['activo'] = 0; }
    if ($tipo === 'empleado') { $usuario['rol'] = 'empleado'; }
    require __DIR__ . '/../app/Vistas/plantillas/cabecera_administrativa.php';
    if ($tipo !== 'empleado') { require __DIR__ . '/../app/Vistas/usuarios.php'; }
    require __DIR__ . '/../app/Vistas/plantillas/pie_administrativo.php';
    exit;
}

$casos = 0;
function verificar_usuarios(bool $valor, string $caso): void
{
    global $casos;
    if (!$valor) { throw new RuntimeException('Falló: ' . $caso); }
    $casos++;
}
function rechazar_usuarios(callable $operacion, string $clase = InvalidArgumentException::class): void
{
    try { $operacion(); } catch (Throwable $error) {
        if (!$error instanceof $clase) { throw $error; }
        verificar_usuarios(true, 'Operación rechazada'); return;
    }
    throw new RuntimeException('Se aceptó una operación inválida');
}
foreach ([0, -1, true, [], null, '1 OR 1=1', '01', '1.5', 2147483648] as $id) { rechazar_usuarios(fn() => validar_id_usuario($id)); }
$datos = ['nombre' => 'Nombre Prueba', 'correo' => 'nueva@example.test', 'rol' => 'empleado'];
foreach ([['nombre',''], ['nombre',[]], ['nombre',str_repeat('á',101)], ['nombre',"A\0B"], ['nombre',"\xFF"], ['correo','x'], ['correo',[]], ['correo',str_repeat('a',151)], ['rol','visitante'], ['rol',[]]] as [$campo,$valor]) {
    rechazar_usuarios(fn() => validar_datos_usuario(array_replace($datos, [$campo => $valor])));
}
verificar_usuarios(validar_datos_usuario(array_replace($datos, ['correo' => '  NUEVA@example.test  ']))['correo'] === 'nueva@example.test', 'Correo normalizado');
foreach (['', 'Abc123', str_repeat('a',13), 'SINCAMBIOS1234', 'SinDigitosLargos', str_repeat('Aa1',25), str_repeat('Á',35).'abc1234', "ClavePrueba1\0extra", []] as $clave) { rechazar_usuarios(fn() => hash_nueva_clave_usuario($clave,$clave)); }
rechazar_usuarios(fn() => hash_nueva_clave_usuario(CLAVE_USUARIO_PRUEBA, 'OtraClave123!'));
$hash = hash_nueva_clave_usuario(CLAVE_USUARIO_PRUEBA, CLAVE_USUARIO_PRUEBA);
verificar_usuarios($hash !== CLAVE_USUARIO_PRUEBA && password_verify(CLAVE_USUARIO_PRUEBA,$hash) && !password_verify('incorrecta',$hash), 'Hash y verificación');

$db = new ConexionUsuariosPrueba(); $pendientes = [];
$crear = $datos + ['operacion' => 'crear', 'clave_nueva' => CLAVE_USUARIO_PRUEBA, 'clave_repetida' => CLAVE_USUARIO_PRUEBA];
$originales = $db->cuentas;
$token = preparar_cambio_usuario($db, 1, $crear, $pendientes);
verificar_usuarios($db->cuentas === $originales && !str_contains(json_encode($pendientes),CLAVE_USUARIO_PRUEBA), 'Preparación no escribe ni conserva contraseña en claro');
rechazar_usuarios(function () use ($db, $token, &$pendientes) { confirmar_cambio_usuario($db,1,$token,'incorrecta',$pendientes); }, CredencialAdministradorInvalida::class);
verificar_usuarios(isset($pendientes[$token]) && $db->cuentas === $originales, 'Reautenticación fallida sin cambios');
$resultado = confirmar_cambio_usuario($db,1,$token,CLAVE_USUARIO_PRUEBA,$pendientes);
verificar_usuarios($resultado['id'] === 5 && $db->cuentas[5]['activo'] === 1 && $db->cuentas[5]['rol'] === 'empleado', 'Creación de empleado');
verificar_usuarios(password_verify(CLAVE_USUARIO_PRUEBA,$db->cuentas[5]['clave_hash']) && $db->commits === 1, 'Contraseña persistida como hash');
rechazar_usuarios(function () use ($db, $token, &$pendientes) { confirmar_cambio_usuario($db,1,$token,CLAVE_USUARIO_PRUEBA,$pendientes); });
$crear_admin = array_replace($crear,['correo'=>'otro@example.test','rol'=>'administrador']);
$token = preparar_cambio_usuario($db,1,$crear_admin,$pendientes);
confirmar_cambio_usuario($db,1,$token,CLAVE_USUARIO_PRUEBA,$pendientes);
verificar_usuarios($db->cuentas[6]['rol'] === 'administrador', 'Creación de administrador');
rechazar_usuarios(function () use ($db,$crear,&$pendientes) { preparar_cambio_usuario($db,2,$crear,$pendientes); }, PermisoUsuariosDenegado::class);
rechazar_usuarios(function () use ($db,$crear,&$pendientes) { preparar_cambio_usuario($db,4,$crear,$pendientes); }, PermisoUsuariosDenegado::class);
rechazar_usuarios(function () use ($db,$crear,&$pendientes) { preparar_cambio_usuario($db,1,$crear,$pendientes); });
$editar = ['operacion'=>'editar','id_usuario'=>2,'nombre'=>'Nombre nuevo','correo'=>'editada@example.test','rol'=>'administrador'];
$antes = $db->cuentas[2];
$token = preparar_cambio_usuario($db,1,$editar,$pendientes);
confirmar_cambio_usuario($db,1,$token,CLAVE_USUARIO_PRUEBA,$pendientes);
verificar_usuarios($db->cuentas[2] === array_replace($antes, array_intersect_key($editar, $datos)), 'Edita solamente nombre, correo y rol');
foreach (['0','1'] as $activo) {
    $token = preparar_cambio_usuario($db,1,['operacion'=>'estado','id_usuario'=>2,'activo'=>$activo],$pendientes);
    $antes = $db->cuentas[2];
    confirmar_cambio_usuario($db,1,$token,CLAVE_USUARIO_PRUEBA,$pendientes);
    verificar_usuarios($db->cuentas[2] === array_replace($antes,['activo'=>(int)$activo]), 'Activación conserva datos');
}
rechazar_usuarios(function () use ($db,&$pendientes) { preparar_cambio_usuario($db,1,['operacion'=>'estado','id_usuario'=>1,'activo'=>'0'],$pendientes); });
rechazar_usuarios(function () use ($db,&$pendientes) { preparar_cambio_usuario($db,1,['operacion'=>'eliminar','id_usuario'=>2],$pendientes); });
$antes = $db->cuentas[2];
$token = preparar_cambio_usuario($db,1,['operacion'=>'clave','id_usuario'=>2,'clave_nueva'=>'OtraClaveSegura456!','clave_repetida'=>'OtraClaveSegura456!'],$pendientes);
confirmar_cambio_usuario($db,1,$token,CLAVE_USUARIO_PRUEBA,$pendientes);
verificar_usuarios(password_verify('OtraClaveSegura456!',$db->cuentas[2]['clave_hash']) && !password_verify(CLAVE_USUARIO_PRUEBA,$db->cuentas[2]['clave_hash']), 'Cambio de contraseña');
$esperado = $antes; $esperado['clave_hash'] = $db->cuentas[2]['clave_hash'];
verificar_usuarios($db->cuentas[2] === $esperado, 'Cambio de clave conserva perfil');
verificar_usuarios(!str_contains(json_encode(listar_usuarios($db)), 'clave_hash') && !isset(buscar_usuario_panel($db,2)['clave_hash']), 'Listas y formularios sin hashes');

$db = new ConexionUsuariosPrueba(); $pendientes = [];
$token = preparar_cambio_usuario($db,1,['operacion'=>'clave','id_usuario'=>1,'clave_nueva'=>'NuevaClavePropia789!','clave_repetida'=>'NuevaClavePropia789!'],$pendientes);
rechazar_usuarios(function () use ($db,$token,&$pendientes) { confirmar_cambio_usuario($db,3,$token,CLAVE_USUARIO_PRUEBA,$pendientes); });
$propio = confirmar_cambio_usuario($db,1,$token,CLAVE_USUARIO_PRUEBA,$pendientes);
verificar_usuarios($propio['credencial_propia'] === hash('sha256',$db->cuentas[1]['clave_hash']), 'Renueva la credencial de la sesión que cambia su propia contraseña');
verificar_usuarios(password_verify('NuevaClavePropia789!',$db->cuentas[1]['clave_hash']), 'Contraseña propia cambiada en memoria');
rechazar_usuarios(function () use ($db,$crear,&$pendientes) { preparar_cambio_usuario($db,1,array_replace($crear,['correo'=>'PERSONA2@EXAMPLE.TEST']),$pendientes); });
rechazar_usuarios(function () use ($db,&$pendientes) { preparar_cambio_usuario($db,1,['operacion'=>'estado','id_usuario'=>2,'activo'=>true],$pendientes); });
rechazar_usuarios(function () use ($db,&$pendientes) { preparar_cambio_usuario($db,1,['operacion'=>'clave','id_usuario'=>99],$pendientes); });

$db = new ConexionUsuariosPrueba(); $db->cuentas[3]['activo'] = 0; $pendientes = [];
$bajar = ['operacion'=>'editar','id_usuario'=>1,'nombre'=>'Persona uno','correo'=>'persona1@example.test','rol'=>'empleado'];
$token = preparar_cambio_usuario($db,1,$bajar,$pendientes);
rechazar_usuarios(function () use ($db,$token,&$pendientes) { confirmar_cambio_usuario($db,1,$token,CLAVE_USUARIO_PRUEBA,$pendientes); });
verificar_usuarios($db->cuentas[1]['rol'] === 'administrador', 'Protección del último administrador activo');
$db = new ConexionUsuariosPrueba(); $pendientes = [];
$t1 = preparar_cambio_usuario($db,1,$bajar,$pendientes);
$t3 = preparar_cambio_usuario($db,3,array_replace($bajar,['id_usuario'=>3,'correo'=>'persona3@example.test']),$pendientes);
confirmar_cambio_usuario($db,1,$t1,CLAVE_USUARIO_PRUEBA,$pendientes);
rechazar_usuarios(function () use ($db,$t3,&$pendientes) { confirmar_cambio_usuario($db,3,$t3,CLAVE_USUARIO_PRUEBA,$pendientes); });
verificar_usuarios($db->cuentas[3]['rol'] === 'administrador' && $db->cuentas[1]['rol'] === 'empleado', 'Dos revisiones simultáneas conservan administrador');
verificar_usuarios(str_contains(json_encode($db->ejecuciones),'ORDER BY id_usuario FOR UPDATE'), 'Bloqueo ordenado de cuentas');

foreach (['concurrente','permiso','caducado','fallo','duplicado'] as $caso) {
    $db = new ConexionUsuariosPrueba(); $pendientes = [];
    $token = preparar_cambio_usuario($db,1,$editar,$pendientes);
    $clase = InvalidArgumentException::class;
    if ($caso === 'concurrente') { $db->cuentas[2]['nombre'] = 'Cambio ajeno'; $clase = DomainException::class; }
    if ($caso === 'permiso') { $db->cuentas[1]['activo'] = 0; $clase = PermisoUsuariosDenegado::class; }
    if ($caso === 'caducado') { $pendientes[$token]['fecha'] = time()-601; }
    if ($caso === 'fallo') { $db->fallar = true; $clase = PDOException::class; }
    if ($caso === 'duplicado') { $db->cuentas[3]['correo'] = $editar['correo']; }
    $antes = $db->cuentas;
    rechazar_usuarios(function () use ($db,$token,&$pendientes) { confirmar_cambio_usuario($db,1,$token,CLAVE_USUARIO_PRUEBA,$pendientes); }, $clase);
    verificar_usuarios($db->cuentas === $antes, 'Rechazo o rollback conserva todas las cuentas');
}
$db = new ConexionUsuariosPrueba(); $cuenta = $db->cuentas[1];
$usuario = ['rol'=>'administrador']; $huella = hash('sha256',$cuenta['clave_hash']);
verificar_usuarios(cuenta_panel_vigente($usuario,$cuenta,null), 'Compatibilidad con sesiones existentes');
verificar_usuarios(cuenta_panel_vigente($usuario,$cuenta,$huella), 'Sesión vigente');
verificar_usuarios(!cuenta_panel_vigente($usuario,array_replace($cuenta,['activo'=>0]),$huella), 'Cuenta desactivada pierde acceso');
verificar_usuarios(!cuenta_panel_vigente($usuario,array_replace($cuenta,['rol'=>'empleado']),$huella), 'Cambio de rol invalida sesión anterior');
verificar_usuarios(!cuenta_panel_vigente($usuario,array_replace($cuenta,['clave_hash'=>password_hash('OtraClaveSegura123!',PASSWORD_DEFAULT)]),$huella), 'Cambio de contraseña invalida sesión vinculada');
establecer_sesion_usuario($cuenta);
verificar_usuarios($_SESSION['credencial_usuario'] === $huella && !isset($_SESSION['usuario']['clave_hash']), 'Inicio de sesión vincula credencial sin exponer hash');
$csrf = obtener_token_csrf();
verificar_usuarios(validar_token_csrf($csrf) && !validar_token_csrf('incorrecto') && !validar_token_csrf([]), 'CSRF');
echo "OK: $casos comprobaciones de cuentas, contraseñas, permisos, último administrador, concurrencia y sesiones; sin MySQL.\n";

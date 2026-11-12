<?php

declare(strict_types=1);

function iniciar_sesion_segura(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    if (headers_sent()) {
        throw new RuntimeException('No se pudo iniciar la sesión de forma segura.');
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    $ruta_cookie = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $ruta_cookie = $ruta_cookie === '.' ? '/' : rtrim($ruta_cookie, '/');
    $ruta_cookie = $ruta_cookie === '' ? '/' : $ruta_cookie;
    $conexion_segura = isset($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== ''
        && strtolower((string) $_SERVER['HTTPS']) !== 'off';

    session_name('ferreteria_el_cruce');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $ruta_cookie,
        'secure' => $conexion_segura,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        throw new RuntimeException('No se pudo iniciar la sesión de forma segura.');
    }
}

function obtener_token_csrf(): string
{
    iniciar_sesion_segura();

    if (!isset($_SESSION['token_csrf']) || !is_string($_SESSION['token_csrf'])) {
        $_SESSION['token_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['token_csrf'];
}

function validar_token_csrf(mixed $token_recibido): bool
{
    iniciar_sesion_segura();

    return is_string($token_recibido)
        && isset($_SESSION['token_csrf'])
        && is_string($_SESSION['token_csrf'])
        && hash_equals($_SESSION['token_csrf'], $token_recibido);
}

function establecer_sesion_usuario(array $usuario): void
{
    iniciar_sesion_segura();

    $roles_validos = ['administrador', 'empleado'];

    if (!isset($usuario['id_usuario'], $usuario['nombre'], $usuario['correo'], $usuario['rol'])
        || !in_array($usuario['rol'], $roles_validos, true)) {
        throw new RuntimeException('No se pudo establecer la sesión del usuario.');
    }

    if (!session_regenerate_id(true)) {
        throw new RuntimeException('No se pudo establecer la sesión del usuario.');
    }

    $_SESSION = [
        'usuario' => [
            'id_usuario' => (int) $usuario['id_usuario'],
            'nombre' => (string) $usuario['nombre'],
            'correo' => (string) $usuario['correo'],
            'rol' => (string) $usuario['rol'],
        ],
        'ultima_actividad' => time(),
        'token_csrf' => bin2hex(random_bytes(32)),
    ];
}

function obtener_usuario_actual(): ?array
{
    iniciar_sesion_segura();

    $usuario = $_SESSION['usuario'] ?? null;
    $ultima_actividad = $_SESSION['ultima_actividad'] ?? null;
    $roles_validos = ['administrador', 'empleado'];

    if (!is_array($usuario)
        || !isset($usuario['id_usuario'], $usuario['nombre'], $usuario['correo'], $usuario['rol'])
        || !in_array($usuario['rol'], $roles_validos, true)
        || !is_int($ultima_actividad)) {
        return null;
    }

    if (time() - $ultima_actividad > 1800) {
        cerrar_sesion_completa();
        return null;
    }

    $_SESSION['ultima_actividad'] = time();

    return $usuario;
}

function exigir_autenticacion(array $roles_permitidos = []): array
{
    $usuario = obtener_usuario_actual();

    if ($usuario === null) {
        header('Location: iniciar_sesion.php', true, 303);
        exit;
    }

    if ($roles_permitidos !== [] && !in_array($usuario['rol'], $roles_permitidos, true)) {
        http_response_code(403);
        exit('Acceso denegado.');
    }

    return $usuario;
}

function cerrar_sesion_completa(): void
{
    iniciar_sesion_segura();
    $_SESSION = [];

    $parametros_cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $parametros_cookie['path'],
        'domain' => $parametros_cookie['domain'],
        'secure' => $parametros_cookie['secure'],
        'httponly' => $parametros_cookie['httponly'],
        'samesite' => $parametros_cookie['samesite'] ?? 'Lax',
    ]);

    session_destroy();
}

function actualizar_limite_inicio_sesion(string $accion): int
{
    if (!in_array($accion, ['consultar', 'fallo', 'reiniciar'], true)) {
        throw new InvalidArgumentException('La acción de control de intentos no es válida.');
    }

    $direccion_ip = $_SERVER['REMOTE_ADDR'] ?? 'desconocida';
    $direccion_ip = filter_var($direccion_ip, FILTER_VALIDATE_IP) ? $direccion_ip : 'desconocida';
    $directorio_limites = dirname(__DIR__, 2)
        . DIRECTORY_SEPARATOR . 'almacenamiento'
        . DIRECTORY_SEPARATOR . 'registros'
        . DIRECTORY_SEPARATOR . 'intentos_inicio_sesion';

    if (!is_dir($directorio_limites)
        && !@mkdir($directorio_limites, 0700, true)
        && !is_dir($directorio_limites)) {
        throw new RuntimeException('No se pudo aplicar el control de intentos.');
    }

    @chmod($directorio_limites, 0700);
    $ruta_limite = $directorio_limites
        . DIRECTORY_SEPARATOR . hash('sha256', $direccion_ip)
        . '.log';
    $archivo_limite = @fopen($ruta_limite, 'c+');

    if ($archivo_limite === false) {
        throw new RuntimeException('No se pudo aplicar el control de intentos.');
    }

    if (!flock($archivo_limite, LOCK_EX)) {
        fclose($archivo_limite);
        throw new RuntimeException('No se pudo aplicar el control de intentos.');
    }

    $ahora = time();
    $ventana_segundos = 900;
    $maximo_intentos = 5;

    try {
        rewind($archivo_limite);
        $registro = json_decode(stream_get_contents($archivo_limite), true);
        $intentos = is_array($registro) && isset($registro['intentos']) && is_int($registro['intentos'])
            ? $registro['intentos']
            : 0;
        $inicio_ventana = is_array($registro) && isset($registro['inicio_ventana']) && is_int($registro['inicio_ventana'])
            ? $registro['inicio_ventana']
            : $ahora;

        if ($inicio_ventana > $ahora || $ahora - $inicio_ventana >= $ventana_segundos) {
            $intentos = 0;
            $inicio_ventana = $ahora;
        }

        if ($accion === 'fallo') {
            $intentos++;
        } elseif ($accion === 'reiniciar') {
            $intentos = 0;
            $inicio_ventana = $ahora;
        }

        $registro_actualizado = json_encode(
            ['intentos' => $intentos, 'inicio_ventana' => $inicio_ventana],
            JSON_THROW_ON_ERROR
        );

        if (!rewind($archivo_limite)
            || !ftruncate($archivo_limite, 0)
            || fwrite($archivo_limite, $registro_actualizado) === false
            || !fflush($archivo_limite)) {
            throw new RuntimeException('No se pudo guardar el control de intentos.');
        }

        @chmod($ruta_limite, 0600);
    } finally {
        flock($archivo_limite, LOCK_UN);
        fclose($archivo_limite);
    }

    if ($intentos < $maximo_intentos) {
        return 0;
    }

    return max(1, $inicio_ventana + $ventana_segundos - $ahora);
}

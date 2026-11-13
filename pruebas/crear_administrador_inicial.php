<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'conexion.php';

function leer_dato_consola(string $mensaje): string
{
    if (function_exists('readline')) {
        $respuesta = readline($mensaje);
        return is_string($respuesta) ? trim($respuesta) : '';
    }

    fwrite(STDOUT, $mensaje);
    $respuesta = fgets(STDIN);
    return is_string($respuesta) ? trim($respuesta) : '';
}

function leer_clave_oculta(string $mensaje): string
{
    // ReadKey(true) evita mostrar la contraseña. PHP captura la salida en memoria.
    $codigo = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$clave = [System.Text.StringBuilder]::new()
try {
    while ($true) {
        $tecla = [Console]::ReadKey($true)
        if ($tecla.Key -eq [ConsoleKey]::Enter) { break }
        if ($tecla.Key -eq [ConsoleKey]::Escape) { exit 1 }
        if ($tecla.Key -eq [ConsoleKey]::Backspace) {
            if ($clave.Length -gt 0) { $clave.Length-- }
        } elseif (-not [char]::IsControl($tecla.KeyChar)) {
            [void]$clave.Append($tecla.KeyChar)
        }
    }
    [Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)
    [Console]::Write($clave.ToString())
} catch {
    exit 1
} finally {
    [void]$clave.Clear()
}
POWERSHELL;

    fwrite(STDOUT, $mensaje);
    $proceso = proc_open(
        ['powershell.exe', '-NoLogo', '-NoProfile', '-EncodedCommand',
            base64_encode(iconv('UTF-8', 'UTF-16LE', $codigo))],
        [0 => STDIN, 1 => ['pipe', 'w'], 2 => STDERR],
        $tuberias,
        null,
        null,
        ['bypass_shell' => true]
    );

    if (!is_resource($proceso)) {
        throw new RuntimeException('No se pudo abrir la entrada oculta.');
    }

    $clave = stream_get_contents($tuberias[1]);
    fclose($tuberias[1]);
    $resultado = proc_close($proceso);
    fwrite(STDOUT, PHP_EOL);

    if ($resultado !== 0 || $clave === false) {
        throw new RuntimeException('Se canceló la entrada oculta.');
    }

    return $clave;
}

if (PHP_OS_FAMILY !== 'Windows' || !stream_isatty(STDIN)
    || !function_exists('proc_open') || !function_exists('iconv')) {
    fwrite(STDERR, "Ejecuta este script desde una consola interactiva de Windows con PHP de XAMPP.\n");
    exit(1);
}

$nombre = leer_dato_consola('Nombre del administrador: ');
$correo = leer_dato_consola('Correo electrónico: ');
try {
    $clave = leer_clave_oculta('Contraseña (oculta; Esc para cancelar): ');
    $confirmacion = leer_clave_oculta('Repite la contraseña (oculta): ');
    if (!hash_equals($clave, $confirmacion)) {
        fwrite(STDERR, "Las contraseñas no coinciden. No se creó ningún usuario.\n");
        exit(1);
    }
    unset($confirmacion);
} catch (Throwable $error) {
    fwrite(STDERR, "No se pudo leer la contraseña de forma oculta. No se creó ningún usuario.\n");
    exit(1);
}

$longitud_clave = is_string($clave) ? preg_match_all('/./us', $clave) : false;

if ($nombre === '' || strlen($nombre) > 100
    || strlen($correo) > 150
    || filter_var($correo, FILTER_VALIDATE_EMAIL) === false
    || !is_string($clave)
    || $longitud_clave === false
    || $longitud_clave < 12
    || strlen($clave) > 72) {
    fwrite(STDERR, "Datos no válidos. Usa nombre y correo válidos, y una contraseña de al menos 12 caracteres y como máximo 72 bytes UTF-8.\n");
    exit(1);
}

if (leer_dato_consola('¿Autorizas crear el primer administrador en la base de datos local? Escribe SI: ') !== 'SI') {
    fwrite(STDOUT, "Operación cancelada. No se creó ningún usuario.\n");
    exit;
}

try {
    $conexion = obtener_conexion();
    $consulta_administradores = $conexion->query(
        "SELECT COUNT(*) FROM usuarios WHERE rol = 'administrador'"
    );

    if ((int) $consulta_administradores->fetchColumn() > 0) {
        fwrite(STDERR, "Ya existe una cuenta administradora; no se creó otra.\n");
        exit(1);
    }

    $hash_clave = password_hash($clave, PASSWORD_DEFAULT);
    $clave = str_repeat("\0", strlen($clave));
    unset($clave);

    $consulta_usuario = $conexion->prepare(
        'INSERT INTO usuarios (nombre, correo, clave_hash, rol, activo)
        VALUES (:nombre, :correo, :clave_hash, :rol, :activo)'
    );
    $consulta_usuario->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $consulta_usuario->bindValue(':correo', $correo, PDO::PARAM_STR);
    $consulta_usuario->bindValue(':clave_hash', $hash_clave, PDO::PARAM_STR);
    $consulta_usuario->bindValue(':rol', 'administrador', PDO::PARAM_STR);
    $consulta_usuario->bindValue(':activo', 1, PDO::PARAM_INT);
    $consulta_usuario->execute();

    fwrite(STDOUT, "Cuenta administradora creada. Ya puedes probar el inicio de sesión.\n");
} catch (Throwable $error) {
    error_log('No se pudo crear la cuenta administradora inicial.');
    fwrite(STDERR, "No se pudo crear la cuenta. Revisa la conexión y los datos ingresados.\n");
    exit(1);
}

<?php

declare(strict_types=1);
require_once __DIR__ . '/../app/Servicios/panel_administrativo.php';
require_once __DIR__ . '/../app/Servicios/productos_masivos.php';
[$conexion, $usuario] = iniciar_panel();
header('Content-Type: application/json; charset=UTF-8');
if ($usuario['rol'] !== 'administrador') { http_response_code(403); echo json_encode(['error' => 'Solo un administrador puede gestionar productos en conjunto.']); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); http_response_code(405); echo json_encode(['error' => 'Método no permitido.']); exit; }
try {
    if (!validar_token_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) { http_response_code(400); throw new InvalidArgumentException('La sesión venció o la solicitud no es válida.'); }
    $contenido = file_get_contents('php://input', false, null, 0, 200001);
    if ($contenido === false || strlen($contenido) > 200000) { throw new InvalidArgumentException('La selección es demasiado grande.'); }
    try { $entrada = json_decode($contenido, true, 16, JSON_THROW_ON_ERROR); }
    catch (JsonException $error) { throw new InvalidArgumentException('La solicitud no es válida.'); }
    if (!is_array($entrada)) { throw new InvalidArgumentException('La solicitud no es válida.'); }
    $accion = $entrada['accion'] ?? '';
    // El bloqueo de la sesión serializa confirmaciones y lotes de esta cuenta/sesión.
    $trabajo = $_SESSION['productos_masivos'] ?? null;
    if ($trabajo && $trabajo['vence'] < time()) { unset($_SESSION['productos_masivos']); $trabajo = null; }
    if ($accion === 'previa') {
        if ($trabajo && $trabajo['estado'] === 'procesando') { throw new InvalidArgumentException('Termina o cierra la operación pendiente antes de preparar otra.'); }
        $trabajo = preparar_accion_productos($conexion, $entrada);
    } elseif ($accion !== 'estado') {
        if (!$trabajo || !is_string($entrada['trabajo'] ?? null) || !hash_equals($trabajo['id'], $entrada['trabajo'])) {
            throw new InvalidArgumentException('La vista previa venció o fue reemplazada. Revisa nuevamente la selección.');
        }
        if ($accion === 'confirmar') {
            if (($entrada['confirmar'] ?? null) !== true) { throw new InvalidArgumentException('Confirma expresamente la acción revisada.'); }
            // Una respuesta perdida puede reenviarse: no reiniciar ni duplicar los lotes.
            if ($trabajo['estado'] === 'previa') { $trabajo['estado'] = 'procesando'; }
        } elseif ($accion === 'lote') {
            $trabajo = procesar_accion_productos($conexion, $trabajo, $entrada['inicio'] ?? null);
        } elseif ($accion === 'cancelar') { $trabajo = null; }
        else { throw new InvalidArgumentException('Operación no válida.'); }
    }
    $_SESSION['productos_masivos'] = $trabajo;
    echo json_encode(['trabajo' => $trabajo ? resumen_accion_productos($trabajo) : null], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $error) {
    if (http_response_code() < 400) { http_response_code(422); }
    echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    http_response_code(500); error_log('No se pudo completar un lote de gestión de productos.');
    echo json_encode(['error' => 'No se pudo completar el lote. Los lotes anteriores se conservan. Revisa el progreso antes de continuar.']);
}

<?php

declare(strict_types=1);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../app/Servicios/lista_solicitud.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// Es una consulta pública sin efectos en el servidor. No se guardan listas ni datos de clientes.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST'); http_response_code(405);
    echo json_encode(['error' => 'Método no permitido.']); exit;
}
if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
    http_response_code(415); echo json_encode(['error' => 'La lista debe enviarse en formato JSON.']); exit;
}
try {
    $contenido = file_get_contents('php://input', false, null, 0, 16001);
    if ($contenido === false || strlen($contenido) > 16000) {
        throw new InvalidArgumentException('La lista enviada es demasiado grande.');
    }
    $entrada = json_decode($contenido, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($entrada) || array_keys($entrada) !== ['productos']) {
        throw new InvalidArgumentException('La lista enviada no es válida.');
    }
    // Validar tamaño y cantidades antes de abrir la conexión.
    $lista = normalizar_lista_solicitud($entrada['productos']);
    echo json_encode(validar_lista_solicitud(obtener_conexion(), $lista), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException | JsonException $error) {
    http_response_code(422);
    echo json_encode(['error' => $error instanceof JsonException ? 'La lista enviada no es válida.' : $error->getMessage()]);
} catch (Throwable $error) {
    http_response_code(503);
    error_log('No se pudo verificar la lista pública de productos.');
    echo json_encode(['error' => 'No pudimos verificar los productos. Tu lista guardada se conserva; inténtalo nuevamente.']);
}

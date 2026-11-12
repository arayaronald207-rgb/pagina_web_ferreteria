<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Servicios/autenticacion.php';

header('Cache-Control: no-store, private');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Método no permitido.');
}

if (!validar_token_csrf($_POST['token_csrf'] ?? null)) {
    http_response_code(400);
    exit('No se pudo completar la solicitud. Actualiza la página e inténtalo nuevamente.');
}

cerrar_sesion_completa();
header('Location: iniciar_sesion.php', true, 303);
exit;

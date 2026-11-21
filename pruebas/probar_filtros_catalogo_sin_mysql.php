<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Ejecutar: C:\xampp\php\php.exe pruebas/probar_filtros_catalogo_sin_mysql.php
// Se ejecuta una copia idéntica del catálogo y sus consultas, con PDO SQLite
// en memoria. No se carga .env ni la conexión real de MySQL.
$casos = [
    'completo' => [[], ['Brocha', 'Martillo de goma', 'Martillo de prueba']],
    'codigo_parcial' => [['buscar' => 'OD-2'], ['Martillo de goma']],
    'descripcion' => [['buscar' => 'fictici'], ['Brocha', 'Martillo de goma', 'Martillo de prueba']],
    'buscar' => [['buscar' => 'Martillo'], ['Martillo de goma', 'Martillo de prueba']],
    'categoria' => [['categoria' => 'Herramientas'], ['Martillo de prueba']],
    'combinados' => [['buscar' => 'Martillo', 'categoria' => 'Herramientas'], ['Martillo de prueba']],
    'otra_categoria' => [['buscar' => 'Martillo', 'categoria' => 'Acabados'], ['Martillo de goma']],
    'todas_categorias' => [['buscar' => 'Martillo', 'categoria' => ''], ['Martillo de goma', 'Martillo de prueba']],
    'sin_coincidencias' => [['buscar' => 'Brocha', 'categoria' => 'Herramientas'], []],
    'categoria_vacia' => [['categoria' => 'Sin productos'], []],
    'categoria_oculta' => [['categoria' => 'Solo ocultos'], []],
    'vacia_con_busqueda' => [['buscar' => 'Martillo', 'categoria' => 'Sin productos'], []],
    'categoria_especial' => [['categoria' => 'Pinturas & decoración "hogar" <prueba>'], []],
    'ocultos' => [['buscar' => 'Oculto'], []],
    'inyeccion' => [['buscar' => "' OR 1=1 --", 'categoria' => "' OR 1=1 --"], []],
    'escape' => [['buscar' => '"><script>alert(1)</script>&'], []],
    'entrada_invalida' => [['buscar' => ['Martillo'], 'categoria' => ['Herramientas']], ['Brocha', 'Martillo de goma', 'Martillo de prueba']],
    'vacio' => [[], []],
    'error' => [[], []],
];
foreach ([1, 2, 6, 11, 999] as $pagina) {
    $primero = (min($pagina, 11) - 1) * 12 + 1;
    $casos['paginacion_' . $pagina] = [
        ['buscar' => 'Martillo', 'categoria' => 'Herramientas', 'pagina' => $pagina],
        array_map(fn($n) => sprintf('Martillo %03d', $n), range($primero, min($primero + 11, 125))),
    ];
}

if (($argv[1] ?? '') !== '--caso') {
    foreach (array_keys($casos) as $caso) {
        $proceso = proc_open([PHP_BINARY, __FILE__, '--caso', $caso],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias);
        if (!is_resource($proceso)) { throw new RuntimeException('No se pudo iniciar la prueba.'); }
        fclose($tuberias[0]);
        $salida = stream_get_contents($tuberias[1]);
        $error = stream_get_contents($tuberias[2]);
        fclose($tuberias[1]);
        fclose($tuberias[2]);
        $codigo = proc_close($proceso);
        echo $salida;
        if ($codigo !== 0) { fwrite(STDERR, $error); exit(1); }
    }
    echo count($casos) . " escenarios aprobados. MySQL no utilizado.\n";
    exit;
}

$caso = $argv[2] ?? '';
if (!isset($casos[$caso])) { throw new InvalidArgumentException('Caso desconocido.'); }
[$entrada, $esperados] = $casos[$caso];
$raiz = sys_get_temp_dir() . '/el-cruce-filtros-' . bin2hex(random_bytes(6));
$archivos = [];
$directorios = [$raiz, "$raiz/config", "$raiz/public", "$raiz/app", "$raiz/app/Servicios", "$raiz/app/Vistas", "$raiz/app/Vistas/plantillas"];

function comprobar_filtro(bool $condicion, string $mensaje): void
{
    if (!$condicion) { throw new RuntimeException($mensaje); }
}

try {
    foreach ($directorios as $directorio) { mkdir($directorio); }
    foreach (['public/catalogo.php', 'app/Servicios/imagenes_productos.php', 'app/Servicios/detalles_productos.php', 'app/Servicios/catalogo.php', 'app/Servicios/categorias.php', 'app/Vistas/plantillas/cabecera_publica.php', 'app/Vistas/plantillas/pie_publico.php'] as $archivo) {
        $destino = "$raiz/$archivo";
        $archivos[] = $destino;
        if (!copy(dirname(__DIR__) . '/' . $archivo, $destino)) { throw new RuntimeException('Falló la copia aislada.'); }
    }
    $archivos[] = "$raiz/config/conexion.php";
    file_put_contents("$raiz/config/conexion.php", '<?php function obtener_conexion(): PDO { return $GLOBALS["conexion_prueba"]; }');

    $GLOBALS['conexion_prueba'] = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo = $GLOBALS['conexion_prueba'];
    $pdo->exec('CREATE TABLE categorias (id_categoria INTEGER PRIMARY KEY, nombre TEXT)');
    $pdo->exec('CREATE TABLE productos (id_producto INTEGER PRIMARY KEY, id_categoria INTEGER, nombre TEXT, descripcion TEXT, imagen TEXT, visible INTEGER, codigo TEXT)');
    if ($caso !== 'vacio') {
        $pdo->exec("INSERT INTO categorias VALUES (1, 'Herramientas'), (2, 'Acabados'), (3, 'Solo ocultos'), (4, 'Sin productos')");
        $insertar = $pdo->prepare('INSERT INTO categorias VALUES (5, :nombre)');
        $insertar->execute(['nombre' => 'Pinturas & decoración "hogar" <prueba>']);
        $pdo->exec("INSERT INTO productos VALUES
            (1, 1, 'Martillo de prueba', 'Dato ficticio', NULL, 1, 'COD-1'),
            (2, 2, 'Martillo de goma', 'Dato ficticio', NULL, 1, 'COD-2'),
            (3, 2, 'Brocha', 'Dato ficticio', NULL, 1, 'COD-3'),
            (4, 3, 'Oculto', 'No debe publicarse', NULL, 0, 'COD-4')");
    }
    if (str_starts_with($caso, 'paginacion_')) {
        $pdo->exec('DELETE FROM productos'); // Solo la base SQLite en memoria de esta prueba.
        $insertar = $pdo->prepare('INSERT INTO productos VALUES (?, 1, ?, ?, NULL, 1, ?)');
        for ($n = 1; $n <= 125; $n++) {
            $insertar->execute([$n, sprintf('Martillo %03d', $n), 'Dato ficticio', 'P-' . $n]);
        }
    }
    if ($caso === 'error') {
        // Fallo inducido únicamente en SQLite; el detalle nunca debe aparecer en HTML.
        $pdo->exec('DROP TABLE productos');
        $archivos[] = "$raiz/error.log";
        ini_set('error_log', "$raiz/error.log");
    }
    $_GET = $entrada;
    ob_start();
    require "$raiz/public/catalogo.php";
    $html = ob_get_clean();
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    $nombres = [];
    foreach ($xpath->query('//article//h2') as $nodo) { $nombres[] = $nodo->textContent; }
    comprobar_filtro($nombres === $esperados, "$caso: resultados incorrectos");
    comprobar_filtro($xpath->query('//input[@type="search"]')->length === 1, 'Debe existir un solo buscador visible');
    $buscar = is_string($entrada['buscar'] ?? '') ? trim($entrada['buscar'] ?? '') : '';
    comprobar_filtro($xpath->query('//main//form | //select[@name="categoria"]')->length === 0, 'El formulario grande sigue visible');
    comprobar_filtro($xpath->query('//input[@id="busqueda_cabecera"]')->item(0)->getAttribute('value') === $buscar, 'El encabezado perdió la búsqueda');
    $categoria = is_string($entrada['categoria'] ?? '') ? trim($entrada['categoria'] ?? '') : '';
    if ($caso === 'inyeccion') { $categoria = ''; }
    $resumen = $xpath->query('//div[@id="filtros"]')->item(0);
    comprobar_filtro(($resumen !== null) === ($buscar !== '' || $categoria !== ''), 'Resumen de filtros incorrecto');
    if ($resumen !== null) {
        comprobar_filtro(str_contains($resumen->textContent, $buscar) && str_contains($resumen->textContent, $categoria), 'El resumen perdió filtros');
        comprobar_filtro($xpath->query('//a[@class="catalogo__limpiar"]')->item(0)->getAttribute('href') === 'catalogo.php', 'Quitar filtros debe limpiar los parámetros');
    }
    $categoria_buscador = $xpath->query('//form[@role="search"]/input[@name="categoria"]')->item(0);
    comprobar_filtro($categoria === '' ? $categoria_buscador === null : $categoria_buscador instanceof DOMElement && $categoria_buscador->getAttribute('value') === $categoria, 'El buscador debe conservar la categoría activa');
    if ($caso !== 'vacio' && $caso !== 'error') {
        $enlaces_menu = $xpath->query('//ul[@class="megamenu__lista"]//a');
        comprobar_filtro($enlaces_menu->length === 5, 'Deben ofrecerse todas las categorías, incluso las vacías');
        foreach ($enlaces_menu as $enlace) {
            parse_str(parse_url($enlace->getAttribute('href'), PHP_URL_QUERY), $parametros);
            comprobar_filtro(($parametros['buscar'] ?? '') === $buscar && $parametros['categoria'] === $enlace->textContent, 'El enlace perdió búsqueda o categoría');
        }
    }
    comprobar_filtro(!str_contains($html, 'No debe publicarse'), 'Se publicó un producto oculto');
    comprobar_filtro(!str_contains($html, '<prueba>'), 'Falta escape en el nombre de categoría');
    comprobar_filtro(!str_contains($html, '<script>alert(1)</script>'), 'Falta escape HTML');
    if ($caso === 'vacio') {
        comprobar_filtro(str_contains($html, 'Estamos preparando nuestro catálogo'), 'Estado vacío incorrecto');
    } elseif ($caso === 'error') {
        comprobar_filtro(str_contains($html, 'No pudimos cargar el catálogo') && !str_contains($html, 'SQLSTATE'), 'Error interno expuesto');
    } elseif ($caso === 'inyeccion') {
        comprobar_filtro(str_contains($html, 'La categoría seleccionada no está disponible'), 'Categoría inválida debe rechazarse sin ampliar resultados');
    } elseif ($esperados === [] && $categoria !== '' && $buscar === '') {
        comprobar_filtro(str_contains($html, 'No hay productos publicados en esta categoría'), 'Falta explicación de categoría sin productos');
    } elseif ($esperados === []) {
        comprobar_filtro(str_contains($html, 'No encontramos coincidencias'), 'Falta estado sin coincidencias');
    }
    // Captura opcional para revisión visual, en una ubicación temporal explícita.
    if (($salidaHtml = getenv('CRUCE_PRUEBA_HTML')) !== false && $caso === 'combinados') {
        file_put_contents($salidaHtml, str_replace('<head>', '<head><base href="http://localhost/pagina_web_ferreteria/public/">', $html));
    }
    $paginacion = $xpath->query('//nav[@class="catalogo__paginacion"]');
    comprobar_filtro($paginacion->length === (str_starts_with($caso, 'paginacion_') ? 1 : 0), 'Controles innecesarios o ausentes');
    if (str_starts_with($caso, 'paginacion_')) {
        comprobar_filtro($xpath->query('//ul[@class="catalogo__paginas"]/li')->length <= 7, 'Demasiados números de página');
        comprobar_filtro($xpath->query('//span[@aria-current="page"]')->item(0)->textContent === (string) min($entrada['pagina'], 11), 'Página actual incorrecta');
        foreach ($xpath->query('//nav[@class="catalogo__paginacion"]//a') as $enlace) {
            parse_str(parse_url($enlace->getAttribute('href'), PHP_URL_QUERY), $parametros);
            comprobar_filtro($parametros['buscar'] === 'Martillo' && $parametros['categoria'] === 'Herramientas', 'Paginación perdió filtros');
        }
    }
    echo ($argv[3] ?? '') === '--html' ? $html : "OK: $caso\n";
} finally {
    foreach (array_reverse($archivos) as $archivo) { if (is_file($archivo)) { unlink($archivo); } }
    foreach (array_reverse($directorios) as $directorio) { if (is_dir($directorio)) { rmdir($directorio); } }
}

<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Ejecutar: C:\xampp\php\php.exe pruebas/probar_filtros_catalogo_sin_mysql.php
// Se ejecuta una copia idéntica del catálogo y sus consultas, con PDO SQLite
// en memoria. No se carga .env ni la conexión real de MySQL.
$casos = [
    'completo' => [[], ['Brocha', 'Martillo de goma', 'Martillo de prueba']],
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
$directorios = [$raiz, "$raiz/config", "$raiz/public", "$raiz/app", "$raiz/app/Vistas", "$raiz/app/Vistas/plantillas"];

function comprobar_filtro(bool $condicion, string $mensaje): void
{
    if (!$condicion) { throw new RuntimeException($mensaje); }
}

try {
    foreach ($directorios as $directorio) { mkdir($directorio); }
    foreach (['public/catalogo.php', 'app/Vistas/plantillas/cabecera_publica.php', 'app/Vistas/plantillas/pie_publico.php'] as $archivo) {
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
    $pdo->exec('CREATE TABLE productos (id_producto INTEGER PRIMARY KEY, id_categoria INTEGER, nombre TEXT, descripcion TEXT, imagen TEXT, visible INTEGER)');
    if ($caso !== 'vacio') {
        $pdo->exec("INSERT INTO categorias VALUES (1, 'Herramientas'), (2, 'Acabados'), (3, 'Solo ocultos'), (4, 'Sin productos')");
        $insertar = $pdo->prepare('INSERT INTO categorias VALUES (5, :nombre)');
        $insertar->execute(['nombre' => 'Pinturas & decoración "hogar" <prueba>']);
        $pdo->exec("INSERT INTO productos VALUES
            (1, 1, 'Martillo de prueba', 'Dato ficticio', NULL, 1),
            (2, 2, 'Martillo de goma', 'Dato ficticio', NULL, 1),
            (3, 2, 'Brocha', 'Dato ficticio', NULL, 1),
            (4, 3, 'Oculto', 'No debe publicarse', NULL, 0)");
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
    $oculto = $xpath->query('//form[@id="filtros"]/input[@name="buscar" and @type="hidden"]')->item(0);
    comprobar_filtro($oculto instanceof DOMElement && $oculto->getAttribute('value') === $buscar, 'Se perdió la búsqueda al filtrar');
    comprobar_filtro($xpath->query('//input[@id="busqueda_cabecera"]')->item(0)->getAttribute('value') === $buscar, 'El encabezado perdió la búsqueda');
    $categoria = is_string($entrada['categoria'] ?? '') ? trim($entrada['categoria'] ?? '') : '';
    if ($categoria !== '' && $caso !== 'inyeccion') {
        $opcion = $xpath->query('//select[@name="categoria"]/option[@selected]')->item(0);
        comprobar_filtro($opcion instanceof DOMElement && $opcion->getAttribute('value') === $categoria, 'Se perdió la categoría seleccionada');
    }
    $categoria_buscador = $xpath->query('//form[@role="search"]/input[@name="categoria"]')->item(0);
    comprobar_filtro($categoria === '' ? $categoria_buscador === null : $categoria_buscador instanceof DOMElement && $categoria_buscador->getAttribute('value') === $categoria, 'El buscador debe conservar la categoría activa');
    comprobar_filtro($xpath->query('//a[@class="catalogo__limpiar"]')->item(0)->getAttribute('href') === 'catalogo.php', 'Limpiar debe quitar todos los parámetros');
    if ($caso !== 'vacio' && $caso !== 'error') {
        comprobar_filtro($xpath->query('//select[@name="categoria"]/option')->length === 6, 'Deben ofrecerse todas las categorías, incluso las vacías');
    }
    comprobar_filtro(!str_contains($html, 'No debe publicarse'), 'Se publicó un producto oculto');
    comprobar_filtro(!str_contains($html, '<prueba>'), 'Falta escape en el nombre de categoría');
    comprobar_filtro(!str_contains($html, '<script>alert(1)</script>'), 'Falta escape HTML');
    if ($caso === 'vacio') {
        comprobar_filtro(str_contains($html, 'Estamos preparando nuestro catálogo'), 'Estado vacío incorrecto');
    } elseif ($caso === 'error') {
        comprobar_filtro(str_contains($html, 'No pudimos cargar el catálogo') && !str_contains($html, 'SQLSTATE'), 'Error interno expuesto');
    } elseif ($esperados === [] && $categoria !== '' && $buscar === '') {
        comprobar_filtro(str_contains($html, 'No hay productos publicados en esta categoría'), 'Falta explicación de categoría sin productos');
    } elseif ($esperados === []) {
        comprobar_filtro(str_contains($html, 'No encontramos coincidencias'), 'Falta estado sin coincidencias');
    }
    // Captura opcional para revisión visual, en una ubicación temporal explícita.
    if (($salidaHtml = getenv('CRUCE_PRUEBA_HTML')) !== false && $caso === 'combinados') {
        file_put_contents($salidaHtml, str_replace('<head>', '<head><base href="http://localhost/pagina_web_ferreteria/public/">', $html));
    }
    echo "OK: $caso\n";
} finally {
    foreach (array_reverse($archivos) as $archivo) { if (is_file($archivo)) { unlink($archivo); } }
    foreach (array_reverse($directorios) as $directorio) { if (is_dir($directorio)) { rmdir($directorio); } }
}

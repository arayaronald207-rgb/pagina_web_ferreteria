<?php

declare(strict_types=1);

// Lector acotado al reporte tabular XLSX, sin extracción ZIP ni evaluación de fórmulas.
const MAX_EXCEL_BYTES = 10 * 1024 * 1024;
const MAX_EXCEL_FILAS = 10000;
const NS_EXCEL = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

function xml_inventario(string $texto): DOMDocument
{
    if (preg_match('/<!DOCTYPE|<!ENTITY/i', $texto)) { throw new InvalidArgumentException('El Excel contiene declaraciones XML no permitidas.'); }
    $anterior = libxml_use_internal_errors(true);
    try {
        $doc = new DOMDocument();
        if (!$doc->loadXML($texto, LIBXML_NONET | LIBXML_COMPACT)) { throw new InvalidArgumentException('El contenido XML del Excel no es válido.'); }
        return $doc;
    } finally { libxml_clear_errors(); libxml_use_internal_errors($anterior); }
}

function leer_inventario_xlsx(string $ruta): array
{
    if (!is_file($ruta) || filesize($ruta) > MAX_EXCEL_BYTES || filesize($ruta) < 4
        || file_get_contents($ruta, false, null, 0, 4) !== "PK\x03\x04") {
        throw new InvalidArgumentException('Selecciona un archivo XLSX válido de hasta 10 MB.');
    }
    try { $zip = new PharData($ruta); }
    catch (Throwable $error) { throw new InvalidArgumentException('El archivo no es un libro XLSX válido.'); }
    $total = 0; $entradas = 0;
    foreach (new RecursiveIteratorIterator($zip) as $archivo) {
        if (++$entradas > 200 || $archivo->getSize() > 20 * 1024 * 1024 || ($total += $archivo->getSize()) > 40 * 1024 * 1024) {
            throw new InvalidArgumentException('El contenido descomprimido del Excel supera el límite permitido.');
        }
        if (preg_match('~vbaProject|externalLinks|\.\.[/\\\\]~i', $archivo->getPathname())) {
            throw new InvalidArgumentException('No se permiten macros ni enlaces externos en el Excel.');
        }
    }
    $leer = function (string $nombre) use ($zip): string {
        if (!isset($zip[$nombre]) || $zip[$nombre]->isDir()) { throw new InvalidArgumentException('Falta una parte necesaria del libro XLSX.'); }
        $contenido = $zip[$nombre]->getContent();
        if (strlen($contenido) > 20 * 1024 * 1024) { throw new InvalidArgumentException('Contenido Excel demasiado grande.'); }
        return $contenido;
    };
    $tipos = xml_inventario($leer('[Content_Types].xml'));
    $esLibro = false;
    foreach ($tipos->getElementsByTagName('Override') as $tipo) {
        if (str_contains($tipo->getAttribute('ContentType'), 'macroEnabled')) { throw new InvalidArgumentException('No se permiten libros con macros.'); }
        if ($tipo->getAttribute('PartName') === '/xl/workbook.xml'
            && $tipo->getAttribute('ContentType') === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml') { $esLibro = true; }
    }
    if (!$esLibro) { throw new InvalidArgumentException('El archivo no tiene formato XLSX de hoja de cálculo.'); }
    $libro = xml_inventario($leer('xl/workbook.xml'));
    $hojas = $libro->getElementsByTagNameNS(NS_EXCEL, 'sheet');
    if ($hojas->length !== 1) { throw new InvalidArgumentException('Utiliza un libro con una sola hoja de inventario.'); }
    $hoja = $hojas->item(0);
    $relId = $hoja->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
    $destino = '';
    foreach (xml_inventario($leer('xl/_rels/workbook.xml.rels'))->getElementsByTagName('Relationship') as $rel) {
        if ($rel->getAttribute('TargetMode') === 'External') { throw new InvalidArgumentException('No se permiten referencias externas.'); }
        if ($rel->getAttribute('Id') === $relId) { $destino = $rel->getAttribute('Target'); }
    }
    $destino = str_starts_with($destino, '/') ? ltrim($destino, '/') : 'xl/' . $destino;
    if (!preg_match('~\Axl/worksheets/[A-Za-z0-9_.-]+\.xml\z~', $destino)) { throw new InvalidArgumentException('La ruta de la hoja no es válida.'); }
    $cadenas = [];
    if (isset($zip['xl/sharedStrings.xml'])) {
        $doc = xml_inventario($leer('xl/sharedStrings.xml'));
        foreach ($doc->getElementsByTagNameNS(NS_EXCEL, 'si') as $si) {
            $texto = '';
            foreach ($si->getElementsByTagNameNS(NS_EXCEL, 't') as $t) { $texto .= $t->textContent; }
            if (count($cadenas) >= 150000 || strlen($texto) > 20000) { throw new InvalidArgumentException('El Excel contiene demasiados textos o textos demasiado largos.'); }
            $cadenas[] = $texto;
        }
        unset($doc);
    }
    $xml = $leer($destino);
    if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) { throw new InvalidArgumentException('La hoja contiene XML no permitido.'); }
    $lector = new XMLReader();
    $anterior = libxml_use_internal_errors(true);
    $filas = []; $encabezado = []; $nodos = 0; $filaAnterior = 0;
    try {
        if (!$lector->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) { throw new InvalidArgumentException('No se pudo leer la hoja.'); }
        $lector->setParserProperty(XMLReader::LOADDTD, false);
        $lector->setParserProperty(XMLReader::SUBST_ENTITIES, false);
        while ($lector->read()) {
            if ($lector->nodeType !== XMLReader::ELEMENT || $lector->localName !== 'row') { continue; }
            if (++$nodos > MAX_EXCEL_FILAS + 30) { throw new InvalidArgumentException('El archivo supera 10.000 filas de inventario.'); }
            $numero = filter_var($lector->getAttribute('r'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1048576]]);
            if ($numero === false || $numero <= $filaAnterior) { throw new InvalidArgumentException('La numeración de filas del Excel no es válida.'); }
            $filaAnterior = $numero;
            $filaXml = $lector->readOuterXML();
            if (strlen($filaXml) > 100000) { throw new InvalidArgumentException('Una fila supera el tamaño permitido.'); }
            $doc = xml_inventario($filaXml); $celdas = [];
            foreach ($doc->getElementsByTagNameNS(NS_EXCEL, 'c') as $celda) {
                if (!preg_match('/\A([A-Z]{1,2})[1-9][0-9]*\z/', $celda->getAttribute('r'), $coincidencia)) { throw new InvalidArgumentException('Referencia de celda inválida.'); }
                $col = $coincidencia[1]; $tipo = $celda->getAttribute('t');
                if ($encabezado !== [] && !in_array($col, $encabezado, true)) { continue; } // Nunca conservar columnas financieras.
                $valor = $celda->getElementsByTagNameNS(NS_EXCEL, 'v')->item(0)?->textContent ?? '';
                if ($tipo === 's') {
                    if (!ctype_digit($valor) || !array_key_exists((int) $valor, $cadenas)) { throw new InvalidArgumentException('Referencia de texto inválida.'); }
                    $valor = $cadenas[(int) $valor];
                } elseif ($tipo === 'inlineStr') {
                    $valor = '';
                    foreach ($celda->getElementsByTagNameNS(NS_EXCEL, 't') as $t) { $valor .= $t->textContent; }
                }
                $celdas[$col] = ['valor' => $valor, 'texto' => in_array($tipo, ['s', 'inlineStr', 'str'], true),
                    'formula' => $celda->getElementsByTagNameNS(NS_EXCEL, 'f')->length > 0 || $tipo === 'e'];
            }
            if ($encabezado === []) {
                $mapa = [];
                foreach ($celdas as $col => $celda) {
                    $titulo = strtr(mb_strtolower(trim($celda['valor'])), ['ó' => 'o', 'í' => 'i']);
                    if (in_array($titulo, ['codigo', 'descripcion', 'familia'], true)) {
                        if (isset($mapa[$titulo])) { throw new InvalidArgumentException('Hay encabezados repetidos.'); }
                        $mapa[$titulo] = $col;
                    }
                }
                if (isset($mapa['codigo'], $mapa['descripcion'])) { $encabezado = $mapa; }
                elseif ($nodos > 20) { throw new InvalidArgumentException('No se encontraron los encabezados Codigo y Descripcion.'); }
                continue;
            }
            $codigo = $celdas[$encabezado['codigo']] ?? ['valor' => '', 'texto' => true, 'formula' => false];
            $nombre = $celdas[$encabezado['descripcion']] ?? ['valor' => '', 'formula' => false];
            $familia = $celdas[$encabezado['familia'] ?? ''] ?? ['valor' => '', 'formula' => false];
            if ($nombre['valor'] === '' && in_array(trim($codigo['valor']), ['TOTALES', 'TOTAL'], true)) { continue; }
            if ($codigo['valor'] === '' && $nombre['valor'] === '' && $familia['valor'] === '') { continue; }
            if (count($filas) >= MAX_EXCEL_FILAS) { throw new InvalidArgumentException('Máximo 10.000 registros por archivo.'); }
            if (strlen($codigo['valor']) > 1000 || strlen($nombre['valor']) > 5000 || strlen($familia['valor']) > 1000) { throw new InvalidArgumentException('Una celda del inventario es demasiado larga.'); }
            $error = !$codigo['texto'] ? 'El código debe estar almacenado como texto en Excel; no se convertirá automáticamente.' : '';
            if ($codigo['formula'] || $nombre['formula'] || $familia['formula']) { $error = 'No se admiten fórmulas ni errores en los campos importados.'; }
            $filas[] = ['fila' => $numero, 'codigo' => $codigo['valor'], 'original' => $nombre['valor'], 'familia' => $familia['valor'], 'error' => $error];
        }
        if (libxml_get_errors() !== []) { throw new InvalidArgumentException('La hoja XML está incompleta o mal formada.'); }
    } finally { $lector->close(); libxml_clear_errors(); libxml_use_internal_errors($anterior); }
    if ($encabezado === [] || $filas === []) { throw new InvalidArgumentException('No se encontraron registros de inventario.'); }
    return ['hoja' => $hoja->getAttribute('name'), 'filas' => $filas];
}

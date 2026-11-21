"""Catálogo en XAMPP: lecturas reales y lista en un navegador aislado; sin escrituras MySQL."""
import re
import subprocess
from urllib.parse import parse_qs, urlparse, urlencode
from playwright.sync_api import sync_playwright, expect
from probar_lista_solicitud import BASE, ROOT, PHP, CHROME, estado_mysql


def probar_paginacion():
    codigo = r'''<?php
require getcwd().'/app/Servicios/catalogo.php';
function verificar($valor) { if (!$valor) throw new RuntimeException('Paginación incorrecta'); }
foreach ([1,2,7,8,9,100,1000000] as $total) {
    foreach ([1,2,3,4,5,50,$total-2,$total-1,$total] as $actual) {
        $actual = min(max(1,$actual),$total);
        $ventana = paginas_catalogo($actual,$total);
        $numeros = array_values(array_filter($ventana, fn($n) => $n !== null));
        verificar(count($ventana) <= 7 && $numeros[0] === 1 && end($numeros) === $total);
        verificar(in_array($actual,$numeros,true) && count(array_unique($numeros)) === count($numeros));
        $ordenados=$numeros; sort($ordenados); verificar($numeros === $ordenados);
    }
}
$db = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('CREATE TABLE categorias (id_categoria INTEGER, nombre TEXT, visible INTEGER)');
$db->exec('CREATE TABLE productos (id_producto INTEGER, id_categoria INTEGER, codigo TEXT, nombre TEXT, descripcion TEXT, imagen TEXT, visible INTEGER)');
$db->exec("INSERT INTO categorias VALUES (1,'Herramientas & jardín',1),(2,'Oculta',0)");
$q=$db->prepare('INSERT INTO productos VALUES (?, ?, ?, ?, ?, NULL, ?)');
for($i=1;$i<=125;$i++) $q->execute([$i,1,'P'.$i,sprintf('Martillo %03d',$i),'Prueba',1]);
$q->execute([126,2,'OCULTO','Martillo oculto','',1]);
$q->execute([127,1,'NO','Martillo no publicado','',0]);
$ids=[];
for($p=1;$p<=11;$p++) {
    $r=productos_catalogo($db,'Martillo','Herramientas & jardín',$p);
    verificar($r['total']===125 && $r['paginas']===11 && $r['pagina']===$p);
    verificar(count($r['productos'])===($p===11 ? 5 : 12));
    $ids=array_merge($ids,array_column($r['productos'],'id_producto'));
}
verificar(count(array_unique($ids))===125 && $ids===range(1,125));
verificar(productos_catalogo($db,'Martillo',null,999)['pagina']===11);
verificar(productos_catalogo($db,'Martillo',null,-1)['pagina']===1);
verificar(productos_catalogo($db,'inexistente',null)['productos']===[]);
verificar(productos_catalogo($db,'Martillo 001',null)['paginas']===1);
parse_str(parse_url(url_catalogo(['buscar'=>'A & B','categoria'=>'Herramientas & jardín','pagina'=>2]),PHP_URL_QUERY),$f);
verificar($f===['buscar'=>'A & B','categoria'=>'Herramientas & jardín','pagina'=>'2']);
echo 'OK: 12 por página, límites, filtros, visibilidad, sin duplicados y ventanas de hasta un millón de páginas; SQLite en memoria.';
'''
    result = subprocess.run([PHP], input=codigo, text=True, encoding='utf-8', capture_output=True, cwd=ROOT)
    assert result.returncode == 0, result.stderr
    print(result.stdout)


def main():
    probar_paginacion()
    antes = estado_mysql()
    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=CHROME, headless=True)
        try:
            context = browser.new_context(viewport={'width': 1440, 'height': 1000})
            context.route('**/*', lambda route: route.continue_() if route.request.url.startswith(BASE)
                          and (route.request.method == 'GET' or route.request.url == BASE+'solicitud_api.php') else route.abort())
            page = context.new_page()
            errores = []
            page.on('pageerror', lambda error: errores.append(str(error)))
            # HTML del catálogo real con SQLite en memoria; los assets vienen del XAMPP existente.
            vistas = {}
            for numero in (1,2,6,11):
                result = subprocess.run([PHP, str(ROOT/'pruebas/probar_filtros_catalogo_sin_mysql.php'),
                                         '--caso', f'paginacion_{numero}', '--html'],
                                        capture_output=True, text=True, encoding='utf-8', cwd=ROOT, check=True)
                vistas[numero] = result.stdout
            page.route('**/catalogo.php*', lambda route: route.fulfill(content_type='text/html; charset=utf-8',
                       body=vistas[int(parse_qs(urlparse(route.request.url).query).get('pagina',['1'])[0])]))
            assert page.goto(BASE+'catalogo.php').status == 200
            expect(page.locator('.producto')).to_have_count(12)
            expect(page.locator('[aria-current="page"].catalogo__numero')).to_have_text('1')
            expect(page.locator('.catalogo__paso[aria-disabled]')).to_have_text('Anterior')
            assert page.locator('.catalogo__paginas li').count() <= 7
            primeros = page.locator('[data-agregar-solicitud]').evaluate_all('(bs) => bs.map(b => b.dataset.agregarSolicitud)')
            for ancho, columnas in [(320,1),(390,1),(600,2),(768,2),(1024,4),(1440,4)]:
                page.set_viewport_size({'width':ancho,'height':1000})
                if ancho <= 1120:
                    expect(page.locator('#panel-navegacion')).to_be_hidden()
                assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), ancho
                rects = page.locator('.catalogo__rejilla > .col').evaluate_all('(es) => es.map(e => ({top:e.getBoundingClientRect().top,width:e.getBoundingClientRect().width}))')
                assert sum(abs(r['top']-rects[0]['top'])<1 for r in rects)==columnas, (ancho,rects)
                boton = page.locator('[data-agregar-solicitud]').first
                assert boton.bounding_box()['width'] == 48
                expect(boton).to_have_attribute('title','Agregar a solicitud')
                expect(boton).to_have_attribute('aria-label', re.compile('Agregar a solicitud: .+'))
                if ancho in (390,1440):
                    page.screenshot(path=str(ROOT/'tmp'/f'catalogo-actual-{ancho}.png'),full_page=True)
            page.locator('.catalogo__paso[rel="next"]').click()
            expect(page.locator('.catalogo__numero[aria-current]')).to_have_text('2')
            segundos = page.locator('[data-agregar-solicitud]').evaluate_all('(bs) => bs.map(b => b.dataset.agregarSolicitud)')
            assert not set(primeros).intersection(segundos)
            for numero in (6,11):
                page.goto(BASE+'catalogo.php?'+urlencode({'buscar':'Martillo','categoria':'Herramientas','pagina':numero}))
                expect(page.locator('.catalogo__numero[aria-current]')).to_have_text(str(numero))
                expect(page.locator('.catalogo__elipsis')).to_have_count(2 if numero==6 else 1)
                for href in page.locator('.catalogo__paginacion a').evaluate_all('(as) => as.map(a => a.href)'):
                    query = parse_qs(urlparse(href).query)
                    assert query['categoria']==['Herramientas'] and query['buscar']==['Martillo']
                if numero==6:
                    page.set_viewport_size({'width':320,'height':1000})
                    expect(page.locator('#panel-navegacion')).to_be_hidden()
                    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
                    page.locator('.catalogo__paginacion').screenshot(path=str(ROOT/'tmp'/'catalogo-paginacion.png'))
            expect(page.locator('.catalogo__paso[aria-disabled]')).to_have_text('Siguiente')
            expect(page.locator('.producto')).to_have_count(5)
            page.unroute('**/catalogo.php*')
            page.goto(BASE+'catalogo.php')
            # Los enlaces de detalle conservan página y permiten volver a los resultados.
            page.locator('.producto__detalles').first.click()
            boton = page.locator('[data-agregar-solicitud]')
            expect(boton).to_contain_text('Agregar a solicitud')
            expect(boton.locator('.solicitud-icono-carrito')).to_be_visible()
            boton.click()
            expect(boton).to_have_class(re.compile('solicitud-boton--agregado'))
            expect(page.locator('[data-solicitud-contador]')).to_have_text('1 producto')
            codigo = page.locator('.detalle__codigo strong').inner_text()
            page.goto(BASE+'catalogo.php?'+urlencode({'buscar':codigo}))
            expect(page.locator('.producto')).to_have_count(1)
            expect(page.locator('.catalogo__paginacion')).to_have_count(0)
            boton = page.locator('[data-agregar-solicitud]')
            boton.click()
            expect(boton.locator('.solicitud-icono-listo')).to_be_visible()
            expect(boton.locator('.solicitud-icono-bolsa')).to_be_visible(timeout=4000)
            page.route('**/solicitud_api.php', lambda route: route.abort('failed'))
            boton.click()
            expect(page.locator('#solicitud-notificacion')).to_have_class(re.compile('solicitud-notificacion--error'))
            expect(boton.locator('.solicitud-icono-listo')).to_be_hidden()
            page.unroute('**/solicitud_api.php')
            # Usa una categoría con varios productos; busca una letra de un nombre real.
            page.goto(BASE+'catalogo.php')
            categoria = page.locator('.producto__categoria').first.inner_text()
            nombre = page.locator('.producto h2').first.inner_text()
            filtros = {'categoria':categoria,'buscar':nombre[0]}
            page.goto(BASE+'catalogo.php?'+urlencode(filtros))
            for href in page.locator('.catalogo__paginacion a').evaluate_all('(as) => as.map(a => a.href)'):
                query = parse_qs(urlparse(href).query)
                assert query['categoria']==[categoria] and query['buscar']==[nombre[0]]
            assert not errores, errores
        finally:
            browser.close()
            assert estado_mysql()==antes, 'Los datos reales cambiaron durante la prueba'
    print('OK: catálogo XAMPP, cuadrícula 1/2/4, paginación, filtros, carrito, confirmación y contador. MySQL intacto.')


if __name__ == '__main__':
    main()

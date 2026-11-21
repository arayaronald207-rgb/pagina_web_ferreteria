"""Pruebas del módulo completo en una copia temporal: SQLite, Chrome y sesiones aisladas.
Ejecutar: python pruebas/probar_productos.py (requiere Playwright y Chrome).
Añadir --manual para abrir una copia temporal en el navegador y cerrarla con Enter.
Nunca carga .env ni conecta con MySQL.
"""
import argparse
import base64
from contextlib import closing
import json
import os
from pathlib import Path
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
from urllib.request import urlopen
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which('php') or r'C:\xampp2\php\php.exe'
CHROME = os.environ.get('CRUCE_CHROME', r'C:\Program Files\Google\Chrome\Application\chrome.exe')
ARTIFACTS = Path(tempfile.mkdtemp(prefix='el-cruce-productos-'))


def check(condition, message):
    if not condition:
        raise AssertionError(message)


def run(browser, base, root):
    context = browser.new_context(viewport={'width': 1440, 'height': 900})
    context.route('**/*', lambda r: r.continue_() if r.request.url.startswith(base) else r.abort())
    page = context.new_page()
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    page.goto(base + 'productos.php')
    check(page.url.endswith('iniciar_sesion.php'), 'Productos exige autenticación')
    page.locator('#correo').fill('admin@example.test')
    page.locator('#contrasena').fill('Fixture-Only-123')
    page.locator('.acceso-boton').click()
    page.locator('.panel-navegacion a[href="productos.php"]').click()
    check(page.locator('tbody tr').count() == 20, 'Página limitada a 20 productos')
    page.get_by_role('link', name='Siguiente', exact=True).click()
    check(page.locator('tbody tr').count() == 1, 'Segunda página de productos')

    with closing(sqlite3.connect(root / 'datos.sqlite')) as db:
        def count():
            return db.execute('SELECT COUNT(*) FROM productos').fetchone()[0]

        page.goto(base + 'productos.php?nuevo=1')
        token = page.locator('input[name="token_csrf"]').first.input_value()
        before = count()
        result = context.request.post(base + 'productos.php', form={'accion': 'crear', 'token_csrf': 'incorrecto'})
        check(result.status == 400 and count() == before, 'CSRF rechazado antes de escribir')
        result = context.request.post(base + 'productos.php', form={'accion': 'eliminar', 'id_producto': '2', 'token_csrf': token})
        check(result.status == 422 and count() == before, 'Eliminación requiere confirmación')
        page.goto(base + 'productos.php?eliminar=2')
        check(count() == before, 'GET no elimina')
        page.goto(base + 'productos.php?nuevo=1')
        page.locator('#nombre_producto').fill('Martillo <prueba> & especial')
        page.locator('#codigo_producto').fill('test-001')
        page.locator('#categoria_producto').select_option('1')
        page.locator('#descripcion_producto').fill('<script>alert(1)</script>\nDescripción de prueba')
        page.locator('.panel-producto-formulario button[type="submit"]').click()
        check(page.locator('.alert-success').is_visible(), 'Creación sin foto')
        product_id = db.execute("SELECT id_producto FROM productos WHERE codigo='test-001'").fetchone()[0]
        check(db.execute('SELECT codigo FROM productos WHERE id_producto=?', (product_id,)).fetchone()[0] == 'test-001', 'Conserva exactamente el codigo original')
        check(db.execute('SELECT visible, imagen FROM productos WHERE id_producto=?', (product_id,)).fetchone() == (0, None), 'Nuevo producto oculto, sin imagen')
        page.goto(base + 'catalogo.php?buscar=Martillo')
        check(page.locator('.producto').count() == 0, 'Oculto no aparece en catálogo')

        def edit():
            page.goto(base + f'productos.php?editar={product_id}')

        edit()
        # Fotografías reales generadas por el navegador para los tres formatos aceptados.
        photos = {}
        for extension, mime in [('png', 'image/png'), ('jpg', 'image/jpeg'), ('webp', 'image/webp')]:
            data = page.evaluate('''mime => {const c=document.createElement('canvas'); c.width=32;c.height=24;
                const x=c.getContext('2d');x.fillStyle='#153657';x.fillRect(0,0,32,24);return c.toDataURL(mime);}''', mime)
            photos[extension] = {'name': 'foto.' + extension, 'mimeType': mime, 'buffer': base64.b64decode(data.split(',')[1])}
        previous = None
        for extension in photos:
            edit()
            page.locator('#fotografia_producto').set_input_files(photos[extension])
            page.locator('.panel-producto-formulario button[type="submit"]').click()
            check(page.locator('.alert-success').is_visible(), 'Subida válida ' + extension)
            path = db.execute('SELECT imagen FROM productos WHERE id_producto=?', (product_id,)).fetchone()[0]
            check((root / 'almacenamiento' / path).is_file(), 'Archivo privado almacenado')
            if previous:
                check(not (root / 'almacenamiento' / previous).exists(), 'Fotografía reemplazada retirada')
            previous = path
        edit()
        image_url = page.locator('.panel-producto-preview').get_attribute('src')
        check(context.request.get(base + image_url).status == 200, 'Personal puede ver foto de producto oculto')
        anon = browser.new_context()
        check(anon.request.get(base + image_url).status == 404, 'Visitante no ve foto oculta')

        def post(data=None, photo=None):
            fields = {'accion': 'editar', 'id_producto': str(product_id), 'token_csrf': token,
                      'codigo': 'test-001', 'nombre': 'Martillo <prueba> & especial', 'descripcion': '<script>alert(1)</script>',
                      'id_categoria': '1', 'visible': '0'}
            fields.update(data or {})
            if photo:
                fields['fotografia'] = photo
                return context.request.post(base + 'productos.php', multipart=fields)
            return context.request.post(base + 'productos.php', form=fields)

        files_before = set((root / 'almacenamiento/productos').iterdir())
        for data in ({'codigo': 'SEED-001'}, {'nombre': ''}, {'id_categoria': '9999'}, {'visible': '5'}, {'codigo': 'bad code'}, {'descripcion': 'a' * 5001}):
            check(post(data).status == 422, 'Validación rechaza datos inválidos: ' + str(data)[:60])
        invalid_files = [
            {'name': 'foto.php', 'mimeType': 'image/png', 'buffer': photos['png']['buffer']},
            {'name': 'foto.png', 'mimeType': 'image/png', 'buffer': b'<?php echo "no"; ?>'},
            {'name': 'foto.jpg', 'mimeType': 'image/jpeg', 'buffer': photos['png']['buffer']},
            {'name': 'grande.png', 'mimeType': 'image/png', 'buffer': photos['png']['buffer'] + b'0' * (5 * 1024 * 1024)},
        ]
        for photo in invalid_files:
            check(post(photo=photo).status == 422, 'Subida inválida rechazada: ' + photo['name'])
        check(post({'quitar_foto': '1'}, photos['png']).status == 422, 'Rechaza quitar y subir simultáneamente')
        check(post({'codigo': 'SEED-001'}, photos['png']).status == 422, 'Duplicado con fotografía rechazado')
        check(set((root / 'almacenamiento/productos').iterdir()) == files_before, 'Errores no dejan archivos huérfanos')
        # Un guardado puede fallar después de mover la nueva fotografía al disco.
        db.execute("CREATE TRIGGER fallo_guardado BEFORE UPDATE ON productos BEGIN SELECT RAISE(ABORT, 'fallo temporal de prueba'); END")
        db.commit()
        try:
            response = post(photo=photos['png'])
            check(response.status == 500 and 'fallo temporal de prueba' not in response.text(), 'Error de almacenamiento sin detalles sensibles')
            check(set((root / 'almacenamiento/productos').iterdir()) == files_before, 'Rollback retira la foto nueva y conserva la anterior')
            check(db.execute('SELECT imagen FROM productos WHERE id_producto=?', (product_id,)).fetchone()[0] == previous, 'Rollback conserva referencia anterior')
        finally:
            db.execute('DROP TRIGGER fallo_guardado')
            db.commit()
        check(post({'id_producto': '999999'}).status == 422, 'Editar producto ya eliminado produce validación controlada')
        edit()
        page.locator('#visible_producto').select_option('1')
        page.locator('.panel-producto-formulario button[type="submit"]').click()
        check(db.execute('SELECT imagen FROM productos WHERE id_producto=?', (product_id,)).fetchone()[0] == previous, 'Guardar sin archivo conserva foto')
        page.goto(base + 'catalogo.php?buscar=Martillo')
        check(page.locator('.producto').count() == 1, 'Producto publicado aparece')
        check('<script>alert(1)</script>' not in page.content(), 'Descripción escapada contra XSS')
        response = anon.request.get(base + image_url)
        check(response.status == 200 and response.headers.get('content-type') == 'image/webp' and response.headers.get('x-content-type-options') == 'nosniff', 'Imagen pública con MIME y nosniff')
        check(anon.request.get(base + 'imagen_producto.php?archivo=../config/conexion.php').status == 404, 'Traversal rechazado')

        page.goto(base + 'productos.php?buscar=test-001&categoria=1&estado=1')
        check(page.locator('tbody tr').count() == 1, 'Filtros combinados por código, categoría y estado')
        page.get_by_role('button', name='Cambiar publicación').click()
        page.goto(base + 'catalogo.php?buscar=Martillo')
        check(page.locator('.producto').count() == 0, 'Botón ocultar retira producto')
        edit()
        page.locator('#quitar_foto').check()
        page.locator('.panel-producto-formulario button[type="submit"]').click()
        check(not (root / 'almacenamiento' / previous).exists(), 'Eliminar foto retira archivo')
        edit()
        page.locator('#fotografia_producto').set_input_files(photos['png'])
        page.locator('.panel-producto-formulario button[type="submit"]').click()
        last_photo = db.execute('SELECT imagen FROM productos WHERE id_producto=?', (product_id,)).fetchone()[0]
        page.goto(base + 'productos.php?buscar=test-001')
        page.get_by_role('button', name='Eliminar Martillo').click()
        check(page.locator('.panel-confirmacion').is_visible(), 'Confirmación de eliminación visible')
        page.locator('.panel-confirmacion a').click()
        check(db.execute('SELECT COUNT(*) FROM productos WHERE id_producto=?', (product_id,)).fetchone()[0] == 1, 'Cancelar conserva producto')
        page.get_by_role('button', name='Eliminar Martillo').click()
        page.get_by_role('button', name='Sí, eliminar producto').click()
        check(count() == before and not (root / 'almacenamiento' / last_photo).exists(), 'Eliminar producto retira registro y foto')
        result = context.request.post(base + 'productos.php', form={'accion': 'confirmar_eliminacion', 'id_producto': '1', 'token_csrf': token})
        check(result.status == 422 and count() == before, 'Producto con proforma protegido')
        # También proteger el servicio frente a una confirmación antigua o manipulación del cliente.
        context.request.post(base + 'productos.php', form={'accion': 'publicacion', 'id_producto': '1', 'visible': '0', 'token_csrf': token})
        check(db.execute('SELECT producto_nombre FROM detalle_solicitud WHERE id_producto=1').fetchone()[0] == 'Nombre histórico', 'Ocultar preserva historial de proforma')

        for width in (320, 375, 768, 1024, 1440):
            page.set_viewport_size({'width': width, 'height': 900})
            for path in ('productos.php', 'productos.php?nuevo=1', 'productos.php?editar=1'):
                page.goto(base + path)
                check(page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), f'Diseño sin desbordamiento a {width}')
                page.locator('img').evaluate_all('(els) => els.forEach(e => e.loading = "eager")')
                page.wait_for_function('[...document.images].every(e => e.complete && e.naturalWidth > 0)')
            if width in (375, 1440):
                page.screenshot(path=str(ARTIFACTS / f'{width}-productos.png'), full_page=True)
        print('OK CRUD, filtros, paginación, publicación, proformas, fotografías, CSRF, XSS y responsive', flush=True)

        # El empleado utiliza exactamente el mismo control de acceso existente.
        page.locator('form[action="cerrar_sesion.php"] button').click()
        page.locator('#correo').fill('empleado@example.test')
        page.locator('#contrasena').fill('Fixture-Only-123')
        page.locator('.acceso-boton').click()
        page.goto(base + 'productos.php')
        check(page.locator('h1').inner_text() == 'Productos', 'Empleado autorizado accede')
        token = page.locator('input[name="token_csrf"]').first.input_value()
        result = context.request.post(base + 'productos.php', form={'accion': 'publicacion', 'id_producto': '2', 'visible': '1', 'token_csrf': token})
        check(result.ok, 'Empleado autorizado administra productos')
        db.execute('UPDATE usuarios SET activo=0 WHERE id_usuario=2')
        db.commit()
        page.goto(base + 'productos.php')
        check(page.url.endswith('iniciar_sesion.php'), 'Cuenta desactivada pierde acceso')
        check(not errors, 'Errores JavaScript: ' + repr(errors))
        anon.close()
        context.close()
        db.close()
        print('OK roles y revocación de acceso', flush=True)


def run_empty_and_pending(browser, base, root):
    """Solo modifica el archivo SQLite de la copia temporal creada por main()."""
    context = browser.new_context()
    context.route('**/*', lambda r: r.continue_() if r.request.url.startswith(base) else r.abort())
    page = context.new_page()
    page.goto(base + 'iniciar_sesion.php')
    page.locator('#correo').fill('admin@example.test')
    page.locator('#contrasena').fill('Fixture-Only-123')
    page.locator('.acceso-boton').click()
    with closing(sqlite3.connect(root / 'datos.sqlite')) as db:
        db.execute('PRAGMA foreign_keys = ON')
        db.execute('DELETE FROM detalle_solicitud')
        db.execute('DELETE FROM productos')
        db.execute('DELETE FROM categorias')
        db.commit()
        page.goto(base + 'productos.php?nuevo=1')
        check(page.locator('.panel-vacio').is_visible(), 'Listado vacío explicado')
        check(page.locator('.panel-producto-formulario .alert-warning').is_visible(), 'Falta de categorías explicada')
        check(page.locator('.panel-producto-formulario button[type="submit"]').is_disabled(), 'No registrar sin categorías')
        page.goto(base + 'catalogo.php')
        check(page.locator('.producto').count() == 0, 'Catálogo vacío funciona')
        check('Aún no hay categorías registradas.' in page.content(), 'Megamenú sin categorías explicado')
        # Simula un esquema anterior; nunca ejecuta la migración MySQL preparada.
        db.execute('ALTER TABLE productos RENAME COLUMN codigo TO codigo_pendiente')
        db.commit()
        response = page.goto(base + 'productos.php')
        check(response.status == 503, 'Migración pendiente produce indisponibilidad controlada')
        check(page.locator('.alert-danger').is_visible() and page.locator('form[action="productos.php"]').count() == 0, 'Sin esquema no se ofrecen escrituras')
        for path in ('administracion.php', 'categorias.php', 'catalogo.php', 'index.php'):
            check(page.goto(base + path).status == 200, 'Esquema anterior conserva ' + path)
    context.close()
    print('OK estados vacíos y módulo pendiente de migración; resto del sitio operativo', flush=True)


def main(visibilidad_categorias=False):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--manual', action='store_true', help='Mantener la copia temporal abierta para pruebas manuales')
    manual = parser.parse_args().manual
    with tempfile.TemporaryDirectory(prefix='el-cruce-productos-fixture-') as directory:
        root = Path(directory)
        shutil.copytree(ROOT / 'public', root / 'public')
        shutil.copytree(ROOT / 'app', root / 'app')
        (root / 'config').mkdir()
        (root / 'sesiones').mkdir()
        conexion_temporal = '''<?php
function obtener_conexion(): PDO {
    static $db;
    if ($db instanceof PDO) return $db;
    $archivo = dirname(__DIR__) . '/datos.sqlite';
    $nuevo = !is_file($archivo);
    $db = new PDO('sqlite:' . $archivo, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys = ON');
    if ($nuevo) {
        $db->exec('CREATE TABLE usuarios (id_usuario INTEGER PRIMARY KEY, nombre TEXT, correo TEXT, clave_hash TEXT, rol TEXT, activo INTEGER)');
        $q=$db->prepare('INSERT INTO usuarios VALUES (?, ?, ?, ?, ?, 1)');
        $hash=password_hash('Fixture-Only-123', PASSWORD_DEFAULT);
        $q->execute([1,'Administrador de prueba','admin@example.test',$hash,'administrador']);
        $q->execute([2,'Empleado de prueba','empleado@example.test',$hash,'empleado']);
        $db->exec('CREATE TABLE categorias (id_categoria INTEGER PRIMARY KEY, nombre TEXT)');
        $db->exec("INSERT INTO categorias (id_categoria,nombre) VALUES (1,'Herramientas'), (2,'Pinturas')");
        $db->exec('CREATE TABLE productos (id_producto INTEGER PRIMARY KEY AUTOINCREMENT, codigo TEXT NOT NULL UNIQUE COLLATE NOCASE, nombre TEXT, descripcion TEXT, id_categoria INTEGER REFERENCES categorias, imagen TEXT, visible INTEGER)');
        $q=$db->prepare('INSERT INTO productos (codigo,nombre,descripcion,id_categoria,imagen,visible) VALUES (?,?,?,1,NULL,1)');
        for ($i=1;$i<=21;$i++) $q->execute(['SEED-'.str_pad((string)$i,3,'0',STR_PAD_LEFT),'Artículo '.str_pad((string)$i,3,'0',STR_PAD_LEFT),'Ficticio']);
        $db->exec('CREATE TABLE solicitudes (id_solicitud INTEGER PRIMARY KEY, estado TEXT)');
        $db->exec("INSERT INTO solicitudes VALUES (1,'Pendiente')");
        $db->exec('CREATE TABLE detalle_solicitud (id_producto INTEGER REFERENCES productos, id_solicitud INTEGER REFERENCES solicitudes, producto_nombre TEXT, cantidad INTEGER)');
        $db->exec("INSERT INTO detalle_solicitud VALUES (1,1,'Nombre histórico',2)");
    }
    return $db;
}
'''
        if visibilidad_categorias:
            conexion_temporal = conexion_temporal.replace('CREATE TABLE categorias (id_categoria INTEGER PRIMARY KEY, nombre TEXT)',
                'CREATE TABLE categorias (id_categoria INTEGER PRIMARY KEY, nombre TEXT, visible INTEGER NOT NULL DEFAULT 1 CHECK (visible IN (0,1)))')
        (root / 'config/conexion.php').write_text(conexion_temporal, encoding='utf-8')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        base = f'http://127.0.0.1:{port}/'
        with (ARTIFACTS / 'php.log').open('w') as log:
            server = subprocess.Popen([PHP, '-d', 'session.save_path="' + (root / 'sesiones').as_posix() + '"',
                                       '-d', 'sys_temp_dir="' + root.as_posix() + '"',
                                       '-d', 'upload_tmp_dir="' + root.as_posix() + '"',
                                       '-d', 'upload_max_filesize=6M', '-d', 'post_max_size=8M',
                                       '-S', f'127.0.0.1:{port}', '-t', str(root / 'public')], stdout=log, stderr=log)
            try:
                for attempt in range(50):
                    try:
                        with urlopen(base + 'index.php', timeout=1):
                            break
                    except OSError:
                        time.sleep(.1)
                else:
                    raise RuntimeError('No inició el servidor PHP temporal')
                if manual:
                    print('Copia aislada con 21 productos ficticios. MySQL y .env no se utilizan.', flush=True)
                    print('Navegador: ' + base + 'productos.php', flush=True)
                    print('Administrador: admin@example.test | Empleado: empleado@example.test', flush=True)
                    print('Contraseña temporal de ambas cuentas: Fixture-Only-123', flush=True)
                    input('Pulsa Enter para cerrar el servidor y eliminar la copia temporal.\n')
                    return
                with sync_playwright() as pw:
                    browser = pw.chromium.launch(executable_path=CHROME, headless=True)
                    try:
                        run(browser, base, root)
                        run_empty_and_pending(browser, base, root)
                    finally:
                        browser.close()
            finally:
                server.terminate()
                server.wait(timeout=10)
        php_log = (ARTIFACTS / 'php.log').read_text(encoding='utf-8', errors='replace')
        check(not any(message in php_log for message in ('PHP Warning:', 'PHP Fatal error:', 'PHP Parse error:', 'PHP Notice:', 'PHP Deprecated:')), 'Errores PHP: revisar ' + str(ARTIFACTS / 'php.log'))
        print('OK registro PHP sin warnings, errores fatales ni avisos', flush=True)
    print(json.dumps({'capturas': str(ARTIFACTS), 'mysql': 'no utilizado'}, ensure_ascii=False))


if __name__ == '__main__':
    main()

(() => {
    'use strict';
    const base = new URL('../../', document.currentScript.src);
    const clave = `el-cruce:solicitud:v1:${base.pathname}`;
    const el = id => document.getElementById(id);
    const vista = el('mi-solicitud');
    const paginaRegistro = document.querySelector('[data-registro-solicitud]');
    let productos = [], ocupado = false, verificada = false, hayGuardado = false;
    let cola = Promise.resolve(), avisoTimer, cambiosPendientes = 0;
    const confirmaciones = new WeakMap();
    const confirmarAgregado = boton => {
        clearTimeout(confirmaciones.get(boton));
        boton.classList.add('solicitud-boton--agregado');
        confirmaciones.set(boton, setTimeout(() => boton.classList.remove('solicitud-boton--agregado'), 2200));
    };

    const avisar = (texto, error = false) => {
        const aviso = el('solicitud-notificacion');
        clearTimeout(avisoTimer);
        aviso.textContent = texto;
        aviso.classList.toggle('solicitud-notificacion--error', error);
        aviso.hidden = !texto;
        if (texto && !error) avisoTimer = setTimeout(() => { aviso.hidden = true; }, 6500);
    };

    const leer = () => {
        let texto;
        try { texto = localStorage.getItem(clave); }
        catch (_) { throw new Error('El navegador no permite guardar la lista. Habilita el almacenamiento del sitio para utilizarla.'); }
        hayGuardado = Boolean(texto);
        if (!texto) return [];
        try {
            if (texto.length > 16000) throw new Error();
            const datos = JSON.parse(texto);
            if (datos?.version !== 1 || !Array.isArray(datos.productos) || datos.productos.length > 100) throw new Error();
            if (!datos.productos.every(p => p && Object.keys(p).length === 2
                && Number.isInteger(p.id_producto) && p.id_producto > 0 && p.id_producto <= 2147483647
                && Number.isInteger(p.cantidad) && p.cantidad > 0 && p.cantidad <= 999)) throw new Error();
            hayGuardado = datos.productos.length > 0;
            return datos.productos;
        } catch (_) {
            throw new Error('La lista guardada no es válida. Puedes vaciarla desde Mi solicitud para comenzar de nuevo.');
        }
    };

    const guardar = (lista, recibo = 0) => {
        // Persistir únicamente IDs y cantidades; los nombres, códigos e imágenes proceden del servidor.
        try {
            let anterior;
            try { anterior = JSON.parse(localStorage.getItem(clave)); } catch (_) { anterior = null; }
            const recibos = Array.isArray(anterior?.solicitudesConfirmadas)
                ? anterior.solicitudesConfirmadas.filter(id => Number.isSafeInteger(id) && id > 0).slice(-100) : [];
            const datos = {version: 1, productos: lista.map(p => ({id_producto: p.id_producto, cantidad: p.cantidad}))};
            if (recibo && !recibos.includes(recibo)) recibos.push(recibo);
            if (recibos.length) datos.solicitudesConfirmadas = recibos.slice(-100);
            const texto = JSON.stringify(datos);
            // El recibo se guarda atómicamente con la lista para no limpiarla dos veces al recargar.
            if (!lista.length && !datos.solicitudesConfirmadas) localStorage.removeItem(clave);
            else if (localStorage.getItem(clave) !== texto) localStorage.setItem(clave, texto);
        } catch (_) { throw new Error('No se pudo guardar el cambio en este navegador. Revisa el almacenamiento e inténtalo de nuevo.'); }
        hayGuardado = lista.length > 0;
    };

    const consultar = async lista => {
        if (!lista.length) return {productos: [], retirados: []};
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch(new URL('solicitud_api.php', base), {
                method: 'POST', credentials: 'omit', redirect: 'error', signal: controller.signal,
                headers: {'Content-Type': 'application/json'}, body: JSON.stringify({productos: lista})
            });
            let datos;
            try { datos = await response.json(); }
            catch (_) { throw new Error('No se pudo verificar la lista. Inténtalo nuevamente; tu lista guardada se conserva.'); }
            if (!response.ok) throw new Error(datos.error || 'No se pudo verificar la lista.');
            if (!Array.isArray(datos.productos) || !Array.isArray(datos.retirados)) throw new Error('La respuesta del servidor no es válida.');
            return datos;
        } catch (error) {
            if (error.name === 'AbortError' || error instanceof TypeError) {
                throw new Error('No pudimos conectar para verificar la lista. Tu lista guardada se conserva; vuelve a intentarlo.');
            }
            throw error;
        } finally { clearTimeout(timer); }
    };

    const nodo = (tag, clase, texto) => {
        const elemento = document.createElement(tag);
        if (clase) elemento.className = clase;
        if (texto !== undefined) elemento.textContent = texto;
        return elemento;
    };
    const fila = producto => {
        const articulo = nodo('article', 'solicitud__producto');
        const imagen = nodo('img', 'solicitud__foto');
        imagen.src = new URL(producto.imagen, base).href;
        imagen.alt = producto.nombre;
        imagen.width = 96; imagen.height = 96; imagen.loading = 'lazy';
        const informacion = nodo('div', 'solicitud__informacion');
        const titulo = nodo('h2');
        const enlace = nodo('a', '', producto.nombre);
        enlace.href = new URL(`producto.php?id=${producto.id_producto}`, base).href;
        titulo.append(enlace);
        informacion.append(titulo, nodo('p', 'solicitud__codigo', `Código: ${producto.codigo}`));
        const cantidad = nodo('div', 'solicitud__cantidad');
        const input = nodo('input', 'form-control');
        input.type = 'number'; input.min = '1'; input.max = '999'; input.step = '1';
        input.inputMode = 'numeric'; input.required = true; input.value = producto.cantidad;
        input.id = `cantidad-solicitud-${producto.id_producto}`;
        input.dataset.cantidadSolicitud = producto.id_producto;
        const label = nodo('label', '', 'Cantidad');
        label.htmlFor = input.id;
        input.setAttribute('aria-label', `Cantidad de ${producto.nombre} (${producto.codigo})`);
        cantidad.append(label, input);
        const quitar = nodo('button', 'solicitud__quitar', 'Eliminar');
        quitar.type = 'button'; quitar.dataset.quitarSolicitud = producto.id_producto;
        quitar.setAttribute('aria-label', `Eliminar ${producto.nombre} de la solicitud`);
        articulo.append(imagen, informacion, cantidad, quitar);
        return articulo;
    };

    const controles = () => {
        document.querySelectorAll('[data-agregar-solicitud]').forEach(b => { b.disabled = ocupado; });
        if (!vista) return;
        vista.setAttribute('aria-busy', String(ocupado));
        vista.querySelectorAll('[data-cantidad-solicitud], [data-quitar-solicitud]').forEach(e => { e.disabled = ocupado || !verificada; });
        el('solicitud-reintentar').disabled = ocupado;
        el('solicitud-vaciar').disabled = ocupado || !hayGuardado;
        el('solicitud-continuar').disabled = ocupado || !verificada || !productos.length;
    };
    const pintar = () => {
        document.querySelectorAll('[data-solicitud-contador]').forEach(contador => {
            contador.textContent = verificada ? `${productos.length} ${productos.length === 1 ? 'producto' : 'productos'}` : 'Revisar lista';
        });
        document.querySelectorAll('[data-solicitud-insignia]').forEach(insignia => {
            insignia.textContent = productos.length;
            insignia.hidden = !verificada || !productos.length;
        });
        if (vista) {
            const foco = document.activeElement?.dataset.cantidadSolicitud;
            el('solicitud-cargando').hidden = true;
            el('solicitud-vacia').hidden = !verificada || productos.length > 0;
            el('solicitud-resumen').textContent = verificada && productos.length
                ? `${productos.length} ${productos.length === 1 ? 'producto seleccionado' : 'productos distintos seleccionados'}` : '';
            el('solicitud-productos').replaceChildren(...(verificada ? productos.map(fila) : []));
            if (foco) el(`cantidad-solicitud-${foco}`)?.focus({preventScroll: true});
        }
        controles();
    };

    const ejecutar = (transformar = lista => lista, opciones = {}) => {
        if (opciones.mensaje) cambiosPendientes++;
        cola = cola.then(async () => {
            avisar('');
            ocupado = true; controles();
            if (vista) el('solicitud-error').hidden = true;
            const operacion = async () => {
                const lista = opciones.vaciar ? [] : transformar(leer());
                const resultado = await consultar(lista);
                if (!paginaRegistro) guardar(resultado.productos);
                productos = resultado.productos;
                verificada = true;
                const noAgregado = opciones.agregado && resultado.retirados.includes(opciones.agregado);
                if (opciones.boton && !noAgregado) confirmarAgregado(opciones.boton);
                let mensaje = noAgregado ? 'Este producto ya no está disponible públicamente y no se agregó.' : opciones.mensaje || '';
                if (resultado.retirados.length) mensaje += `${mensaje ? ' ' : ''}Se retiraron ${resultado.retirados.length} productos que ya no están disponibles públicamente.`;
                if (mensaje) avisar(mensaje, Boolean(noAgregado));
            };
            try {
                // Serializar las modificaciones entre pestañas cuando el navegador dispone de Web Locks.
                if (navigator.locks?.request) await navigator.locks.request(clave, operacion);
                else await operacion();
            } catch (error) {
                verificada = false;
                const mensaje = error.message || 'No se pudo verificar tu lista. Inténtalo nuevamente.';
                avisar(mensaje, true);
                if (vista) { el('solicitud-error-texto').textContent = mensaje; el('solicitud-error').hidden = false; }
            } finally {
                if (opciones.mensaje) cambiosPendientes--;
                ocupado = false; pintar();
            }
        });
        return cola;
    };

    document.querySelectorAll('[data-agregar-solicitud]').forEach(boton => {
        boton.addEventListener('click', () => {
            clearTimeout(confirmaciones.get(boton));
            boton.classList.remove('solicitud-boton--agregado');
            const id = Number(boton.dataset.agregarSolicitud);
            ejecutar(lista => {
                const existente = lista.find(p => p.id_producto === id);
                if (existente) {
                    if (existente.cantidad >= 999) throw new Error('La cantidad máxima por producto es 999.');
                    existente.cantidad++;
                } else {
                    if (lista.length >= 100) throw new Error('La lista admite hasta 100 productos distintos. Revisa Mi solicitud antes de agregar otro.');
                    lista.push({id_producto: id, cantidad: 1});
                }
                return lista;
            }, {agregado: id, boton, mensaje: 'Producto agregado a Mi solicitud. Puedes revisar la lista desde la navegación.'});
        });
    });

    if (vista) {
        el('solicitud-preparar').addEventListener('submit', evento => {
            if (ocupado || !verificada || !productos.length) { evento.preventDefault(); return; }
            el('solicitud-lista-envio').value = JSON.stringify(productos.map(p => ({id_producto: p.id_producto, cantidad: p.cantidad})));
            el('solicitud-continuar').disabled = true;
        });
        el('solicitud-productos').addEventListener('change', evento => {
            const input = evento.target.closest('[data-cantidad-solicitud]');
            if (!input) return;
            const id = Number(input.dataset.cantidadSolicitud);
            if (!/^[1-9][0-9]{0,2}$/.test(input.value)) {
                input.value = productos.find(p => p.id_producto === id)?.cantidad ?? 1;
                avisar('Utiliza una cantidad entera entre 1 y 999. Se conservó la cantidad anterior.', true);
                return;
            }
            const cantidad = Number(input.value);
            ejecutar(lista => lista.map(p => p.id_producto === id ? {...p, cantidad} : p), {mensaje: 'Cantidad actualizada.'});
        });
        el('solicitud-productos').addEventListener('click', evento => {
            const boton = evento.target.closest('[data-quitar-solicitud]');
            if (!boton) return;
            const id = Number(boton.dataset.quitarSolicitud);
            const producto = productos.find(p => p.id_producto === id);
            if (!producto || !window.confirm(`¿Eliminar ${producto.nombre} de tu lista?`)) return;
            ejecutar(lista => lista.filter(p => p.id_producto !== id), {mensaje: 'Producto eliminado de la lista.'});
        });
        el('solicitud-vaciar').addEventListener('click', () => {
            if (window.confirm('¿Vaciar tu lista? Se eliminarán todos los productos seleccionados en este navegador.')) {
                ejecutar(undefined, {vaciar: true, mensaje: 'Lista vaciada.'});
            }
        });
        el('solicitud-reintentar').addEventListener('click', () => ejecutar());
    }
    window.addEventListener('storage', evento => { if (evento.key === clave || evento.key === null) ejecutar(); });
    window.addEventListener('pageshow', evento => { if (evento.persisted) ejecutar(); });
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') ejecutar(); });
    window.addEventListener('beforeunload', evento => {
        if (cambiosPendientes > 0) { evento.preventDefault(); evento.returnValue = ''; }
    });
    const confirmacion = el('solicitud-confirmada');
    if (confirmacion) {
        const limpiarRegistrados = async () => {
            try {
                const recibo = JSON.parse(confirmacion.textContent);
                const limpiar = () => {
                    const lista = leer();
                    const anterior = JSON.parse(localStorage.getItem(clave));
                    if (anterior?.solicitudesConfirmadas?.includes(recibo.id)) return;
                    // Conserva productos o cantidades agregados desde otra pestaña después del resumen.
                    const enviados = new Map(recibo.productos.map(p => [p.id_producto, p.cantidad]));
                    const restantes = lista.map(p => ({...p, cantidad: p.cantidad - (enviados.get(p.id_producto) || 0)})).filter(p => p.cantidad > 0);
                    guardar(restantes, recibo.id);
                };
                if (navigator.locks?.request) await navigator.locks.request(clave, limpiar);
                else limpiar();
                el('solicitud-limpieza').textContent = 'Los productos registrados se retiraron de tu lista. Si agregaste otros productos mientras completabas el formulario, se conservan.';
            } catch (_) {
                el('solicitud-limpieza').textContent = 'Tu solicitud está registrada, pero no pudimos actualizar la lista del navegador. Habilita el almacenamiento y recarga esta confirmación; no vuelvas a enviarla.';
            }
            ejecutar();
        };
        limpiarRegistrados();
    } else ejecutar();
})();

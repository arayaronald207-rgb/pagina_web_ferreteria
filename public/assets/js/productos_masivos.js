(() => {
    'use strict';
    const panel = document.querySelector('#gestion-masiva');
    if (!panel) return;
    const el = id => document.getElementById(id);
    const cajas = [...document.querySelectorAll('[data-seleccionar-producto]')];
    const pagina = el('seleccionar-pagina');
    const clave = `productos-seleccion:${panel.dataset.usuario}:${panel.dataset.token}`;
    let seleccion = new Set(), trabajo = null, ocupado = false, pausa = false;
    const error = mensaje => { el('masiva-error').textContent = mensaje; el('masiva-error').hidden = !mensaje; };
    try {
        const ids = JSON.parse(sessionStorage.getItem(clave) || '[]');
        if (Array.isArray(ids) && ids.length <= 10000) seleccion = new Set(ids.filter(id => Number.isSafeInteger(id) && id > 0));
    } catch (_) { error('No se pudo recuperar la selección de esta pestaña. Selecciona los productos nuevamente.'); }
    const guardar = () => {
        try { sessionStorage.setItem(clave, JSON.stringify([...seleccion])); }
        catch (_) { error('El navegador no permite conservar la selección entre páginas. No navegues hasta completar o limpiar la selección.'); }
    };
    const pintar = () => {
        const bloqueado = ocupado || trabajo?.estado === 'procesando';
        cajas.forEach(caja => { caja.checked = seleccion.has(Number(caja.dataset.seleccionarProducto)); caja.disabled = bloqueado; });
        if (pagina) {
            pagina.disabled = bloqueado;
            pagina.checked = cajas.length > 0 && cajas.every(caja => caja.checked);
            pagina.indeterminate = !pagina.checked && cajas.some(caja => caja.checked);
        }
        el('seleccion-cantidad').textContent = `${seleccion.size} productos seleccionados en total (incluye otras páginas y filtros).`;
        ['masiva-operacion', 'masiva-categoria', 'masiva-limpiar'].forEach(id => { el(id).disabled = bloqueado; });
        el('masiva-revisar').disabled = bloqueado || !seleccion.size;
        el('masiva-destino').hidden = el('masiva-operacion').value !== 'categoria';
        el('masiva-previa').hidden = !trabajo;
        if (!trabajo) return;
        const etiquetas = {categoria: 'Cambiar categoría', publicar: 'Publicar', ocultar: 'Ocultar'};
        el('masiva-resumen').textContent = `${etiquetas[trabajo.operacion]}: ${trabajo.total} productos revisados; ${trabajo.cambios_previstos} cambios previstos. ` +
            (trabajo.destino ? `Categoría destino: ${trabajo.destino.nombre}. Publicación: se conserva.` : `Categorías: se conservan. Estado destino: ${trabajo.operacion === 'publicar' ? 'Publicado' : 'Oculto'}.`);
        el('masiva-grupos').replaceChildren(...trabajo.grupos.map(grupo => {
            const fila = document.createElement('tr');
            [grupo.categoria, grupo.visible ? 'Publicado' : 'Oculto', grupo.cantidad].forEach(valor => { const celda = document.createElement('td'); celda.textContent = valor; fila.append(celda); });
            return fila;
        }));
        el('masiva-progreso').max = trabajo.total;
        el('masiva-progreso').value = trabajo.procesados;
        el('masiva-resultado').textContent = `${trabajo.estado === 'terminado' ? 'Operación terminada. ' : ''}${trabajo.procesados} de ${trabajo.total} procesados. Modificados: ${trabajo.modificados}. Ya tenían el valor: ${trabajo.sin_cambios}. Omitidos por cambios o eliminación: ${trabajo.omitidos}.`;
        el('masiva-consentimiento').hidden = trabajo.estado !== 'previa';
        el('masiva-confirmar').hidden = trabajo.estado !== 'previa';
        el('masiva-confirmar').disabled = ocupado || !el('masiva-aceptar').checked;
        el('masiva-continuar').hidden = trabajo.estado !== 'procesando' || ocupado;
        el('masiva-pausar').hidden = trabajo.estado !== 'procesando' || !ocupado;
        el('masiva-cancelar').disabled = ocupado;
    };
    const invalidar = () => { trabajo = null; el('masiva-aceptar').checked = false; guardar(); pintar(); };
    cajas.forEach(caja => caja.addEventListener('change', () => {
        const id = Number(caja.dataset.seleccionarProducto);
        if (caja.checked && seleccion.size >= 10000) { caja.checked = false; error('El máximo es 10.000 productos.'); return; }
        if (caja.checked) seleccion.add(id); else seleccion.delete(id);
        invalidar();
    }));
    pagina?.addEventListener('change', () => {
        if (pagina.checked && new Set([...seleccion, ...cajas.map(caja => Number(caja.dataset.seleccionarProducto))]).size > 10000) { error('El máximo es 10.000 productos.'); pintar(); return; }
        cajas.forEach(caja => { const id = Number(caja.dataset.seleccionarProducto); if (pagina.checked) seleccion.add(id); else seleccion.delete(id); });
        invalidar();
    });
    el('masiva-limpiar').addEventListener('click', () => { seleccion.clear(); invalidar(); });
    ['masiva-operacion', 'masiva-categoria'].forEach(id => el(id).addEventListener('change', invalidar));
    el('masiva-aceptar').addEventListener('change', pintar);
    const api = async datos => {
        const respuesta = await fetch('productos_masivos.php', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': panel.dataset.token}, body: JSON.stringify(datos)});
        if (respuesta.redirected) throw new Error('La sesión venció. Vuelve a iniciar sesión.');
        const resultado = await respuesta.json();
        if (!respuesta.ok) throw new Error(resultado.error || 'No se pudo completar la operación.');
        return resultado.trabajo;
    };
    const ejecutar = async tarea => {
        if (ocupado) return;
        ocupado = true; error(''); pintar();
        try { await tarea(); }
        catch (fallo) { error(fallo.message || 'No se recibió la respuesta. Actualiza el listado para revisar el progreso.'); }
        finally { ocupado = false; pintar(); }
    };
    el('masiva-revisar').addEventListener('click', () => ejecutar(async () => {
        trabajo = await api({accion: 'previa', ids: [...seleccion], operacion: el('masiva-operacion').value, categoria: el('masiva-categoria').value});
        el('masiva-aceptar').checked = false; pintar(); el('masiva-previa-titulo').focus();
    }));
    const lotes = async () => {
        pausa = false;
        while (trabajo?.estado === 'procesando' && !pausa) {
            trabajo = await api({accion: 'lote', trabajo: trabajo.id, inicio: trabajo.procesados}); pintar();
        }
        if (trabajo?.estado === 'terminado') { seleccion.clear(); guardar(); }
    };
    el('masiva-confirmar').addEventListener('click', () => ejecutar(async () => {
        if (!el('masiva-aceptar').checked || trabajo?.estado !== 'previa') return;
        trabajo = await api({accion: 'confirmar', trabajo: trabajo.id, confirmar: true}); pintar(); await lotes();
    }));
    el('masiva-continuar').addEventListener('click', () => ejecutar(lotes));
    el('masiva-pausar').addEventListener('click', () => { pausa = true; });
    el('masiva-cancelar').addEventListener('click', () => ejecutar(async () => { trabajo = await api({accion: 'cancelar', trabajo: trabajo.id}); el('masiva-aceptar').checked = false; }));
    window.addEventListener('beforeunload', evento => { if (ocupado) { evento.preventDefault(); evento.returnValue = ''; } });
    ejecutar(async () => { trabajo = await api({accion: 'estado'}); });
})();

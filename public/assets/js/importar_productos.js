(() => {
    'use strict';
    const seleccion = document.querySelector('#seleccion-importacion');
    const confirmar = document.querySelector('#confirmar-importacion');
    let sinGuardar = false;
    if (seleccion && confirmar) {
        seleccion.addEventListener('change', () => { sinGuardar = true; });
        seleccion.addEventListener('submit', () => { sinGuardar = false; });
        confirmar.addEventListener('submit', evento => {
            if (!sinGuardar) return;
            evento.preventDefault();
            document.querySelector('#exclusiones-pendientes').hidden = false;
            seleccion.querySelector('[type="submit"]').focus();
        });
        window.addEventListener('beforeunload', evento => {
            if (sinGuardar) { evento.preventDefault(); evento.returnValue = ''; }
        });
        let guardando = false;
        const navegar = async destino => {
            if (guardando) return;
            if (!sinGuardar) { window.location.assign(destino); return; }
            guardando = true;
            const datos = new FormData(seleccion);
            const controles = [...seleccion.querySelectorAll('input, button'), ...confirmar.querySelectorAll('button')];
            const estados = controles.map(control => control.disabled);
            controles.forEach(control => { control.disabled = true; });
            seleccion.setAttribute('aria-busy', 'true');
            try {
                const respuesta = await fetch(seleccion.action, {method: 'POST', body: datos});
                if (!respuesta.ok || new URL(respuesta.url).searchParams.get('trabajo') !== datos.get('trabajo')) {
                    throw new Error('No se pudieron guardar las exclusiones. Revisa tu sesión y vuelve a intentarlo antes de navegar.');
                }
                sinGuardar = false;
                window.location.assign(destino);
            } catch (error) {
                const aviso = document.querySelector('#exclusiones-pendientes');
                aviso.textContent = error.message || 'No se pudieron guardar las exclusiones. Vuelve a intentarlo.';
                aviso.hidden = false;
            } finally {
                controles.forEach((control, indice) => { control.disabled = estados[indice]; });
                seleccion.removeAttribute('aria-busy');
                guardando = false;
            }
        };
        document.querySelector('#filtros-importacion').addEventListener('submit', evento => {
            evento.preventDefault();
            const form = evento.currentTarget;
            const destino = new URL(form.action);
            destino.search = new URLSearchParams(new FormData(form)).toString();
            navegar(destino.href);
        });
        document.querySelectorAll('[data-navegar-previa]').forEach(enlace => {
            enlace.addEventListener('click', evento => {
                if (evento.ctrlKey || evento.metaKey || evento.shiftKey || evento.altKey) return;
                evento.preventDefault();
                navegar(enlace.href);
            });
        });
    }
    const form = document.querySelector('#procesar-importacion');
    if (!form) return;
    const boton = form.querySelector('[type="submit"]');
    const pausa = document.querySelector('#pausar-importacion');
    const progreso = document.querySelector('#progreso-importacion');
    const estado = document.querySelector('#estado-importacion');
    let ocupado = false;
    let detener = false;
    pausa.addEventListener('click', () => { detener = true; pausa.disabled = true; });
    form.addEventListener('submit', async evento => {
        evento.preventDefault();
        if (ocupado) return;
        ocupado = true; detener = false; boton.disabled = true; pausa.hidden = false; pausa.disabled = false;
        try {
            while (!detener) {
                const response = await fetch(form.action, {method: 'POST', body: new FormData(form), headers: {Accept: 'application/json'}});
                if (response.redirected) throw new Error('La sesión venció. Vuelve a iniciar sesión y revisa el progreso.');
                const datos = await response.json();
                if (!response.ok) throw new Error(datos.error || 'No se pudo procesar el lote. Revisa el progreso antes de continuar.');
                const r = datos.resumen;
                progreso.value = r.procesados;
                estado.textContent = `${r.procesados} de ${r.total - r.excluidos} procesados. Creados ocultos: ${r.creados}. Existentes omitidos: ${r.omitidos}. Por revisar: ${r.revisar}.`;
                if (datos.estado === 'terminado') { window.location.reload(); return; }
            }
            estado.textContent += ' Procesamiento detenido entre lotes; puedes continuar.';
        } catch (error) {
            estado.textContent = error.message || 'No se recibió el resultado. Actualiza la página para comprobar el progreso.';
        } finally { ocupado = false; boton.disabled = false; pausa.hidden = true; }
    });
})();

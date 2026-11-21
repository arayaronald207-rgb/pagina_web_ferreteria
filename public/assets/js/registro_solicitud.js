(() => {
    'use strict';
    const formulario = document.getElementById('registro-solicitud');
    if (!formulario) return;
    const nombre = formulario.elements.cliente_nombre;
    const telefono = formulario.elements.cliente_whatsapp;
    const boton = document.getElementById('registro-enviar');
    const error = document.getElementById('registro-error');
    const estado = document.getElementById('registro-estado');
    let enviando = false;
    const validar = () => {
        nombre.setCustomValidity(/\p{L}.*\s+\p{L}/u.test(nombre.value.trim()) ? '' : 'Escribe tu nombre y apellidos.');
        const numero = telefono.value.trim();
        const digitos = numero.replace(/\D/g, '');
        telefono.setCustomValidity(/^\+?[0-9][0-9 ()-]*$/.test(numero) && digitos.length >= 8 && digitos.length <= 15
            ? '' : 'Escribe un WhatsApp válido de 8 a 15 dígitos.');
    };
    nombre.addEventListener('input', validar);
    telefono.addEventListener('input', validar);
    boton.addEventListener('click', validar);
    formulario.addEventListener('submit', async evento => {
        evento.preventDefault();
        validar();
        if (enviando || !formulario.reportValidity()) return;
        enviando = true;
        boton.disabled = true;
        error.hidden = true;
        estado.textContent = 'Registrando la solicitud. Espera la confirmación antes de volver a enviarla.';
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 20000);
        try {
            const respuesta = await fetch(formulario.action, {
                method: 'POST', credentials: 'same-origin', signal: controller.signal,
                body: new URLSearchParams(new FormData(formulario))
            });
            const pagina = new DOMParser().parseFromString(await respuesta.text(), 'text/html');
            const destino = new URL(respuesta.url);
            if (respuesta.ok && pagina.getElementById('solicitud-confirmada')
                && destino.origin === location.origin && destino.pathname === new URL(formulario.action).pathname) {
                // El GET de confirmación es repetible y no vuelve a registrar la solicitud.
                enviando = false;
                location.assign(destino.href);
                return;
            }
            error.textContent = pagina.getElementById('registro-error')?.textContent.trim()
                || 'No pudimos completar el registro. Tu lista y tus datos se conservan; inténtalo nuevamente.';
        } catch (_) {
            error.textContent = 'No recibimos la confirmación del servidor. Tu lista y tus datos se conservan. Reintenta desde este formulario: si ya se registró, recuperarás la misma confirmación.';
        } finally {
            clearTimeout(timer);
            enviando = false;
            boton.disabled = false;
            estado.textContent = '';
        }
        error.hidden = false;
        error.focus();
    });
    window.addEventListener('beforeunload', evento => {
        if (enviando) { evento.preventDefault(); evento.returnValue = ''; }
    });
    window.addEventListener('pageshow', evento => {
        if (evento.persisted && enviando) {
            enviando = false; boton.disabled = false;
            estado.textContent = '';
        }
    });
})();

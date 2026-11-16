(() => {
    'use strict';
    const imagen = document.querySelector('#imagen-principal');
    const miniaturas = [...document.querySelectorAll('[data-fotografia]')];
    const estado = document.querySelector('#estado-galeria');
    if (!imagen || miniaturas.length < 2) return;
    miniaturas.forEach((miniatura, indice) => {
        miniatura.addEventListener('click', evento => {
            // Los enlaces siguen abriendo la fotografía si JavaScript está deshabilitado.
            if (evento.ctrlKey || evento.metaKey || evento.shiftKey || evento.altKey) return;
            evento.preventDefault();
            imagen.src = miniatura.dataset.fotografia;
            imagen.alt = miniatura.dataset.alternativa;
            miniaturas.forEach(enlace => enlace.removeAttribute('aria-current'));
            miniatura.setAttribute('aria-current', 'true');
            estado.textContent = `Fotografía ${indice + 1} de ${miniaturas.length}`;
        });
    });
})();

(() => {
    const cabecera = document.querySelector('.cabecera');
    if (!cabecera) return;

    const boton = cabecera.querySelector('.boton-menu');
    const panel = cabecera.querySelector('#panel-navegacion');
    const cierre = cabecera.querySelector('.menu-panel__cerrar');
    const fondo = cabecera.querySelector('.menu-fondo');
    const categorias = panel.querySelector('details');
    const escritorio = window.matchMedia('(min-width: 1121px)');
    let abierto = false;
    let desplazamiento = 0;
    let elementosInertes = [];

    const enfocables = () => [...panel.querySelectorAll('a[href], button, summary')]
        .filter(elemento => elemento.getClientRects().length && !elemento.disabled);

    function cerrarMenu(devolverFoco = true) {
        if (!abierto) return;
        abierto = false;
        cabecera.classList.remove('menu-abierto');
        boton.setAttribute('aria-expanded', 'false');
        panel.removeAttribute('role');
        panel.removeAttribute('aria-modal');
        panel.removeAttribute('aria-labelledby');
        panel.inert = !escritorio.matches;
        elementosInertes.forEach(elemento => { elemento.inert = false; });
        elementosInertes = [];
        document.body.classList.remove('menu-bloqueado');
        document.body.style.removeProperty('--menu-desplazamiento');
        window.scrollTo({ top: desplazamiento, behavior: 'instant' });
        categorias.open = false;
        if (devolverFoco) boton.focus({ preventScroll: true });
    }

    function abrirMenu() {
        if (escritorio.matches || abierto) return;
        abierto = true;
        desplazamiento = window.scrollY;
        document.body.style.setProperty('--menu-desplazamiento', '-' + desplazamiento + 'px');
        document.body.classList.add('menu-bloqueado');
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'true');
        panel.setAttribute('aria-labelledby', 'titulo-menu');
        panel.inert = false;
        boton.setAttribute('aria-expanded', 'true');
        cabecera.classList.add('menu-abierto');
        // Inhabilitar el fondo sin cambiar elementos que ya eran inertes.
        elementosInertes = [cabecera.querySelector('.cabecera__interior'),
            ...document.body.children].filter(elemento => elemento !== cabecera &&
                !['SCRIPT', 'STYLE'].includes(elemento.tagName) && !elemento.inert);
        elementosInertes.forEach(elemento => { elemento.inert = true; });
        cierre.focus({ preventScroll: true });
    }

    boton.addEventListener('click', abrirMenu);
    cierre.addEventListener('click', () => cerrarMenu());
    fondo.addEventListener('click', () => cerrarMenu());
    panel.addEventListener('click', evento => {
        if (evento.target.closest('a[href]')) {
            cerrarMenu();
            categorias.open = false;
        }
    });

    document.addEventListener('keydown', evento => {
        if (evento.key === 'Escape') {
            if (abierto) {
                evento.preventDefault();
                cerrarMenu();
            } else if (categorias.open) {
                categorias.open = false;
                categorias.querySelector('summary').focus();
            }
        }
        if (!abierto || evento.key !== 'Tab') return;
        const elementos = enfocables();
        const primero = elementos[0];
        const ultimo = elementos[elementos.length - 1];
        if (evento.shiftKey && (document.activeElement === primero || !panel.contains(document.activeElement))) {
            evento.preventDefault();
            ultimo.focus();
        } else if (!evento.shiftKey && (document.activeElement === ultimo || !panel.contains(document.activeElement))) {
            evento.preventDefault();
            primero.focus();
        }
    });

    document.addEventListener('click', evento => {
        if (escritorio.matches && !categorias.contains(evento.target)) categorias.open = false;
    });
    escritorio.addEventListener('change', () => {
        const focoEnPanel = panel.contains(document.activeElement);
        cerrarMenu(false);
        panel.inert = !escritorio.matches;
        categorias.open = false;
        if (focoEnPanel) {
            (escritorio.matches ? panel.querySelector('a[href]') : boton).focus({ preventScroll: true });
        }
    });
    window.addEventListener('pagehide', () => cerrarMenu(false));
    panel.inert = !escritorio.matches;
    cabecera.classList.add('cabecera--interactiva');
})();

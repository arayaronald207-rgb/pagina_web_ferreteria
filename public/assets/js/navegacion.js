(() => {
    const cabecera = document.querySelector('.cabecera');
    if (!cabecera) return;

    const boton = cabecera.querySelector('.boton-menu');
    const panel = cabecera.querySelector('#panel-navegacion');
    const cierre = cabecera.querySelector('.menu-panel__cerrar');
    const fondo = cabecera.querySelector('.menu-fondo');
    const categorias = panel.querySelector('details');
    const disparadorCategorias = categorias.querySelector('summary');
    const escritorio = window.matchMedia('(min-width: 1121px)');
    const ratonDisponible = window.matchMedia('(any-hover: hover) and (any-pointer: fine)');
    let cierreCategorias;
    let aperturaPorHover = false;
    let usandoTeclado = false;

    function cerrarCategorias(devolverFoco = false) {
        clearTimeout(cierreCategorias);
        aperturaPorHover = false;
        categorias.open = false;
        if (devolverFoco) disparadorCategorias.focus({ preventScroll: true });
    }

    // El panel absoluto es descendiente de details: el recorrido desde el botón
    // hasta cualquier columna permanece dentro de la misma área interactiva.
    categorias.addEventListener('pointerenter', evento => {
        if (!escritorio.matches || !ratonDisponible.matches || evento.pointerType !== 'mouse') return;
        clearTimeout(cierreCategorias);
        if (!categorias.open) {
            usandoTeclado = false;
            aperturaPorHover = true;
            categorias.open = true;
        }
    });
    categorias.addEventListener('pointerleave', evento => {
        if (!escritorio.matches || evento.pointerType !== 'mouse' || !categorias.open) return;
        clearTimeout(cierreCategorias);
        cierreCategorias = setTimeout(() => {
            // No ocultar un enlace que el usuario está recorriendo con el teclado.
            if (usandoTeclado && categorias.contains(document.activeElement)) return;
            cerrarCategorias(categorias.contains(document.activeElement));
        }, 180);
    });
    disparadorCategorias.addEventListener('click', evento => {
        // El primer clic tras el hover confirma la apertura, sin cerrarla por accidente.
        if (aperturaPorHover && evento.pointerType === 'mouse') evento.preventDefault();
        aperturaPorHover = false;
    });
    document.addEventListener('pointerdown', () => { usandoTeclado = false; });
    // Ajustar las anclas a la altura real, también al ampliar texto o rotar el móvil.
    const actualizarAltura = () => document.documentElement.style.setProperty(
        '--alto-cabecera', cabecera.getBoundingClientRect().height + 'px');
    new ResizeObserver(actualizarAltura).observe(cabecera);
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
        cerrarCategorias();
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
            cerrarCategorias();
        }
    });

    document.addEventListener('keydown', evento => {
        if (['Tab', 'Enter', ' '].includes(evento.key)) usandoTeclado = true;
        if (evento.key === 'Escape') {
            if (abierto) {
                evento.preventDefault();
                cerrarMenu();
            } else if (categorias.open) {
                cerrarCategorias(true);
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
        if (escritorio.matches && !categorias.contains(evento.target)) cerrarCategorias();
    });
    document.addEventListener('focusin', evento => {
        if (escritorio.matches && !categorias.contains(evento.target)) cerrarCategorias();
    });
    escritorio.addEventListener('change', () => {
        const focoEnPanel = panel.contains(document.activeElement);
        cerrarMenu(false);
        panel.inert = !escritorio.matches;
        cerrarCategorias();
        if (focoEnPanel) {
            (escritorio.matches ? panel.querySelector('a[href]') : boton).focus({ preventScroll: true });
        }
    });
    window.addEventListener('pagehide', () => { cerrarMenu(false); cerrarCategorias(); });
    panel.inert = !escritorio.matches;
    cabecera.classList.add('cabecera--interactiva');
    actualizarAltura();
})();

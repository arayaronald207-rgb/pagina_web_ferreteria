(() => {
    // La visibilidad nunca depende de una clase JS: si falla, todo sigue visible.
    if (!('IntersectionObserver' in window) || !Element.prototype.animate) return;
    const movimientoReducido = window.matchMedia('(prefers-reduced-motion: reduce)');
    const elementos = [...document.querySelectorAll('[data-revelar]')];
    const observador = new IntersectionObserver(entradas => {
        for (const entrada of entradas) {
            if (!entrada.isIntersecting) continue;
            observador.unobserve(entrada.target);
            if (movimientoReducido.matches || entrada.target.contains(document.activeElement)) continue;
            entrada.target.animate([
                { opacity: .55, transform: 'translateY(14px)' },
                { opacity: 1, transform: 'translateY(0)' },
            ], { duration: 450, easing: 'ease-out' });
        }
    }, { threshold: .1 });
    elementos.forEach(elemento => observador.observe(elemento));
    movimientoReducido.addEventListener('change', evento => {
        if (evento.matches) elementos.forEach(elemento => elemento.getAnimations().forEach(animacion => animacion.cancel()));
    });
})();

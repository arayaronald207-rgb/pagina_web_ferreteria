const botonMenu = document.querySelector(".boton-menu");
const navegacion = document.querySelector("#navegacion-principal");

function cerrarMenu(devolverFoco = false) {
    navegacion.classList.remove("is-open");
    botonMenu.setAttribute("aria-expanded", "false");
    botonMenu.setAttribute("aria-label", "Abrir menú");

    if (devolverFoco) {
        botonMenu.focus();
    }
}

botonMenu.addEventListener("click", () => {
    const menuAbierto = botonMenu.getAttribute("aria-expanded") === "true";

    if (menuAbierto) {
        cerrarMenu();
        return;
    }

    navegacion.classList.add("is-open");
    botonMenu.setAttribute("aria-expanded", "true");
    botonMenu.setAttribute("aria-label", "Cerrar menú");
});

navegacion.addEventListener("click", (evento) => {
    if (evento.target.closest("a")) {
        cerrarMenu();
    }
});

document.addEventListener("keydown", (evento) => {
    if (evento.key === "Escape" && botonMenu.getAttribute("aria-expanded") === "true") {
        cerrarMenu(true);
    }
});

window.matchMedia("(min-width: 1121px)").addEventListener("change", (evento) => {
    if (evento.matches) {
        cerrarMenu();
    }
});
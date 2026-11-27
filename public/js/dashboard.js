/* PrintFree — dashboard shell: off-canvas sidebar on small screens. */
(() => {
    "use strict";

    const sidebar = document.getElementById("sidebar");
    const overlay = document.querySelector("[data-sidebar-overlay]");
    const toggles = document.querySelectorAll("[data-sidebar-toggle]");

    if (!sidebar) return;

    const close = () => {
        sidebar.classList.remove("is-open");
        overlay?.classList.remove("is-visible");
        document.body.style.removeProperty("overflow");
    };

    const open = () => {
        sidebar.classList.add("is-open");
        overlay?.classList.add("is-visible");
        document.body.style.overflow = "hidden";
    };

    toggles.forEach((toggle) => {
        toggle.addEventListener("click", () => {
            sidebar.classList.contains("is-open") ? close() : open();
        });
    });

    overlay?.addEventListener("click", close);

    sidebar.querySelectorAll("a").forEach((link) => {
        link.addEventListener("click", () => {
            if (window.innerWidth <= 1024) close();
        });
    });

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") close();
    });

    window.addEventListener("resize", () => {
        if (window.innerWidth > 1024) close();
    });
})();

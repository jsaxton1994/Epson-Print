/* PrintFree — dashboard shell: off-canvas sidebar on small screens. */
(() => {
    "use strict";

    const sidebar = document.getElementById("sidebar");
    const overlay = document.querySelector("[data-sidebar-overlay]");
    const toggles = document.querySelectorAll("[data-sidebar-toggle]");

    // Live printer status: poll the JSON endpoint so the dashboard shows what
    // the printer is doing (photo 4 of 12, out of paper, idle) without a reload.
    const livePanels = document.querySelectorAll("[data-printer-live]");

    if (livePanels.length) {
        const refreshMs = 20000;

        const paint = (panel, status) => {
            panel.dataset.state = status.state || "idle";

            const label = panel.querySelector("[data-live-label]");
            const detail = panel.querySelector("[data-live-detail]");
            const time = panel.querySelector("[data-live-time]");
            const link = panel.querySelector("[data-live-link]");
            const order = panel.querySelector("[data-live-order]");

            if (label && status.label) label.textContent = status.label;
            if (detail && status.detail) detail.textContent = status.detail;
            if (time) time.textContent = status.state === "unreachable" ? "retrying…" : "checked just now";

            if (link) {
                if (status.order_url) {
                    link.href = status.order_url;
                    link.hidden = false;
                    if (order) order.textContent = status.order_reference || "Open order";
                } else {
                    link.hidden = true;
                }
            }
        };

        const refresh = async (panel) => {
            const endpoint = panel.dataset.statusUrl;
            if (!endpoint) return;

            try {
                const response = await fetch(endpoint, {
                    credentials: "same-origin",
                    cache: "no-store",
                    headers: { Accept: "application/json" },
                });

                if (!response.ok) throw new Error("HTTP " + response.status);

                paint(panel, await response.json());
            } catch (error) {
                paint(panel, {
                    state: "unreachable",
                    label: "Status unavailable",
                    detail: "Could not refresh the printer status. Retrying…",
                });
            }
        };

        const tick = () => {
            if (document.hidden) return;
            livePanels.forEach(refresh);
        };

        window.setInterval(tick, refreshMs);
        document.addEventListener("visibilitychange", () => {
            if (!document.hidden) tick();
        });
    }

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

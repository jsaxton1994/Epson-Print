/* PrintFree — site-wide interactions: header, mobile nav, reveals, flashes, FAQ. */
(() => {
    "use strict";

    const header = document.querySelector("[data-site-header]");
    const navToggle = document.querySelector("[data-nav-toggle]");

    const onScroll = () => {
        if (header) header.classList.toggle("is-scrolled", window.scrollY > 8);
    };
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });

    const closeNav = () => {
        if (!header) return;
        header.classList.remove("nav-open");
        navToggle?.setAttribute("aria-expanded", "false");
    };

    if (header && navToggle) {
        navToggle.addEventListener("click", () => {
            const open = header.classList.toggle("nav-open");
            navToggle.setAttribute("aria-expanded", open ? "true" : "false");
        });

        header.querySelectorAll(".site-nav a").forEach((link) => {
            link.addEventListener("click", closeNav);
        });

        document.addEventListener("keydown", (event) => {
            if (event.key === "Escape") closeNav();
        });

        window.addEventListener("resize", () => {
            if (window.innerWidth > 960) closeNav();
        });
    }

    /* Scroll reveal ------------------------------------------------------- */
    const reveals = document.querySelectorAll(".reveal");
    if (reveals.length) {
        if ("IntersectionObserver" in window) {
            const observer = new IntersectionObserver(
                (entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add("is-visible");
                            observer.unobserve(entry.target);
                        }
                    });
                },
                { threshold: 0.12, rootMargin: "0px 0px -40px 0px" }
            );
            reveals.forEach((element) => observer.observe(element));
        } else {
            reveals.forEach((element) => element.classList.add("is-visible"));
        }
    }

    /* Flash messages ------------------------------------------------------ */
    document.querySelectorAll("[data-flash]").forEach((flash) => {
        const dismiss = () => {
            if (!flash.isConnected) return;
            flash.style.transition = "opacity 200ms ease, transform 200ms ease";
            flash.style.opacity = "0";
            flash.style.transform = "translateY(-6px)";
            window.setTimeout(() => flash.remove(), 220);
        };

        flash.querySelector("[data-flash-close]")?.addEventListener("click", dismiss);
        window.setTimeout(dismiss, 6500);
    });

    /* Accordion FAQ: only one answer open at a time ----------------------- */
    const faqItems = document.querySelectorAll(".faq-item");
    faqItems.forEach((item) => {
        item.addEventListener("toggle", () => {
            if (!item.open) return;
            faqItems.forEach((other) => {
                if (other !== item) other.open = false;
            });
        });
    });
})();

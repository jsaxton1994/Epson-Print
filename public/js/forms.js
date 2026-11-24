/* PrintFree — forms: password toggles, demo accounts, photo uploads, confirms. */
(() => {
    "use strict";

    const humanSize = (bytes) => {
        if (!bytes) return "0 KB";
        const units = ["B", "KB", "MB", "GB"];
        const power = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        const value = bytes / Math.pow(1024, power);
        return `${power === 0 ? value : value.toFixed(1)} ${units[power]}`;
    };

    const setSummary = (key, value) => {
        document.querySelectorAll(`[data-summary="${key}"]`).forEach((element) => {
            element.textContent = value;
        });
    };

    /* Password visibility ------------------------------------------------- */
    document.querySelectorAll("[data-toggle-password]").forEach((button) => {
        const input = button.closest(".input-wrap")?.querySelector("input");
        if (!input) return;

        button.addEventListener("click", () => {
            const show = input.type === "password";
            input.type = show ? "text" : "password";
            button.setAttribute("aria-pressed", show ? "true" : "false");
            button.setAttribute("aria-label", show ? "Hide password" : "Show password");
        });
    });

    /* Photo dropzone ------------------------------------------------------ */
    document.querySelectorAll("[data-dropzone]").forEach((zone) => {
        const input = zone.querySelector('input[type="file"]');
        const nameEls = document.querySelectorAll("[data-file-name]");
        const hintEl = zone.querySelector("[data-file-hint]");
        const titleEl = zone.querySelector(".dropzone-title");
        const grid = zone.querySelector("[data-photo-grid]");
        const list = zone.querySelector("[data-photo-list]");
        const maxBytes = Number(zone.dataset.maxBytes) || 10 * 1024 * 1024;
        const multi = zone.hasAttribute("data-multi");
        const maxFiles = multi ? (Number(zone.dataset.maxFiles) || 10) : 1;
        let objectUrls = [];

        if (!input) return;

        const clearUrls = () => {
            objectUrls.forEach((url) => URL.revokeObjectURL(url));
            objectUrls = [];
        };

        const selected = () => Array.from(input.files || []);

        const isPhoto = (file) => file.type === "image/jpeg" || file.type === "image/png" || /\.(jpe?g|png)$/i.test(file.name);

        const invalidate = (message) => {
            window.alert(message);
            input.value = "";
            render();
        };

        const render = () => {
            clearUrls();
            const files = selected();
            const total = files.reduce((sum, file) => sum + file.size, 0);

            if (grid) {
                grid.innerHTML = "";
                files.forEach((file) => {
                    const url = URL.createObjectURL(file);
                    objectUrls.push(url);
                    const img = document.createElement("img");
                    img.src = url;
                    img.alt = "";
                    grid.appendChild(img);
                });
            }

            if (list) {
                list.innerHTML = "";
                files.forEach((file, index) => {
                    const row = document.createElement("span");
                    row.textContent = `${index + 1}. ${file.name} · ${humanSize(file.size)}`;
                    list.appendChild(row);
                });
            }

            zone.classList.toggle("has-file", files.length > 0);

            if (files.length === 0) {
                if (titleEl) titleEl.textContent = multi ? "Drop your photos here" : "Drop your photo here";
                if (hintEl) {
                    hintEl.textContent = multi
                        ? `or click to browse — JPG or PNG, up to ${Math.round(maxBytes / 1048576)} MB each, ${maxFiles} photos per order`
                        : `or click to browse — JPG or PNG, up to ${Math.round(maxBytes / 1048576)} MB`;
                }
                nameEls.forEach((element) => {
                    element.textContent = multi ? "" : "";
                });
                setSummary("photos", `0 of ${maxFiles}`);
                return;
            }

            if (titleEl) {
                titleEl.textContent = files.length === 1 ? "Photo selected" : `${files.length} photos selected`;
            }
            if (hintEl) {
                hintEl.textContent = `${files.length} of ${maxFiles} · ${humanSize(total)} total · ready to upload`;
            }
            nameEls.forEach((element) => {
                element.textContent = files.length === 1 ? files[0].name : `${files.length} photos selected`;
            });
            setSummary("photos", `${files.length} of ${maxFiles}`);
        };

        const accept = (files) => {
            const incoming = Array.from(files || []);

            if (incoming.length === 0) {
                render();
                return;
            }

            if (incoming.length > maxFiles) {
                invalidate(`Please choose no more than ${maxFiles} photos per order.`);
                return;
            }

            for (const file of incoming) {
                if (!isPhoto(file)) {
                    invalidate("Please choose JPG or PNG photos only. Other formats need converting first.");
                    return;
                }
                if (file.size > maxBytes) {
                    invalidate(`Please keep each photo under ${Math.round(maxBytes / 1048576)} MB.`);
                    return;
                }
            }

            const combined = multi ? [...selected(), ...incoming] : incoming.slice(0, 1);
            if (combined.length > maxFiles) {
                invalidate(`Please choose no more than ${maxFiles} photos per order.`);
                return;
            }

            try {
                const transfer = new DataTransfer();
                combined.forEach((file) => transfer.items.add(file));
                input.files = transfer.files;
            } catch (error) {
                if (!multi) {
                    input.value = "";
                }
                /* Older browsers keep their own file selection. */
            }

            render();
        };

        input.addEventListener("change", () => accept(input.files));
        render();

        ["dragenter", "dragover"].forEach((name) => {
            zone.addEventListener(name, (event) => {
                event.preventDefault();
                zone.classList.add("is-dragging");
            });
        });

        ["dragleave", "dragend", "drop"].forEach((name) => {
            zone.addEventListener(name, (event) => {
                event.preventDefault();
                zone.classList.remove("is-dragging");
            });
        });

        zone.addEventListener("drop", (event) => {
            const files = event.dataTransfer?.files;
            if (!files || files.length === 0) return;
            accept(files);
        });
    });

    /* Live order summary ------------------------------------------------ */
    const orderForm = document.querySelector("[data-order-form]");
    if (orderForm) {
        const updateSummary = () => {
            const set = (key, value) => {
                document.querySelectorAll(`[data-summary="${key}"]`).forEach((element) => {
                    element.textContent = value;
                });
            };

            const paper = orderForm.querySelector('select[name="paper_size"]');
            if (paper && paper.selectedIndex >= 0) {
                const label = paper.options[paper.selectedIndex].text.split("—")[0].trim();
                set("paper", label || paper.value);
            }

            const border = orderForm.querySelector('input[name="borderless"]:checked');
            if (border) set("borderless", border.dataset.label || (border.value === "1" ? "Borderless" : "White border"));

            const color = orderForm.querySelector('input[name="color_mode"]:checked');
            if (color) set("color", color.dataset.label || color.value);

            const quality = orderForm.querySelector('input[name="print_quality"]:checked');
            if (quality) set("quality", quality.dataset.label || quality.value);
        };

        orderForm.addEventListener("change", updateSummary);
        orderForm.addEventListener("input", updateSummary);
        updateSummary();
    }

    /* Copy buttons -------------------------------------------------------- */
    document.querySelectorAll("[data-copy]").forEach((button) => {
        button.addEventListener("click", async () => {
            const text = button.dataset.copy || "";
            let copied = false;

            try {
                await navigator.clipboard.writeText(text);
                copied = true;
            } catch (error) {
                const helper = document.createElement("textarea");
                helper.value = text;
                helper.setAttribute("readonly", "");
                helper.style.position = "fixed";
                helper.style.opacity = "0";
                document.body.appendChild(helper);
                helper.select();
                copied = document.execCommand("copy");
                helper.remove();
            }

            if (!copied) return;

            const original = button.textContent;
            button.textContent = "Copied";
            window.setTimeout(() => {
                button.textContent = original;
            }, 1500);
        });
    });

    /* Confirmations and double submits ------------------------------------ */
    document.addEventListener(
        "submit",
        (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement)) return;

            if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
                event.preventDefault();
                return;
            }

            if (form.hasAttribute("data-submit-once")) {
                const button = form.querySelector('[type="submit"]');
                if (button) {
                    window.setTimeout(() => {
                        button.disabled = true;
                        button.dataset.loading = "true";
                    }, 0);
                }
            }
        },
        true
    );
})();

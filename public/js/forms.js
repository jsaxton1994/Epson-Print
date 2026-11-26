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
        const batchSize = Math.max(1, Number(zone.dataset.batchSize) || 10);
        let previewToken = 0;

        if (!input) return;

        // Decode a 200px stand-in for the preview grid. Pointing <img> at the
        // full-size file makes iOS Safari decode every picked photo at full
        // resolution, which can leave the native photo picker on screen while
        // the page is busy — the "stuck after selecting photos" symptom.
        const smallPreview = async (file) => {
            if (typeof createImageBitmap !== "function") return null;

            try {
                const bitmap = await createImageBitmap(file, { resizeWidth: 200, resizeQuality: "low" });
                const scale = Math.min(1, 200 / Math.max(bitmap.width, bitmap.height));
                const canvas = document.createElement("canvas");
                canvas.width = Math.max(1, Math.round(bitmap.width * scale));
                canvas.height = Math.max(1, Math.round(bitmap.height * scale));

                const context = canvas.getContext("2d");
                if (!context) {
                    if (typeof bitmap.close === "function") bitmap.close();
                    return null;
                }

                context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
                if (typeof bitmap.close === "function") bitmap.close();

                return canvas.toDataURL("image/jpeg", 0.7);
            } catch (error) {
                return null;
            }
        };

        const selected = () => Array.from(input.files || []);

        const isPhoto = (file) => file.type.startsWith("image/")
            || /\.(jpe?g|jfif|png|webp|gif|bmp|avif|tiff?|heics?|heif|tga|ico)$/i.test(file.name);

        const invalidate = (message, clear = true) => {
            window.alert(message);
            if (clear) input.value = "";
            render();
        };

        const render = () => {
            const token = ++previewToken;
            const files = selected();
            const total = files.reduce((sum, file) => sum + file.size, 0);

            if (grid) {
                grid.innerHTML = "";
                // Empty slots keep the order stable while the small previews
                // decode one at a time; no full-size decode on selection.
                const previews = files.slice(0, 6);
                const slots = previews.map(() => {
                    const img = document.createElement("img");
                    img.alt = "";
                    img.decoding = "async";
                    img.className = "is-pending";
                    grid.appendChild(img);
                    return img;
                });

                (async () => {
                    // One at a time: six 48-megapixel photos decoded together is
                    // what made a phone browser grind to a halt.
                    for (let index = 0; index < previews.length; index++) {
                        const url = await smallPreview(previews[index]);
                        if (token !== previewToken) return;

                        if (url) {
                            slots[index].src = url;
                            slots[index].className = "";
                        } else {
                            slots[index].remove();
                        }
                    }
                })();

                if (files.length > 6) {
                    const more = document.createElement("span");
                    more.className = "dropzone-more";
                    more.textContent = `+${files.length - 6}`;
                    grid.appendChild(more);
                }
            }

            if (list) {
                list.innerHTML = "";
                files.slice(0, 40).forEach((file, index) => {
                    const row = document.createElement("span");
                    row.textContent = `${index + 1}. ${file.name} · ${humanSize(file.size)}`;
                    list.appendChild(row);
                });
                if (files.length > 40) {
                    const row = document.createElement("span");
                    row.textContent = `…and ${files.length - 40} more`;
                    list.appendChild(row);
                }
            }

            zone.classList.toggle("has-file", files.length > 0);

            if (files.length === 0) {
                if (titleEl) titleEl.textContent = multi ? "Drop your photos here" : "Drop your photo here";
                if (hintEl) {
                    hintEl.textContent = multi
                        ? `or click to browse — any common photo, up to ${Math.round(maxBytes / 1048576)} MB each. Select up to ${maxFiles}; uploaded ${batchSize} at a time.`
                        : `or click to browse — any common photo, up to ${Math.round(maxBytes / 1048576)} MB`;
                }
                nameEls.forEach((element) => {
                    element.textContent = multi ? "" : "";
                });
                setSummary("photos", `0 of ${maxFiles}`);
                return;
            }

            const batches = Math.ceil(files.length / batchSize);

            if (titleEl) {
                titleEl.textContent = files.length === 1 ? "Photo selected" : `${files.length} photos selected`;
            }
            if (hintEl) {
                hintEl.textContent = `${files.length} of ${maxFiles} · ${humanSize(total)} total · uploads in ${batches} batch${batches === 1 ? "" : "es"}`;
            }
            nameEls.forEach((element) => {
                element.textContent = files.length === 1 ? files[0].name : `${files.length} photos selected`;
            });
            setSummary("photos", `${files.length} of ${maxFiles}`);
        };

        const accept = (files, append = false) => {
            const incoming = Array.from(files || []);

            if (incoming.length === 0) {
                render();
                return;
            }

            const combined = multi ? [...(append ? selected() : []), ...incoming] : incoming.slice(0, 1);
            const seen = new Set();
            const unique = combined.filter((file) => {
                const key = `${file.name}:${file.size}:${file.lastModified}`;
                if (seen.has(key)) return false;
                seen.add(key);
                return true;
            });

            let chosen = unique;
            let trimmed = false;
            if (unique.length > maxFiles) {
                chosen = unique.slice(0, maxFiles);
                trimmed = true;
                window.alert(`Only ${maxFiles} photos can be added here. The first ${maxFiles} are selected.`);
            }

            for (const file of chosen) {
                if (!isPhoto(file)) {
                    invalidate("Please choose image files only — JPG, PNG, HEIC, WebP, GIF, BMP, TIFF, AVIF and similar.", !append);
                    return;
                }
                if (file.size > maxBytes) {
                    invalidate(`Please keep each photo under ${Math.round(maxBytes / 1048576)} MB.`, !append);
                    return;
                }
            }

            // Only rewrite the input when the picker's selection actually
            // changed. Re-assigning input.files inside a change handler can
            // wedge the iOS picker.
            const current = Array.from(input.files || []);
            const unchanged = !append && !trimmed
                && current.length === chosen.length
                && chosen.every((file, index) => current[index] === file);

            if (!unchanged) {
                try {
                    const transfer = new DataTransfer();
                    chosen.forEach((file) => transfer.items.add(file));
                    input.files = transfer.files;
                } catch (error) {
                    if (!multi) {
                        input.value = "";
                    }
                    /* Older browsers keep their own file selection. */
                }
            }

            render();
        };

        input.addEventListener("change", () => {
            // Snapshot now, do the work after the picker has had a chance to
            // close, so the native sheet never waits on our JS.
            const picked = Array.from(input.files || []);
            window.setTimeout(() => accept(picked, false), 50);
        });
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
            accept(files, true);
        });
    });

    /* Batch uploads ------------------------------------------------------- */
    const isHeicFile = (file) => /\.(heic|heif)$/i.test(file.name)
        || ["image/heic", "image/heif"].includes(file.type);
    const isTiffFile = (file) => /\.tiff?$/i.test(file.name) || file.type === "image/tiff";

    const loadScriptGlobal = (src, globalName) => {
        if (!src) return Promise.reject(new Error(`${globalName} decoder is not configured`));

        return new Promise((resolve, reject) => {
            const script = document.createElement("script");
            script.src = src;
            script.async = true;
            script.onload = () => {
                if (window[globalName]) resolve(window[globalName]);
                else reject(new Error(`${globalName} decoder did not load`));
            };
            script.onerror = () => reject(new Error(`${globalName} decoder did not load`));
            document.head.appendChild(script);
        });
    };

    let heicDecoderPromise = null;
    const loadHeicDecoder = (src) => {
        if (typeof window.heic2any === "function") {
            return Promise.resolve(window.heic2any);
        }
        if (!src) {
            return Promise.reject(new Error("HEIC decoder is not configured"));
        }
        if (!heicDecoderPromise) {
            heicDecoderPromise = new Promise((resolve, reject) => {
                const script = document.createElement("script");
                script.src = src;
                script.async = true;
                script.onload = () => {
                    if (typeof window.heic2any === "function") resolve(window.heic2any);
                    else reject(new Error("HEIC decoder did not load"));
                };
                script.onerror = () => reject(new Error("HEIC decoder did not load"));
                document.head.appendChild(script);
            }).catch((error) => {
                heicDecoderPromise = null;
                throw error;
            });
        }
        return heicDecoderPromise;
    };

    let tiffDecoderPromise = null;
    const loadTiffDecoder = (src) => {
        if (window.UTIF) return Promise.resolve(window.UTIF);
        if (!tiffDecoderPromise) {
            tiffDecoderPromise = loadScriptGlobal(src, "UTIF").catch((error) => {
                tiffDecoderPromise = null;
                throw error;
            });
        }
        return tiffDecoderPromise;
    };

    const tiffSource = async (file, src) => {
        const UTIF = await loadTiffDecoder(src);
        const buffer = await file.arrayBuffer();
        const pages = UTIF.decode(buffer);

        if (!pages || pages.length === 0) throw new Error("unreadable TIFF");

        UTIF.decodeImage(buffer, pages[0]);
        const width = pages[0].width;
        const height = pages[0].height;
        if (!width || !height || width * height > 40_000_000) throw new Error("TIFF too large");

        const rgba = UTIF.toRGBA8(pages[0]);
        const canvas = document.createElement("canvas");
        canvas.width = width;
        canvas.height = height;
        canvas.getContext("2d").putImageData(new ImageData(new Uint8ClampedArray(rgba), width, height), 0, 0);

        return canvas;
    };

    const imageSource = async (file) => {
        if (typeof createImageBitmap === "function") {
            try {
                return await createImageBitmap(file);
            } catch (error) {
                /* Fall through to an <img>; Safari can decode some HEIC files that way. */
            }
        }

        return await new Promise((resolve, reject) => {
            const url = URL.createObjectURL(file);
            const image = new Image();
            image.onload = () => {
                URL.revokeObjectURL(url);
                resolve(image);
            };
            image.onerror = () => {
                URL.revokeObjectURL(url);
                reject(new Error("unreadable"));
            };
            image.src = url;
        });
    };

    const prepareForUpload = async (file, heicSrc, tiffSrc) => {
        if (!isHeicFile(file) && !isTiffFile(file)) return file;

        try {
            let source;

            try {
                source = await imageSource(file);
            } catch (nativeError) {
                if (isHeicFile(file)) {
                    // Chrome/Firefox on desktop usually cannot decode HEIC. Fall back
                    // to the bundled decoder (Safari/iOS may already do it natively).
                    const decode = await loadHeicDecoder(heicSrc);
                    const converted = await decode({ blob: file, toType: "image/jpeg", quality: 0.92, multiple: false });
                    const blob = Array.isArray(converted) ? converted[0] : converted;
                    source = await imageSource(blob);
                } else {
                    source = await tiffSource(file, tiffSrc);
                }
            }

            const width = source.width || source.naturalWidth;
            const height = source.height || source.naturalHeight;
            if (!width || !height) throw new Error("unreadable");

            // Keep plenty of pixels for up to A4 (300 dpi), without huge phone canvases.
            const scale = Math.min(1, 3508 / Math.max(width, height));
            const canvas = document.createElement("canvas");
            canvas.width = Math.max(1, Math.round(width * scale));
            canvas.height = Math.max(1, Math.round(height * scale));

            const context = canvas.getContext("2d");
            context.fillStyle = "#fff";
            context.fillRect(0, 0, canvas.width, canvas.height);
            context.drawImage(source, 0, 0, canvas.width, canvas.height);
            if (typeof source.close === "function") source.close();

            const blob = await new Promise((resolve) => canvas.toBlob(resolve, "image/jpeg", 0.92));
            if (!blob) throw new Error("unreadable");

            return new File(
                [blob],
                `${file.name.replace(/\.[^.]+$/, "")}.jpg`,
                { type: "image/jpeg", lastModified: file.lastModified }
            );
        } catch (error) {
            throw new Error(
                `${file.name} is a ${isHeicFile(file) ? "HEIC" : "TIFF"} photo that could not be converted. `
                + "Try a JPG or PNG version of the photo."
            );
        }
    };

    const chunkFiles = (files, size, maxTotalBytes) => {
        const chunks = [];
        let current = [];
        let bytes = 0;
        // Leave room for multipart fields and headers when the host has a cap.
        const usable = maxTotalBytes > 0 ? Math.max(1, maxTotalBytes - 65536) : 0;

        files.forEach((file) => {
            const full = current.length >= size;
            const tooBig = usable > 0 && current.length > 0 && bytes + file.size > usable;
            if (full || tooBig) {
                chunks.push(current);
                current = [];
                bytes = 0;
            }
            current.push(file);
            bytes += file.size;
        });

        if (current.length > 0) chunks.push(current);
        return chunks;
    };

    /* Photos added live to the order page ---------------------------------- */
    const addPhotosToOrder = (photos, total) => {
        if (!Array.isArray(photos) || photos.length === 0) return;

        const gallery = document.querySelector("[data-order-gallery]");
        const rows = document.querySelector("[data-photo-rows]");

        photos.forEach((photo) => {
            if (gallery) {
                const figure = document.createElement("figure");
                const link = document.createElement("a");
                link.href = photo.view;
                link.target = "_blank";
                link.rel = "noopener";

                const img = document.createElement("img");
                img.src = photo.thumb;
                img.alt = photo.name;
                img.loading = "lazy";
                link.appendChild(img);

                const caption = document.createElement("figcaption");
                const name = document.createElement("span");
                name.className = "pg-name";
                name.textContent = `${photo.position}. ${photo.name}`;
                caption.appendChild(name);
                if (photo.badge) caption.insertAdjacentHTML("beforeend", photo.badge);

                figure.append(link, caption);
                gallery.appendChild(figure);
                gallery.hidden = false;
            }

            if (rows) {
                const row = document.createElement("tr");

                const photoCell = document.createElement("td");
                const cell = document.createElement("div");
                cell.className = "doc-cell";

                const thumb = document.createElement("img");
                thumb.className = "thumb";
                thumb.src = photo.thumb;
                thumb.alt = "";
                thumb.width = 42;
                thumb.height = 42;
                thumb.loading = "lazy";

                const text = document.createElement("span");
                text.className = "doc-text";
                const docName = document.createElement("span");
                docName.className = "doc-name";
                docName.textContent = `${photo.position}. ${photo.name}`;
                const docMeta = document.createElement("span");
                docMeta.className = "doc-meta";
                docMeta.textContent = `${photo.width} × ${photo.height} px`;
                text.append(docName, docMeta);
                cell.append(thumb, text);
                photoCell.appendChild(cell);

                const sizeCell = document.createElement("td");
                sizeCell.className = "nowrap muted";
                sizeCell.textContent = humanSize(photo.size);

                const fileCell = document.createElement("td");
                fileCell.className = "nowrap muted";
                fileCell.textContent = photo.file;

                const statusCell = document.createElement("td");
                if (photo.badge) statusCell.innerHTML = photo.badge;

                const actionCell = document.createElement("td");
                actionCell.className = "cell-actions";
                const save = document.createElement("a");
                save.className = "btn btn-ghost btn-sm";
                save.href = photo.download;
                save.textContent = "Save";
                actionCell.appendChild(save);

                row.append(photoCell, sizeCell, fileCell, statusCell, actionCell);
                rows.appendChild(row);

                const table = document.querySelector("[data-photo-table]");
                if (table) table.hidden = false;

                const storage = document.querySelector("[data-order-storage]");
                if (storage) {
                    const bytes = Number(storage.dataset.bytes || "0") + Number(photo.size || 0);
                    storage.dataset.bytes = String(bytes);
                    storage.textContent = humanSize(bytes);
                }
            }
        });

        document.querySelectorAll("[data-empty-photos]").forEach((element) => {
            element.hidden = true;
        });

        if (Number(total) > 0) {
            document.querySelectorAll("[data-order-count]").forEach((element) => {
                element.textContent = String(total);
            });
            document.querySelectorAll("[data-order-count-suffix]").forEach((element) => {
                element.textContent = Number(total) === 1 ? "" : "s";
            });
        }
    };

    document.querySelectorAll("[data-batch-upload]").forEach((form) => {
        const input = form.querySelector('input[type="file"][name="photos[]"]');
        const submit = form.querySelector('[type="submit"]');
        const progress = form.querySelector("[data-upload-progress]");
        const zone = form.querySelector("[data-dropzone]");
        const statusPanel = form.querySelector("[data-upload-status]");
        const statusList = form.querySelector("[data-upload-list]");
        const statusBar = form.querySelector("[data-upload-bar]");
        if (!input || !submit) return;

        const batchSize = Math.max(1, Number(form.dataset.batchSize) || 10);
        const maxTotalBytes = Number(zone?.dataset.maxTotalBytes) || 0;
        const heicDecoder = form.dataset.heicDecoder || "";
        const tiffDecoder = form.dataset.tiffDecoder || "";
        const originalLabel = submit.innerHTML;
        form.dataset.uploaded = "0";

        const setProgress = (text, isError = false) => {
            if (!progress) return;
            progress.hidden = false;
            progress.textContent = text;
            progress.style.color = isError ? "#b42318" : "";
        };

        const nextFrame = () => new Promise((resolve) => {
            let done = false;
            const finish = () => {
                if (done) return;
                done = true;
                resolve();
            };
            // Paint the status row, but never stall the upload when the tab is
            // in the background and animation frames are throttled.
            window.requestAnimationFrame(finish);
            window.setTimeout(finish, 50);
        });

        const rows = [];

        const showRows = (files, alreadyUploaded) => {
            rows.length = 0;
            if (!statusList) return;

            statusList.innerHTML = "";
            files.forEach((file, index) => {
                const item = document.createElement("li");
                const name = document.createElement("span");
                name.className = "upload-name";
                name.textContent = `${index + 1}. ${file.name}`;

                const state = document.createElement("span");
                state.className = "upload-state";
                state.textContent = index < alreadyUploaded ? "Added" : "Waiting";
                if (index < alreadyUploaded) item.className = "is-done";

                item.append(name, state);
                statusList.appendChild(item);
                rows.push(state);
            });

            if (statusPanel) statusPanel.hidden = false;
        };

        const setRowState = (index, className, text) => {
            const state = rows[index];
            if (!state) return;
            state.textContent = text;
            state.parentElement.className = className;
        };

        const setBar = (fraction) => {
            if (!statusBar) return;
            const percent = Math.max(0, Math.min(1, fraction || 0)) * 100;
            statusBar.style.width = `${percent}%`;
        };

        const postChunk = (url, body, onProgress) => new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open("POST", url, true);
            xhr.withCredentials = true;
            xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");

            xhr.upload.addEventListener("progress", (event) => {
                if (event.lengthComputable && event.total > 0) {
                    onProgress(event.loaded / event.total);
                }
            });

            xhr.addEventListener("load", () => {
                resolve({ status: xhr.status, text: xhr.responseText || "" });
            });
            xhr.addEventListener("error", () => reject(new Error("The upload could not reach the server. Check your connection and try again.")));
            xhr.addEventListener("abort", () => reject(new Error("The upload was cancelled.")));
            xhr.send(body);
        });

        form.addEventListener("submit", async (event) => {
            if (form.dataset.uploading === "1") {
                event.preventDefault();
                return;
            }

            const files = Array.from(input.files || []);
            if (files.length === 0) return;

            event.preventDefault();
            form.dataset.uploading = "1";
            submit.disabled = true;
            submit.textContent = "Uploading…";
            form.setAttribute("aria-busy", "true");

            let uploaded = Number(form.dataset.uploaded) || 0;

            // Every selected photo gets its own status row, so the whole
            // selection is visible from the first request instead of the page
            // looking frozen until the last batch lands.
            showRows(files, uploaded);
            setBar(uploaded / files.length);

            // Bring the live list into view; on a phone the upload can start
            // while the button the user tapped is still at the bottom.
            if (statusPanel && !statusPanel.hidden) {
                statusPanel.scrollIntoView({ behavior: "smooth", block: "nearest" });
            }
            let endpoint = form.action;
            let orderId = form.dataset.orderId || "";
            let orderUrl = form.dataset.orderUrl || window.location.pathname;

            try {
                // Send batch by batch, converting HEIC/TIFF only as each batch is
                // reached, so the first photos reach the order within seconds
                // instead of after the whole selection has been re-encoded.
                const chunks = chunkFiles(files.slice(uploaded), batchSize, maxTotalBytes);

                for (const chunk of chunks) {
                    const chunkStart = uploaded;
                    const label = `photos ${chunkStart + 1}–${chunkStart + chunk.length} of ${files.length}`;

                    const prepared = [];
                    for (let index = 0; index < chunk.length; index++) {
                        const rowIndex = chunkStart + index;
                        const file = chunk[index];
                        const converting = isHeicFile(file) || isTiffFile(file);

                        if (converting) {
                            setRowState(rowIndex, "is-active", "Preparing…");
                            setProgress(`Preparing photo ${rowIndex + 1} of ${files.length}…`);
                            // Let the browser paint the row before the (blocking)
                            // canvas conversion starts.
                            await nextFrame();
                        }

                        prepared.push(await prepareForUpload(file, heicDecoder, tiffDecoder));

                        if (converting) {
                            setRowState(rowIndex, "is-ready", "Ready");
                            await nextFrame();
                        }
                    }

                    setProgress(`Uploading ${label}…`);

                    for (let index = 0; index < chunk.length; index++) {
                        setRowState(chunkStart + index, "is-active", "Uploading…");
                    }
                    await nextFrame();

                    // Build the body from the form fields only: FormData(form)
                    // would copy the whole picked FileList for every batch.
                    const body = new FormData();
                    form.querySelectorAll("input[name], select[name], textarea[name]").forEach((field) => {
                        if (field.type === "file") return;
                        if ((field.type === "checkbox" || field.type === "radio") && !field.checked) return;
                        body.append(field.name, field.value);
                    });
                    body.set("ajax", "1");
                    chunk.forEach((file) => body.append("photos[]", file));

                    const response = await postChunk(endpoint, body, (fraction) => {
                        const done = (chunkStart + fraction * chunk.length) / files.length;
                        setBar(done);
                        setProgress(`Uploading ${label} — ${Math.round(done * 100)}%`);
                    });

                    let data = null;
                    try {
                        data = JSON.parse(response.text);
                    } catch (error) {
                        data = null;
                    }

                    if (response.status < 200 || response.status >= 300 || !data || !data.ok) {
                        const fallback = response.status === 419
                            ? "This form expired. Refresh the page and try again."
                            : `Upload failed (HTTP ${response.status}).`;
                        throw new Error(data?.error || fallback);
                    }

                    for (let index = 0; index < chunk.length; index++) {
                        setRowState(chunkStart + index, "is-done", "Added");
                    }

                    uploaded += chunk.length;
                    form.dataset.uploaded = String(uploaded);
                    setBar(uploaded / files.length);

                    // Put this batch straight into the order's photo list.
                    addPhotosToOrder(data.photos, data.total);

                    if (!orderId && data.order_id) {
                        orderId = String(data.order_id);
                        endpoint = `${form.dataset.addUrl}?id=${encodeURIComponent(orderId)}`;
                        orderUrl = `${form.dataset.orderUrl}?id=${encodeURIComponent(orderId)}`;
                    }

                    setProgress(`Uploaded ${uploaded} of ${files.length} photos…`);
                }

                const separator = orderUrl.includes("?") ? "&" : "?";
                window.location.href = `${orderUrl}${separator}uploaded=${uploaded}`;
            } catch (error) {
                form.dataset.uploading = "0";
                form.removeAttribute("aria-busy");
                submit.disabled = false;
                submit.innerHTML = originalLabel;

                if (statusList) {
                    statusList.querySelectorAll("li.is-active").forEach((item) => {
                        item.className = "is-error";
                        const state = item.querySelector(".upload-state");
                        if (state) state.textContent = "Not added";
                    });
                }

                const already = uploaded > 0
                    ? ` ${uploaded} photo${uploaded === 1 ? "" : "s"} already added — press the button again to continue with the rest.`
                    : "";
                setProgress(`${error.message}${already}`, true);
            }
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

    /* Table select-all ---------------------------------------------------- */
    document.querySelectorAll("[data-check-all]").forEach((toggle) => {
        const group = toggle.dataset.checkAll;
        const items = () => Array.from(document.querySelectorAll(`[data-check-item="${group}"]`));

        const sync = () => {
            const all = items();
            const checked = all.filter((item) => item.checked).length;
            toggle.checked = all.length > 0 && checked === all.length;
            toggle.indeterminate = checked > 0 && checked < all.length;
        };

        toggle.addEventListener("change", () => {
            items().forEach((item) => {
                item.checked = toggle.checked;
            });
            sync();
        });

        items().forEach((item) => item.addEventListener("change", sync));
        sync();
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

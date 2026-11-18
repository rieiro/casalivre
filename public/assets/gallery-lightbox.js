// Full-screen property photo gallery. DOM nodes are created with CL.el.
(function () {
    const el = CL.el;

    function label(key, fallback) {
        const bag = (CL.data() && CL.data().i18n) || {};
        return bag[key] || fallback;
    }

    function variant(photo, width) {
        const given = photo && photo.url ? String(photo.url) : CL.photoUrl(photo && photo.path);
        const match = /^(\/uploads\/fotos\/[0-9a-f-]{36})-\d+\.webp$/.exec(given);
        return match ? match[1] + "-" + width + ".webp" : given;
    }

    function captionOf(photo) {
        const text = photo && (photo.caption || photo.room || "");
        return String(text || "").trim();
    }

    let root = null;
    let onKey = null;
    let photos = [];
    let index = 0;
    let img = null;
    let captionEl = null;
    let counterEl = null;
    let thumbs = [];

    function render() {
        const photo = photos[index];
        img.src = variant(photo, 1920);
        img.alt = captionOf(photo) || label("gallery.photo", "Foto");
        const caption = captionOf(photo);
        captionEl.textContent = caption;
        captionEl.hidden = caption === "";
        counterEl.textContent = (index + 1) + " / " + photos.length;
        thumbs.forEach((btn, i) => {
            if (i === index) btn.setAttribute("aria-current", "true");
            else btn.removeAttribute("aria-current");
        });
        if (thumbs[index]) thumbs[index].scrollIntoView({ block: "nearest", inline: "nearest" });
    }

    function step(delta) {
        if (photos.length < 2) return;
        index = (index + delta + photos.length) % photos.length;
        render();
    }

    function close() {
        if (onKey) document.removeEventListener("keydown", onKey);
        onKey = null;
        if (root) root.remove();
        root = null;
        thumbs = [];
        document.body.classList.remove("gallery-lightbox-open");
    }

    function open(list, start) {
        photos = Array.isArray(list) ? list.filter(Boolean) : [];
        if (!photos.length) return;
        close();
        index = Math.max(0, Math.min(photos.length - 1, start | 0));
        img = el("img", { className: "gallery-lightbox-image", alt: "" });
        captionEl = el("p", { className: "gallery-lightbox-caption" });
        counterEl = el("p", { className: "gallery-lightbox-counter" });
        const closeBtn = el("button", {
            type: "button",
            className: "gallery-lightbox-close",
            "aria-label": label("gallery.close", "Fechar")
        }, "×");
        closeBtn.addEventListener("click", close);
        const prev = el("button", {
            type: "button",
            className: "gallery-lightbox-prev",
            "aria-label": label("gallery.prev", "Foto anterior")
        }, "‹");
        const next = el("button", {
            type: "button",
            className: "gallery-lightbox-next",
            "aria-label": label("gallery.next", "Próxima foto")
        }, "›");
        prev.addEventListener("click", () => step(-1));
        next.addEventListener("click", () => step(1));
        if (photos.length < 2) {
            prev.hidden = true;
            next.hidden = true;
        }
        const thumbBar = el("div", { className: "gallery-lightbox-thumbs" });
        thumbs = photos.map((photo, i) => {
            const btn = el("button", { type: "button", className: "gallery-lightbox-thumb" },
                el("img", { src: variant(photo, 320), alt: "" })
            );
            btn.addEventListener("click", () => {
                index = i;
                render();
            });
            thumbBar.appendChild(btn);
            return btn;
        });
        const frame = el("div", { className: "gallery-lightbox-frame" }, closeBtn, prev, img, next, captionEl, counterEl, thumbBar);
        const backdrop = el("button", {
            type: "button",
            className: "gallery-lightbox-backdrop",
            "aria-label": label("gallery.close", "Fechar")
        });
        backdrop.addEventListener("click", close);
        let startX = 0;
        let startY = 0;
        frame.addEventListener("touchstart", (ev) => {
            const touch = ev.changedTouches && ev.changedTouches[0];
            if (!touch) return;
            startX = touch.clientX;
            startY = touch.clientY;
        }, { passive: true });
        frame.addEventListener("touchend", (ev) => {
            const touch = ev.changedTouches && ev.changedTouches[0];
            if (!touch) return;
            const dx = touch.clientX - startX;
            const dy = touch.clientY - startY;
            if (Math.abs(dx) < 40 || Math.abs(dx) < Math.abs(dy)) return;
            step(dx < 0 ? 1 : -1);
        }, { passive: true });
        root = el("div", {
            className: "gallery-lightbox",
            role: "dialog",
            "aria-modal": "true",
            "aria-label": label("gallery.photo", "Foto")
        }, backdrop, frame);
        document.body.appendChild(root);
        document.body.classList.add("gallery-lightbox-open");
        onKey = (ev) => {
            if (ev.key === "Escape") {
                ev.preventDefault();
                close();
            } else if (ev.key === "ArrowLeft") {
                ev.preventDefault();
                step(-1);
            } else if (ev.key === "ArrowRight") {
                ev.preventDefault();
                step(1);
            }
        };
        document.addEventListener("keydown", onKey);
        render();
        closeBtn.focus();
    }

    CL.openPhotoGallery = open;

    function bindStatic() {
        const node = document.getElementById("property-photos");
        if (!node) return;
        let list = [];
        try {
            list = JSON.parse(node.textContent || "[]");
        } catch (e) {
            list = [];
        }
        document.addEventListener("click", (ev) => {
            const btn = ev.target.closest("[data-photo-index]");
            if (!btn) return;
            open(list, Number(btn.getAttribute("data-photo-index")));
        });
    }

    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", bindStatic);
    else bindStatic();
})();

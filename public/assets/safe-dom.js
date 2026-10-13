/* Utilitários para montar DOM sem innerHTML (XSS) e ler dados da página (CSP sem script inline). */
window.CL = {
    data() {
        const node = document.getElementById('page-data');
        try {
            return node ? JSON.parse(node.textContent || '{}') : {};
        } catch (e) {
            return {};
        }
    },
    // el('p', {className: 'muted'}, 'texto', outroNo): texto sempre via textContent.
    el(tag, props, ...children) {
        const node = document.createElement(tag);
        Object.entries(props || {}).forEach(([k, v]) => {
            if (v === null || v === undefined || v === false) return;
            if (k === 'className' || k === 'textContent' || k === 'type' || k === 'name' || k === 'value') node[k] = v;
            else if (k === 'href') node.href = CL.safeHref(v);
            else if (k === 'src') node.src = CL.photoUrl(v);
            else node.setAttribute(k, v === true ? '' : String(v));
        });
        children.flat().forEach((c) => {
            if (c === null || c === undefined || c === false) return;
            node.appendChild(c instanceof Node ? c : document.createTextNode(String(c)));
        });
        return node;
    },
    // Fotos: só caminhos relativos em /uploads/ (mesma regra de photo_url() no PHP); o resto vira placeholder.
    photoUrl(path) {
        const p = String(path || '');
        return /^\/uploads\/[A-Za-z0-9_\/.-]+$/.test(p) && !p.includes('..') ? p : '/assets/placeholder.svg';
    },
    safeHref(href) {
        const h = String(href || '');
        return h.startsWith('/') && !h.startsWith('//') ? h : '#';
    },
};

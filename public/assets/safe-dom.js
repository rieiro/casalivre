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
            // Atributos perigosos nunca passam: handlers on*, srcdoc, formaction, style (CSP sem inline).
            if (/^on/i.test(k) || /^(srcdoc|formaction|style)$/i.test(k)) {
                throw new Error('CL.el: atributo proibido: ' + k);
            }
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
    /**
     * Única chamada à API do front: credentials same-origin, CSRF de page_data (fonte única), JSON no corpo.
     * Resolve com o JSON da resposta; rejeita com Error {status, data, message}: mensagem da API (json.error),
     * senão opts.error, senão texto i18n genérico; falha de rede -> i18n netError (status 0).
     */
    async api(url, opts) {
        const o = opts || {};
        const method = (o.method || 'GET').toUpperCase();
        const headers = { 'X-CSRF-Token': CL.data().csrf || '' };
        const init = { method: method, credentials: 'same-origin', headers: headers };
        if (o.body !== undefined) {
            headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(o.body);
        }
        const i18n = CL.data().i18n || {};
        let res;
        try {
            res = await fetch(url, init);
        } catch (e) {
            const err = new Error(i18n.netError || 'Falha de conexão');
            err.status = 0;
            throw err;
        }
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            const err = new Error((data && data.error) || o.error || i18n.requestFailed || 'Erro');
            err.status = res.status;
            err.data = data;
            throw err;
        }
        return data;
    },
    safeHref(href) {
        const h = String(href || '');
        // Só caminho relativo ao site: "/x". Recusa "//host" e "/\\host" (o navegador trata \\ como /) e
        // espaços/controles (o navegador remove TAB/LF da URL: "/\t/host" viraria "//host").
        return /^\/(?![\/\\])/.test(h) && !/[\s\x00-\x1f\x7f]/.test(h) ? h : '#';
    },
};

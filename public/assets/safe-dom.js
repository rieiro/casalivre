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
    PROPS: { classname: 'className', textcontent: 'textContent', type: 'type', name: 'name', value: 'value' },
    URL_ATTRS: ['href', 'src', 'action', 'formaction', 'xlink:href'],
    // el('p', {className: 'muted'}, 'texto', outroNo): texto sempre via textContent.
    el(tag, props, ...children) {
        const node = document.createElement(tag);
        Object.entries(props || {}).forEach(([k, v]) => {
            if (v === null || v === undefined || v === false) return;
            // Nome sempre comparado em minúsculas (HREF, OnClick, Src... não escapam das regras).
            const name = String(k).toLowerCase();
            // Nunca passam: handlers on*, srcdoc, style (CSP sem inline).
            if (name.startsWith('on') || name === 'srcdoc' || name === 'style') {
                throw new Error('CL.el: atributo proibido: ' + k);
            }
            if (CL.PROPS[name]) {
                node[CL.PROPS[name]] = v;
            } else if (CL.URL_ATTRS.includes(name)) {
                // Atributos de URL: só caminhos relativos do site (safeHref); <img src> ainda só /uploads/.
                const url = CL.safeHref(v);
                node.setAttribute(name, name === 'src' && node.tagName === 'IMG' ? CL.photoUrl(url) : url);
            } else {
                node.setAttribute(name, v === true ? '' : String(v));
            }
        });
        children.flat().forEach((c) => {
            if (c === null || c === undefined || c === false) return;
            node.appendChild(c instanceof Node ? c : document.createTextNode(String(c)));
        });
        return node;
    },
    // Foto pronta (/uploads/fotos/<uuid>-<w>.webp): <img> com a variante card e srcset das 3 larguras
    // (mesma regra de photo_srcset_attr() no PHP). Caminhos antigos: só src.
    photoImg(path, sizes, alt) {
        const p = String(path || '');
        const m = /^(\/uploads\/fotos\/[0-9a-f-]{36})-\d+\.webp$/.exec(p);
        if (!m) return CL.el('img', { src: p, alt: alt || '', loading: 'lazy' });
        const widths = [320, 800, 1920];
        return CL.el('img', {
            src: m[1] + '-800.webp',
            srcset: widths.map((w) => m[1] + '-' + w + '.webp ' + w + 'w').join(', '),
            sizes: sizes,
            alt: alt || '',
            loading: 'lazy',
        });
    },
    // Fotos: só caminhos relativos em /uploads/ (mesma regra de photo_url() no PHP); o resto vira placeholder.
    // O caminho do placeholder fica repetido aqui de propósito (o JS não chama o PHP); manter igual ao de photo_url().
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
    toast(type, title, message) {
        let container = document.getElementById('prop-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'prop-toast-container';
            container.className = 'prop-toast-container cl-toast-container';
            container.setAttribute('aria-live', 'polite');
            document.body.appendChild(container);
        }

        const toastEl = document.createElement('div');
        toastEl.className = 'prop-toast prop-toast-' + type;
        toastEl.setAttribute('role', type === 'error' ? 'alert' : 'status');

        const iconWrap = document.createElement('div');
        iconWrap.className = 'prop-toast-icon';
        const svgNs = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(svgNs, 'svg');
        svg.setAttribute('width', '20');
        svg.setAttribute('height', '20');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('aria-hidden', 'true');
        if (type === 'success') {
            svg.setAttribute('stroke-width', '2.5');
            const p1 = document.createElementNS(svgNs, 'path');
            p1.setAttribute('d', 'M22 11.08V12a10 10 0 1 1-5.93-9.14');
            const p2 = document.createElementNS(svgNs, 'polyline');
            p2.setAttribute('points', '22 4 12 14.01 9 11.01');
            svg.appendChild(p1);
            svg.appendChild(p2);
        } else if (type === 'warning') {
            svg.setAttribute('stroke-width', '2.2');
            const p = document.createElementNS(svgNs, 'path');
            p.setAttribute('d', 'M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z');
            const l1 = document.createElementNS(svgNs, 'line');
            l1.setAttribute('x1', '12'); l1.setAttribute('y1', '9'); l1.setAttribute('x2', '12'); l1.setAttribute('y2', '13');
            const l2 = document.createElementNS(svgNs, 'line');
            l2.setAttribute('x1', '12'); l2.setAttribute('y1', '17'); l2.setAttribute('x2', '12.01'); l2.setAttribute('y2', '17');
            svg.appendChild(p);
            svg.appendChild(l1);
            svg.appendChild(l2);
        } else if (type === 'info') {
            svg.setAttribute('stroke-width', '2.2');
            const c = document.createElementNS(svgNs, 'circle');
            c.setAttribute('cx', '12'); c.setAttribute('cy', '12'); c.setAttribute('r', '10');
            const l1 = document.createElementNS(svgNs, 'line');
            l1.setAttribute('x1', '12'); l1.setAttribute('y1', '16'); l1.setAttribute('x2', '12'); l1.setAttribute('y2', '12');
            const l2 = document.createElementNS(svgNs, 'line');
            l2.setAttribute('x1', '12'); l2.setAttribute('y1', '8'); l2.setAttribute('x2', '12.01'); l2.setAttribute('y2', '8');
            svg.appendChild(c);
            svg.appendChild(l1);
            svg.appendChild(l2);
        } else {
            svg.setAttribute('stroke-width', '2.2');
            const c = document.createElementNS(svgNs, 'circle');
            c.setAttribute('cx', '12'); c.setAttribute('cy', '12'); c.setAttribute('r', '10');
            const l1 = document.createElementNS(svgNs, 'line');
            l1.setAttribute('x1', '12'); l1.setAttribute('y1', '8'); l1.setAttribute('x2', '12'); l1.setAttribute('y2', '12');
            const l2 = document.createElementNS(svgNs, 'line');
            l2.setAttribute('x1', '12'); l2.setAttribute('y1', '16'); l2.setAttribute('x2', '12.01'); l2.setAttribute('y2', '16');
            svg.appendChild(c);
            svg.appendChild(l1);
            svg.appendChild(l2);
        }
        iconWrap.appendChild(svg);

        const bodyWrap = document.createElement('div');
        bodyWrap.className = 'prop-toast-body';
        const titleNode = document.createElement('strong');
        titleNode.className = 'prop-toast-title';
        titleNode.textContent = title;
        bodyWrap.appendChild(titleNode);

        if (message) {
            const descNode = document.createElement('p');
            descNode.className = 'prop-toast-desc';
            descNode.textContent = message;
            bodyWrap.appendChild(descNode);
        }

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'prop-toast-close';
        closeBtn.setAttribute('aria-label', 'Fechar notificação');
        closeBtn.textContent = '✕';

        const dismiss = () => {
            toastEl.classList.add('is-hiding');
            setTimeout(() => {
                if (toastEl.parentNode) toastEl.parentNode.removeChild(toastEl);
            }, 300);
        };

        closeBtn.addEventListener('click', dismiss);
        setTimeout(dismiss, 4000);

        toastEl.appendChild(iconWrap);
        toastEl.appendChild(bodyWrap);
        toastEl.appendChild(closeBtn);

        container.appendChild(toastEl);
        void toastEl.offsetWidth;
        toastEl.classList.add('is-visible');
        return toastEl;
    },
    confirm(opts) {
        return new Promise((resolve) => {
            const options = typeof opts === 'string' ? { message: opts } : (opts || {});
            const title = options.title || 'Confirmação';
            const message = options.message || 'Tem certeza que deseja prosseguir?';
            const confirmText = options.confirmText || 'Confirmar';
            const cancelText = options.cancelText || 'Cancelar';
            const isDanger = options.danger !== false;

            const overlay = document.createElement('div');
            overlay.className = 'cl-modal-overlay';
            overlay.setAttribute('role', 'alertdialog');
            overlay.setAttribute('aria-modal', 'true');
            overlay.setAttribute('aria-label', title);

            const card = document.createElement('div');
            card.className = 'cl-modal-card';

            const iconWrap = document.createElement('div');
            iconWrap.className = 'cl-modal-icon' + (isDanger ? ' cl-modal-icon-danger' : '');
            iconWrap.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>';

            const titleEl = document.createElement('h3');
            titleEl.className = 'cl-modal-title';
            titleEl.textContent = title;

            const msgEl = document.createElement('p');
            msgEl.className = 'cl-modal-message';
            msgEl.textContent = message;

            const actions = document.createElement('div');
            actions.className = 'cl-modal-actions';

            const cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = 'cl-modal-btn cl-modal-btn-cancel';
            cancelBtn.textContent = cancelText;

            const confirmBtn = document.createElement('button');
            confirmBtn.type = 'button';
            confirmBtn.className = 'cl-modal-btn ' + (isDanger ? 'cl-modal-btn-danger' : 'cl-modal-btn-confirm');
            confirmBtn.textContent = confirmText;

            actions.appendChild(cancelBtn);
            actions.appendChild(confirmBtn);

            card.appendChild(iconWrap);
            card.appendChild(titleEl);
            card.appendChild(msgEl);
            card.appendChild(actions);
            overlay.appendChild(card);

            const cleanup = (result) => {
                window.removeEventListener('keydown', onKeyDown);
                overlay.classList.remove('is-visible');
                setTimeout(() => {
                    if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
                    resolve(result);
                }, 200);
            };

            const onKeyDown = (e) => {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    cleanup(false);
                }
            };

            cancelBtn.addEventListener('click', () => cleanup(false));
            confirmBtn.addEventListener('click', () => cleanup(true));
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) cleanup(false);
            });
            window.addEventListener('keydown', onKeyDown);

            document.body.appendChild(overlay);
            void overlay.offsetWidth;
            overlay.classList.add('is-visible');
            confirmBtn.focus();
        });
    },
    safeHref(href) {
        const h = String(href || '');
        // Só caminho relativo ao site: "/x". Recusa "//host" e "/\\host" (o navegador trata \\ como /) e
        // espaços/controles (o navegador remove TAB/LF da URL: "/\t/host" viraria "//host").
        return /^\/(?![\/\\])/.test(h) && !/[\s\x00-\x1f\x7f]/.test(h) ? h : '#';
    },
};

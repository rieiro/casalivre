/*
 * Converte FormData em objeto aninhado (para enviar como JSON à API).
 * - "a[b][c]" -> { a: { b: { c } } }; "rooms[0][beds][1][type]" -> rooms[0].beds[1].type
 * - "x[]" acrescenta em lista; chave repetida sem colchetes vira lista
 * - objetos com chaves 0..n-1 viram arrays
 * - segmentos __proto__/constructor/prototype são ignorados (sem poluição de protótipo)
 * Uso no navegador: CasaLivreForm.toObject(form | FormData). Em Node: require('./form-data.js').
 */
(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.CasaLivreForm = api;
    }
})(typeof self !== 'undefined' ? self : this, function () {
    const BLOCKED = ['__proto__', 'constructor', 'prototype'];

    function parseKey(name) {
        const m = /^([^\[\]]+)((?:\[[^\[\]]*\])*)$/.exec(name);
        if (!m) {
            return [name];
        }
        const parts = [m[1]];
        const re = /\[([^\[\]]*)\]/g;
        let seg;
        while ((seg = re.exec(m[2])) !== null) {
            parts.push(seg[1]);
        }
        return parts;
    }

    function densify(node) {
        if (node === null || typeof node !== 'object') {
            return node;
        }
        if (Array.isArray(node)) {
            return node.map(densify);
        }
        const keys = Object.keys(node);
        keys.forEach((k) => { node[k] = densify(node[k]); });
        const dense = keys.length > 0 && keys.every((k, i) => String(i) === k)
            && keys.length === keys.filter((k) => /^\d+$/.test(k)).length;
        if (dense) {
            return keys.map((k) => node[k]);
        }
        return node;
    }

    function toObject(source) {
        const data = (typeof FormData !== 'undefined' && source instanceof FormData) || (source && typeof source.entries === 'function' && !(source.nodeType))
            ? source
            : new FormData(source);
        const out = Object.create(null);
        for (const [name, raw] of data.entries()) {
            if (typeof raw !== 'string') {
                continue; // arquivos não vão no JSON
            }
            const parts = parseKey(name);
            if (parts.some((p) => BLOCKED.includes(p))) {
                continue;
            }
            let node = out;
            for (let i = 0; i < parts.length; i++) {
                const last = i === parts.length - 1;
                let key = parts[i];
                if (key === '') {
                    key = String(Object.keys(node).length);
                    while (Object.prototype.hasOwnProperty.call(node, key)) {
                        key = String(Number(key) + 1);
                    }
                }
                if (last) {
                    if (Object.prototype.hasOwnProperty.call(node, key) && parts.length === 1) {
                        node[key] = [].concat(node[key], raw);
                    } else {
                        node[key] = raw;
                    }
                } else {
                    if (!Object.prototype.hasOwnProperty.call(node, key) || node[key] === null || typeof node[key] !== 'object') {
                        node[key] = Object.create(null);
                    }
                    node = node[key];
                }
            }
        }
        return JSON.parse(JSON.stringify(densify(out)));
    }

    return { toObject: toObject, parseKey: parseKey };
});

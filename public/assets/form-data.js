/*
 * FormData -> objeto aninhado para a API (issue #20): "rooms[0][beds][1][type]" e "amenity[pool]".
 * Índices numéricos viram chaves de objeto; o PHP (json_decode) os trata como array.
 */
window.CasaLivreForm = {
    toObject(form) {
        const out = {};
        for (const [name, value] of new FormData(form)) {
            const keys = name.replace(/\]/g, '').split('[');
            if (typeof value !== 'string' || keys.some((k) => k === '__proto__' || k === 'constructor' || k === 'prototype')) {
                continue;
            }
            let node = out;
            keys.slice(0, -1).forEach((k) => {
                node = node[k] = (node[k] && typeof node[k] === 'object') ? node[k] : {};
            });
            node[keys[keys.length - 1]] = value;
        }
        return out;
    },
};

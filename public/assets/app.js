document.querySelectorAll('[data-city-hints]').forEach((input) => {
    const wrap = document.createElement('div');
    wrap.className = 'city-field';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    const list = document.createElement('ul');
    list.className = 'hints';
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    wrap.appendChild(list);
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', list.id = 'cidades-' + Math.random().toString(16).slice(2));
    let timer = 0;
    let active = -1;
    let rows = [];

    function close() {
        list.hidden = true;
        list.replaceChildren();
        input.setAttribute('aria-expanded', 'false');
        active = -1;
        rows = [];
        syncCredit();
    }

    function paint() {
        list.replaceChildren();
        rows.forEach((row, index) => {
            const item = document.createElement('li');
            item.setAttribute('role', 'presentation');
            const button = document.createElement('button');
            button.type = 'button';
            button.setAttribute('role', 'option');
            button.id = list.id + '-' + index;
            button.textContent = row.line;
            button.setAttribute('aria-selected', index === active ? 'true' : 'false');
            button.addEventListener('mousedown', (event) => event.preventDefault());
            button.addEventListener('click', () => choose(index));
            item.appendChild(button);
            list.appendChild(item);
        });
        list.hidden = rows.length === 0;
        input.setAttribute('aria-expanded', rows.length ? 'true' : 'false');
        input.setAttribute('aria-activedescendant', active >= 0 ? list.id + '-' + active : '');
        syncCredit();
    }

    function syncCredit() {
        const existing = wrap.querySelector('.osm-attribution');
        const hasPoi = !list.hidden && rows.some((row) => row.type === 'poi');
        if (!hasPoi) {
            if (existing) {
                existing.remove();
            }
            return;
        }
        if (existing) {
            return;
        }
        wrap.appendChild(document.getElementById('osm-attribution').content.firstElementChild.cloneNode(true));
    }

    function choose(index) {
        if (!rows[index]) {
            return;
        }
        input.value = rows[index].text;
        close();
    }

    input.addEventListener('input', () => {
        window.clearTimeout(timer);
        const query = input.value.trim();
        if (query.length < 2) {
            close();
            return;
        }
        timer = window.setTimeout(async () => {
            try {
                const response = await fetch('/api/cidades?q=' + encodeURIComponent(query), { credentials: 'same-origin' });
                rows = response.ok ? await response.json() : [];
            } catch (error) {
                // Falha de rede/JSON (ou 429): sem sugestões; o campo continua editável à mão.
                rows = [];
            }
            if (!Array.isArray(rows)) {
                rows = [];
            }
            active = rows.length ? 0 : -1;
            paint();
        }, 150);
    });

    input.addEventListener('keydown', (event) => {
        if (list.hidden) {
            return;
        }
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            active = Math.min(rows.length - 1, active + 1);
            paint();
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            active = Math.max(0, active - 1);
            paint();
        } else if (event.key === 'Enter' && active >= 0) {
            event.preventDefault();
            choose(active);
        } else if (event.key === 'Escape') {
            close();
        }
    });

    input.addEventListener('blur', () => window.setTimeout(close, 120));
});

(function stayCalendar() {
    const checkin = document.querySelector('[data-stay-date="checkin"]');
    const checkout = document.querySelector('[data-stay-date="checkout"]');
    if (!checkin || !checkout) {
        return;
    }
    const order = checkin.dataset.dateOrder === 'mdy' ? 'mdy' : 'dmy';
    const lang = document.documentElement.lang || 'pt-BR';
    const mondayFirst = !lang.toLowerCase().startsWith('en');
    const monthTitle = new Intl.DateTimeFormat(lang, { month: 'long', year: 'numeric' });
    const weekday = new Intl.DateTimeFormat(lang, { weekday: 'narrow' });
    checkin.readOnly = true;
    checkout.readOnly = true;
    let start = parseDate(checkin.value);
    let end = parseDate(checkout.value);
    let cursor = start ? new Date(start.getFullYear(), start.getMonth(), 1) : new Date();
    let openedAt = 0;
    const panel = document.createElement('div');
    panel.className = 'stay-cal';
    panel.hidden = true;
    document.body.appendChild(panel);

    function today() {
        const now = new Date();
        return new Date(now.getFullYear(), now.getMonth(), now.getDate());
    }

    function stamp(date) {
        return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
    }

    function parseDate(value) {
        const match = String(value || '').trim().match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
        if (!match) {
            return null;
        }
        const day = order === 'mdy' ? Number(match[2]) : Number(match[1]);
        const month = order === 'mdy' ? Number(match[1]) : Number(match[2]);
        const date = new Date(Number(match[3]), month - 1, day);
        if (date.getFullYear() !== Number(match[3]) || date.getMonth() !== month - 1 || date.getDate() !== day) {
            return null;
        }
        return date;
    }

    function format(date) {
        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        return order === 'mdy' ? month + '/' + day + '/' + date.getFullYear() : day + '/' + month + '/' + date.getFullYear();
    }

    function shift(months) {
        const next = new Date(cursor.getFullYear(), cursor.getMonth() + months, 1);
        const first = new Date(today().getFullYear(), today().getMonth(), 1);
        const last = new Date(today().getFullYear(), today().getMonth() + 16, 1);
        if (next < first || next > last) {
            return;
        }
        cursor = next;
        paint();
    }

    function choose(date) {
        if (!start || end || date <= start) {
            start = date;
            end = null;
        } else {
            end = date;
            panel.hidden = true;
        }
        checkin.value = start ? format(start) : '';
        checkout.value = end ? format(end) : '';
        if (!panel.hidden) {
            paint();
        }
    }

    function monthGrid(base) {
        const box = document.createElement('section');
        const title = document.createElement('h3');
        title.textContent = monthTitle.format(base);
        box.appendChild(title);
        const grid = document.createElement('div');
        grid.className = 'stay-month';
        const labels = [];
        for (let i = 0; i < 7; i += 1) {
            const day = new Date(2024, 0, mondayFirst ? i + 1 : i);
            labels.push(weekday.format(day));
        }
        labels.forEach((label) => {
            const cell = document.createElement('span');
            cell.className = 'stay-dow';
            cell.textContent = label;
            grid.appendChild(cell);
        });
        const firstWeekday = mondayFirst ? (base.getDay() + 6) % 7 : base.getDay();
        for (let i = 0; i < firstWeekday; i += 1) {
            grid.appendChild(document.createElement('span'));
        }
        const days = new Date(base.getFullYear(), base.getMonth() + 1, 0).getDate();
        for (let day = 1; day <= days; day += 1) {
            const date = new Date(base.getFullYear(), base.getMonth(), day);
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'stay-day';
            button.textContent = String(day);
            if (date < today()) {
                button.disabled = true;
            }
            const key = stamp(date);
            if (start && key === stamp(start)) {
                button.classList.add('start');
                if (end) {
                    button.classList.add('ranged');
                }
            }
            if (end && key === stamp(end)) {
                button.classList.add('end');
            }
            if (start && end && date > start && date < end) {
                button.classList.add('in-range');
            }
            button.addEventListener('click', () => choose(date));
            grid.appendChild(button);
        }
        box.appendChild(grid);
        return box;
    }

    function paint() {
        panel.replaceChildren();
        const nav = document.createElement('div');
        nav.className = 'stay-nav';
        const prev = document.createElement('button');
        prev.type = 'button';
        prev.textContent = '‹';
        prev.setAttribute('aria-label', '‹');
        prev.addEventListener('click', () => shift(-1));
        const next = document.createElement('button');
        next.type = 'button';
        next.textContent = '›';
        next.setAttribute('aria-label', '›');
        next.addEventListener('click', () => shift(1));
        nav.append(prev, next);
        const months = document.createElement('div');
        months.className = 'stay-months';
        months.append(monthGrid(cursor), monthGrid(new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1)));
        panel.append(nav, months);
    }

    function place() {
        const rect = checkin.getBoundingClientRect();
        const width = Math.min(720, window.innerWidth - 16);
        panel.style.width = width + 'px';
        const left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8));
        panel.style.left = left + 'px';
        panel.style.top = (rect.bottom + 8) + 'px';
        const height = panel.offsetHeight;
        if (rect.bottom + 8 + height > window.innerHeight && rect.top > height + 16) {
            panel.style.top = (rect.top - height - 8) + 'px';
        }
    }

    function open() {
        start = parseDate(checkin.value);
        end = parseDate(checkout.value);
        const anchor = start || today();
        cursor = new Date(anchor.getFullYear(), anchor.getMonth(), 1);
        openedAt = Date.now();
        panel.hidden = false;
        paint();
        place();
    }

    [checkin, checkout].forEach((input) => {
        input.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            open();
        });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                open();
            }
            if (event.key === 'Escape') {
                panel.hidden = true;
            }
        });
    });
    document.addEventListener('click', (event) => {
        if (panel.hidden || Date.now() - openedAt < 250) {
            return;
        }
        if (panel.contains(event.target) || event.target === checkin || event.target === checkout) {
            return;
        }
        panel.hidden = true;
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            panel.hidden = true;
        }
    });
    window.addEventListener('resize', () => {
        if (!panel.hidden) {
            place();
        }
    });
})();

document.querySelectorAll('[data-photo-select]').forEach((form) => {
    const button = form.querySelector('[data-delete-photos]');
    const cover = form.querySelector('[data-cover-photo]');
    const selectedBoxes = () => form.querySelectorAll('.photo-check:checked');
    const sync = () => {
        form.querySelectorAll('.photo-pick').forEach((label) => {
            const box = label.querySelector('input');
            label.classList.toggle('is-selected', Boolean(box && box.checked));
        });
        const selected = selectedBoxes().length;
        button.disabled = selected === 0;
        button.classList.toggle('is-off', selected === 0);
        button.dataset.ready = selected ? '1' : '0';
        if (cover) {
            const one = selected === 1;
            cover.disabled = !one;
            cover.classList.toggle('is-off', !one);
            cover.setAttribute('aria-disabled', one ? 'false' : 'true');
        }
    };
    form.querySelectorAll('.photo-pick').forEach((label) => {
        const box = label.querySelector('input');
        label.addEventListener('click', (event) => {
            if (!box || event.target === box) {
                return;
            }
            event.preventDefault();
            box.checked = !box.checked;
            sync();
        });
    });
    if (cover) {
        cover.addEventListener('click', (event) => {
            event.preventDefault();
            if (selectedBoxes().length !== 1 || cover.disabled) {
                sync();
                return;
            }
            const action = cover.getAttribute('formaction');
            if (action) {
                form.setAttribute('action', action);
            }
            HTMLFormElement.prototype.submit.call(form);
        });
    }
    form.addEventListener('change', sync);
    form.addEventListener('submit', (event) => {
        const selected = selectedBoxes().length;
        if (selected === 0 || !window.confirm(form.dataset.confirm || '')) {
            event.preventDefault();
        }
    });
    sync();
});

document.querySelectorAll('form[data-upload-limit]').forEach((form) => {
    const input = form.querySelector('input[type="file"]');
    const error = form.querySelector('[data-upload-error]');
    const maxPost = Number(form.dataset.maxPost || 0);
    const maxFile = Number(form.dataset.maxFile || 0);
    const maxCount = Number(form.dataset.maxCount || 0);
    const check = () => {
        const files = input && input.files ? Array.from(input.files) : [];
        const total = files.reduce((sum, file) => sum + file.size, 0);
        let message = '';
        if (maxCount > 0 && files.length > maxCount) {
            message = form.dataset.msgCount || '';
        } else if (files.some((file) => maxFile > 0 && file.size > maxFile)) {
            message = form.dataset.msgFile || '';
        } else if (maxPost > 0 && total > maxPost) {
            message = (form.dataset.msgTotal || '') + ' (' + (total / 1048576).toFixed(1) + ' MB)';
        }
        if (error) {
            error.hidden = message === '';
            error.textContent = message;
        }
        return message === '';
    };
    if (input) {
        input.addEventListener('change', check);
    }
    form.addEventListener('submit', (event) => {
        if (!check()) {
            event.preventDefault();
        }
    });
});

document.querySelectorAll('[data-count]').forEach((field) => {
    const note = field.parentElement ? field.parentElement.querySelector('[data-remaining]') : null;
    if (!note) {
        return;
    }
    const max = Number(field.getAttribute('data-count')) || 0;
    const paint = () => {
        const left = max - Array.from(field.value).length;
        const label = note.getAttribute('data-remaining-label') || '';
        note.textContent = label + String(Math.max(0, left));
        note.classList.toggle('over', left < 0);
    };
    field.addEventListener('input', paint);
    paint();
});

document.querySelectorAll('[data-cep]').forEach((input) => {
    const form = input.form;
    if (!form) {
        return;
    }
    const street = form.querySelector('[data-street]');
    const city = form.querySelector('[data-city]');
    const status = form.querySelector('[data-cep-status]');
    let timer = 0;
    const lookup = async () => {
        const digits = input.value.replace(/\D/g, '').slice(0, 8);
        const paint = (field, value) => {
            if (!field || field.value === value) {
                return;
            }
            field.value = value;
            field.dispatchEvent(new Event('input', { bubbles: true }));
        };
        if (digits.length !== 8) {
            paint(street, '');
            paint(city, '');
            return;
        }
        let data = {};
        try {
            const response = await fetch('/api/ceps?q=' + digits, { credentials: 'same-origin' });
            if (response.status === 429) {
                throw new Error('rate limited');
            }
            data = await response.json().catch(() => ({}));
        } catch (error) {
            // Falha de rede ou 429: mesmo tratamento.
            // não apaga o que o usuário já preencheu; só avisa.
            if (status) {
                status.textContent = 'Não foi possível consultar o CEP agora. Preencha o endereço manualmente.';
            }
            return;
        }
        if (!data || !data.street) {
            paint(street, '');
            paint(city, '');
            if (status && data.error) {
                status.textContent = data.error;
            }
            return;
        }
        paint(street, data.street);
        if (data.city_label) {
            paint(city, data.city_label);
        }
        if (status) {
            status.textContent = [data.neighborhood, data.city_label].filter(Boolean).join(' · ');
        }
    };
    input.addEventListener('input', () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(lookup, 250);
    });
    if (input.value.replace(/\D/g, '').length === 8 && ((street && street.value === '') || (city && city.value === ''))) {
        lookup();
    }
});

document.querySelectorAll('[data-save-house]').forEach((button) => {
    const form = button.form;
    if (!form) {
        return;
    }
    const read = () => Array.from(form.elements).map((field) => {
        if (!field.name || field.name === '_csrf' || field.type === 'submit' || field.type === 'button' || field.type === 'file') {
            return '';
        }
        if (field.type === 'checkbox' || field.type === 'radio') {
            return field.name + (field.checked ? ':1' : ':0');
        }
        return field.name + ':' + field.value;
    }).join('\n');
    const initial = read();
    const sync = () => {
        const dirty = read() !== initial;
        button.disabled = !dirty;
        button.classList.toggle('is-off', !dirty);
    };
    form.addEventListener('input', sync);
    form.addEventListener('change', sync);
    sync();
});

document.querySelectorAll('[data-rooms]').forEach((root) => {
    const list = root.querySelector('[data-room-list]');
    const bedTemplate = root.querySelector('[data-bed-template]');
    const form = root.closest('form');
    const max = Number(root.dataset.max || '999');
    const roomLabel = root.dataset.roomLabel || 'Quarto';
    const bedroomsLabel = root.dataset.bedroomsLabel || 'Quartos';
    const bedsLabel = root.dataset.bedsLabel || 'Camas';
    const bedOne = root.dataset.bedOne || 'cama';
    const bedMany = root.dataset.bedMany || 'camas';
    const summary = root.querySelector('[data-room-summary]');
    if (!list || !bedTemplate) {
        return;
    }
    const variantFor = (bed) => {
        const type = bed.querySelector('[data-bed-type]');
        const variant = bed.querySelector('[data-bed-variant]');
        if (!type || !variant) {
            return;
        }
        let keep = false;
        Array.from(variant.options).forEach((option) => {
            const allowed = (option.dataset.for || '').split(' ').includes(type.value);
            option.hidden = !allowed;
            option.disabled = !allowed;
            if (allowed && option.value === variant.value) {
                keep = true;
            }
        });
        variant.hidden = type.value === 'bunk';
        if (type.value === 'bunk') {
            variant.value = '';
            return;
        }
        if (!keep) {
            const first = Array.from(variant.options).find((option) => !option.disabled);
            if (first) {
                variant.value = first.value;
            }
        }
    };
    // Resumo por quarto ("Quarto 1: 2 camas"): só textContent, sem innerHTML.
    const renderSummary = (rooms) => {
        if (!summary) {
            return;
        }
        summary.replaceChildren(...rooms.map((room, index) => {
            const n = room.querySelectorAll('[data-bed]').length;
            const item = document.createElement('li');
            item.textContent = roomLabel + ' ' + (index + 1) + ': ' + n + ' ' + (n === 1 ? bedOne : bedMany);
            return item;
        }));
    };
    const paint = () => {
        const rooms = Array.from(list.querySelectorAll('[data-room]'));
        let bedTotal = 0;
        rooms.forEach((room) => {
            bedTotal += room.querySelectorAll('[data-bed]').length;
        });
        rooms.forEach((room, index) => {
            const title = room.querySelector('[data-room-title]');
            if (title) {
                title.textContent = roomLabel + ' ' + (index + 1);
            }
            const beds = Array.from(room.querySelectorAll('[data-bed]'));
            beds.forEach((bed, bedIndex) => {
                const type = bed.querySelector('[data-bed-type]');
                const variant = bed.querySelector('[data-bed-variant]');
                if (type) {
                    type.name = 'rooms[' + index + '][beds][' + bedIndex + '][type]';
                }
                if (variant) {
                    variant.name = 'rooms[' + index + '][beds][' + bedIndex + '][variant]';
                }
                variantFor(bed);
                const removeBed = bed.querySelector('[data-remove-bed]');
                if (removeBed) {
                    removeBed.disabled = beds.length === 1;
                }
            });
            const removeRoom = room.querySelector('[data-remove-room]');
            if (removeRoom) {
                removeRoom.disabled = rooms.length === 1;
            }
            const addBed = room.querySelector('[data-add-bed]');
            if (addBed) {
                addBed.disabled = bedTotal >= max;
            }
        });
        const addRoom = root.querySelector('[data-add-room]');
        if (addRoom) {
            addRoom.disabled = rooms.length >= max || bedTotal >= max;
        }
        renderSummary(rooms);
        const count = root.querySelector('[data-room-count]');
        if (count) {
            count.textContent = rooms.length + ' ' + bedroomsLabel + ' · ' + bedTotal + ' ' + bedsLabel;
        }
    };
    const changed = () => {
        paint();
        if (form) {
            form.dispatchEvent(new Event('input', { bubbles: true }));
        }
    };
    root.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        if (target.closest('[data-add-room]')) {
            const rooms = list.querySelectorAll('[data-room]');
            const bedTotal = list.querySelectorAll('[data-bed]').length;
            if (rooms.length >= max || bedTotal >= max) {
                return;
            }
            const copy = rooms[0].cloneNode(true);
            copy.querySelectorAll('[data-bed]').forEach((bed, index) => {
                if (index > 0) {
                    bed.remove();
                }
            });
            const type = copy.querySelector('[data-bed-type]');
            const variant = copy.querySelector('[data-bed-variant]');
            if (type) {
                type.value = 'single';
            }
            if (variant) {
                variant.value = 'common';
            }
            list.appendChild(copy);
            changed();
            return;
        }
        if (target.closest('[data-add-bed]')) {
            const room = target.closest('[data-room]');
            if (!room || list.querySelectorAll('[data-bed]').length >= max) {
                return;
            }
            const node = bedTemplate.content.firstElementChild.cloneNode(true);
            room.querySelector('[data-bed-list]').appendChild(node);
            changed();
            return;
        }
        if (target.closest('[data-remove-room]')) {
            const room = target.closest('[data-room]');
            if (room && list.querySelectorAll('[data-room]').length > 1) {
                room.remove();
                changed();
            }
            return;
        }
        if (target.closest('[data-remove-bed]')) {
            const room = target.closest('[data-room]');
            const bed = target.closest('[data-bed]');
            if (room && bed && room.querySelectorAll('[data-bed]').length > 1) {
                bed.remove();
                changed();
            }
        }
    });
    root.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof Element) || !target.matches('[data-bed-type]')) {
            return;
        }
        const bed = target.closest('[data-bed]');
        if (bed) {
            variantFor(bed);
        }
    });
    paint();
});

document.querySelectorAll('[data-noise]').forEach((box) => {
    const hours = box.closest('.amenity-noise')?.querySelector('[data-noise-hours]');
    if (!hours) {
        return;
    }
    const sync = () => {
        hours.hidden = !box.checked;
    };
    box.addEventListener('change', sync);
    sync();
});

const resolutionTitle = document.getElementById('place-resolution-title');
if (resolutionTitle && resolutionTitle.dataset.focus === '1') {
    resolutionTitle.focus();
}
const resolutionLive = document.getElementById('place-resolution-live');
if (resolutionLive) {
    resolutionLive.textContent = resolutionLive.dataset.announce || '';
}

document.querySelectorAll('[data-money]').forEach((input) => {
    const format = () => {
        const text = input.value.replace(/R\$/g, '').replace(/\s/g, '').trim();
        if (text === '') {
            input.value = input.name === 'cleaning_fee' ? 'R$ 0,00' : '';
            return;
        }
        const brazilian = /^(?:\d{1,3}(?:\.\d{3})+|\d+)(?:,(\d{1,2}))?$/.exec(text);
        if (!brazilian) {
            return;
        }
        if (brazilian[1] && Number(brazilian[1]) !== 0) {
            return;
        }
        const amount = Number(brazilian[0].split(',')[0].replace(/\./g, ''));
        if (!Number.isFinite(amount)) {
            return;
        }
        input.value = 'R$ ' + amount.toLocaleString('pt-BR') + ',00';
    };
    input.addEventListener('blur', format);
});



// Substitutos dos handlers inline (CSP sem 'unsafe-inline').
document.addEventListener('change', (event) => {
    const t = event.target;
    if (t instanceof HTMLSelectElement && t.hasAttribute('data-autosubmit') && t.form) {
        t.form.submit();
    }
});
document.addEventListener('click', (event) => {
    const t = event.target;
    if (t instanceof HTMLInputElement && t.hasAttribute('data-select-on-click')) {
        t.select();
    }
});
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (form instanceof HTMLFormElement && form.dataset.confirmSubmit && !window.confirm(form.dataset.confirmSubmit)) {
        event.preventDefault();
    }
});

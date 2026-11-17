(function () {
    const buttons = Array.from(document.querySelectorAll('[data-cal-day]'));
    const start = document.querySelector('[data-cal-start]');
    const end = document.querySelector('[data-cal-end]');
    const summary = document.querySelector('[data-cal-summary]');
    const price = document.querySelector('[data-cal-price]');
    const minInput = document.querySelector('[data-cal-min]');
    const minLabel = document.querySelector('[data-min-label]');
    const form = document.querySelector('[data-cal-form]');
    const summaryTable = document.querySelector('[data-cal-summary-table]');
    const stayFormula = document.querySelector('[data-cal-stay-formula]');
    const stayTotal = document.querySelector('[data-cal-stay-total]');
    const taxesVal = document.querySelector('[data-cal-taxes-val]');
    const grandTotal = document.querySelector('[data-cal-grand-total]');
    const months = (form && form.dataset.months ? form.dataset.months : '').split(',');
    function stamp(iso) {
        const parts = iso.split('-');
        return parts[2] + '-' + (months[Number(parts[1]) - 1] || parts[1]);
    }
    function fill(template, values) {
        let index = 0;
        return String(template || '').replace(/%s/g, () => values[index++] || '');
    }
    let anchor = null;
    let complete = false;
    const saveButton = document.querySelector('[data-cal-save]');
    const quoteButton = document.querySelector('[data-cal-quote]');
    const blockButton = document.querySelector('[data-cal-block]');
    const openButton = document.querySelector('[data-cal-open]');
    if (quoteButton) {
        quoteButton.addEventListener('click', () => {
            let acao = form ? form.querySelector('input[name="acao"]') : null;
            if (!acao && form) {
                acao = document.createElement('input');
                acao.type = 'hidden';
                acao.name = 'acao';
                form.appendChild(acao);
            }
            if (acao) {
                acao.value = 'cotacao';
            }
        });
    }
    function resetEdited() {
        if (!saveButton) {
            return;
        }
        saveButton.disabled = true;
        saveButton.classList.add('is-off');
    }
    function markEdited() {
        if (!saveButton) {
            return;
        }
        saveButton.disabled = false;
        saveButton.classList.remove('is-off');
    }
    if (price) {
        price.addEventListener('input', () => {
            markEdited();
            if (summaryTable && !summaryTable.hidden && start && start.value && end && end.value) {
                const val = Number(price.value);
                const currentChosen = buttons.filter((button) => {
                    const day = button.getAttribute('data-cal-day');
                    return !button.disabled && day >= start.value && day <= end.value;
                });
                if (val > 0 && currentChosen.length > 0) {
                    const subtotal = currentChosen.length * val;
                    const cleaningFee = Number((form && form.dataset.cleaningFee) || 0);
                    const taxPercent = Number((form && form.dataset.taxPercent) || 0);
                    const taxes = cleaningFee + Math.round((subtotal * taxPercent) / 100);
                    const total = subtotal + taxes;
                    if (stayFormula) {
                        stayFormula.textContent = currentChosen.length + ' x R$ ' + Number(val).toLocaleString('pt-BR');
                    }
                    if (stayTotal) {
                        stayTotal.textContent = 'R$ ' + Number(subtotal).toLocaleString('pt-BR');
                    }
                    if (taxesVal) {
                        taxesVal.textContent = 'R$ ' + Number(taxes).toLocaleString('pt-BR');
                    }
                    if (grandTotal) {
                        grandTotal.textContent = 'R$ ' + Number(total).toLocaleString('pt-BR');
                    }
                } else if (currentChosen.length > 0) {
                    updateSummaryTable(currentChosen);
                }
            }
        });
    }
    if (minInput) {
        minInput.addEventListener('input', markEdited);
    }
    function updateSummaryTable(chosenDays) {
        if (!summaryTable) {
            return;
        }
        if (!chosenDays || chosenDays.length === 0) {
            summaryTable.hidden = true;
            return;
        }
        const priceCounts = {};
        let subtotal = 0;
        chosenDays.forEach((btn) => {
            const p = Number(btn.getAttribute('data-price')) || 0;
            priceCounts[p] = (priceCounts[p] || 0) + 1;
            subtotal += p;
        });
        const formulaParts = [];
        Object.keys(priceCounts).forEach((p) => {
            formulaParts.push(priceCounts[p] + ' x R$ ' + Number(p).toLocaleString('pt-BR'));
        });
        const cleaningFee = Number((form && form.dataset.cleaningFee) || 0);
        const taxPercent = Number((form && form.dataset.taxPercent) || 0);
        const taxes = cleaningFee + Math.round((subtotal * taxPercent) / 100);
        const total = subtotal + taxes;

        if (stayFormula) {
            stayFormula.textContent = formulaParts.join(' + ');
        }
        if (stayTotal) {
            stayTotal.textContent = 'R$ ' + Number(subtotal).toLocaleString('pt-BR');
        }
        if (taxesVal) {
            taxesVal.textContent = 'R$ ' + Number(taxes).toLocaleString('pt-BR');
        }
        if (grandTotal) {
            grandTotal.textContent = 'R$ ' + Number(total).toLocaleString('pt-BR');
        }
        summaryTable.hidden = false;
    }
    function paint(from, to) {
        const first = from < to ? from : to;
        const last = from < to ? to : from;
        buttons.forEach((button) => {
            const day = button.getAttribute('data-cal-day');
            button.classList.toggle('picked', !button.disabled && day >= first && day <= last);
        });
        start.value = first;
        end.value = last;
        summary.textContent = first === last ? stamp(first) : stamp(first) + ' — ' + stamp(last);
        summary.classList.remove('is-hint');
        let openCount = 0;
        let systemCount = 0;
        let importedCount = 0;
        let hasDisabled = false;
        buttons.forEach((button) => {
            const day = button.getAttribute('data-cal-day');
            if (day < first || day > last) {
                return;
            }
            if (button.disabled) {
                hasDisabled = true;
                return;
            }
            const origin = button.getAttribute('data-origin') || '';
            if (origin === 'sync' || origin === 'reserva' || origin === 'pre-reserva' || origin === 'prereserva') {
                importedCount++;
            } else if (origin === 'sistema') {
                systemCount++;
            } else {
                openCount++;
            }
        });
        const locked = importedCount > 0;
        if (blockButton) {
            blockButton.disabled = locked || openCount === 0;
            blockButton.classList.toggle('is-off', locked || openCount === 0);
        }
        if (openButton) {
            openButton.disabled = locked || systemCount === 0;
            openButton.classList.toggle('is-off', locked || systemCount === 0);
        }
        const chosenDays = buttons.filter((button) => {
            const day = button.getAttribute('data-cal-day');
            return !button.disabled && day >= first && day <= last;
        });
        const firstChosen = chosenDays.length > 0 ? chosenDays[0] : null;
        const minRequired = firstChosen
            ? (Number(firstChosen.getAttribute('data-min')) || 1)
            : 1;
        const meetsMinNights = chosenDays.length >= minRequired;
        if (quoteButton) {
            const quoteReady = !hasDisabled && !locked && openCount > 0 && systemCount === 0 && importedCount === 0 && meetsMinNights;
            quoteButton.disabled = !quoteReady;
            if (quoteReady) {
                quoteButton.removeAttribute('disabled');
                quoteButton.classList.remove('is-off');
            } else {
                quoteButton.setAttribute('disabled', 'disabled');
                quoteButton.classList.add('is-off');
            }
        }
        if (price) {
            const prices = chosenDays.map((button) => button.getAttribute('data-price') || '');
            const same = prices.length > 0 && prices.every((value) => value === prices[0]);
            price.value = same ? prices[0] : '';
        }
        if (minInput) {
            const mins = chosenDays.map((button) => button.getAttribute('data-min') || '');
            const sameMin = mins.length > 0 && mins.every((value) => value === mins[0]);
            minInput.value = sameMin ? mins[0] : '';
        }
        if (minLabel && form) {
            minLabel.textContent = first === last
                ? fill(form.dataset.minFrom, [stamp(first)])
                : fill(form.dataset.minRange, [stamp(first), stamp(last)]);
        }
        updateSummaryTable(chosenDays);
        resetEdited();
    }
    buttons.forEach((button) => {
        button.addEventListener('click', () => {
            if (button.disabled) {
                return;
            }
            const day = button.getAttribute('data-cal-day');
            if (!anchor || complete) {
                anchor = day;
                complete = false;
                paint(day, day);
                return;
            }
            paint(anchor, day);
            complete = true;
        });
    });
    const board = document.querySelector('.cal-board');
    const viewButtons = Array.from(document.querySelectorAll('[data-cal-view]'));
    const desktop = window.matchMedia('(min-width: 801px)');
    let viewMode = 'day';
    function applyView() {
        const mode = viewMode;
        if (board) {
            board.setAttribute('data-cal-mode', mode);
        }
        const viewInput = document.querySelector('[data-cal-view-input]');
        if (viewInput) {
            viewInput.value = viewMode;
        }
        buttons.forEach((button) => {
            const main = button.querySelector('[data-cal-main]');
            const corner = button.querySelector('[data-cal-corner]');
            const dayNum = button.getAttribute('data-day-num') || '';
            if (corner) {
                corner.textContent = dayNum;
            }
            if (!main) {
                return;
            }
            if (mode === 'price') {
                main.textContent = button.getAttribute('data-price') || '';
            } else if (mode === 'min') {
                main.textContent = button.getAttribute('data-min') || '';
            } else {
                main.textContent = dayNum;
            }
        });
        viewButtons.forEach((item) => {
            const on = item.getAttribute('data-cal-view') === viewMode;
            item.classList.toggle('is-on', on);
            item.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }
    viewButtons.forEach((item) => {
        item.addEventListener('click', () => {
            viewMode = item.getAttribute('data-cal-view') || 'day';
            applyView();
        });
    });
    desktop.addEventListener('change', applyView);
    const monthSections = Array.from(document.querySelectorAll('.cal-month'));
    let monthIndex = 0;
    function showMonth(index) {
        if (!monthSections.length) {
            return;
        }
        monthIndex = Math.max(0, Math.min(monthSections.length - 1, index));
        monthSections.forEach((section, i) => section.classList.toggle('is-shown', i === monthIndex));
        const atStart = monthIndex === 0;
        const atEnd = monthIndex >= monthSections.length - 1;
        document.querySelectorAll('[data-cal-prev]').forEach((button) => {
            button.disabled = atStart;
            button.classList.toggle('is-off', atStart);
        });
        document.querySelectorAll('[data-cal-next]').forEach((button) => {
            button.disabled = atEnd;
            button.classList.toggle('is-off', atEnd);
        });
    }
    showMonth(0);
    document.querySelectorAll('[data-cal-prev]').forEach((button) => {
        button.addEventListener('click', () => showMonth(monthIndex - 1));
    });
    document.querySelectorAll('[data-cal-next]').forEach((button) => {
        button.addEventListener('click', () => showMonth(monthIndex + 1));
    });
    const restore = document.querySelector('[data-cal-restore]');
    if (restore) {
        const savedView = restore.getAttribute('data-view') || 'day';
        if (savedView === 'price' || savedView === 'min' || savedView === 'day') {
            viewMode = savedView;
        }
        applyView();
        const savedStart = restore.getAttribute('data-start') || '';
        const savedEnd = restore.getAttribute('data-end') || '';
        if (savedStart && savedEnd) {
            anchor = savedStart;
            complete = savedStart !== savedEnd;
            paint(savedStart, savedEnd);
            const savedButton = buttons.find((button) => button.getAttribute('data-cal-day') === savedStart);
            const savedMonth = savedButton ? savedButton.closest('.cal-month') : null;
            if (savedMonth) {
                showMonth(monthSections.indexOf(savedMonth));
            }
        }
    }
})();

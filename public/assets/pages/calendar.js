(function () {
    const form = document.querySelector('[data-feed-form]');
    if (!form) {
        return;
    }
    const title = form.querySelector('[name="feed_title"]');
    const url = form.querySelector('[name="external_ical_url"]');
    const platform = form.querySelector('[data-feed-platform]');
    const propertyUrl = form.querySelector('[data-feed-property-url]');
    const consent = form.querySelector('[data-feed-consent]');
    const otaFields = form.querySelector('[data-ota-fields]');
    const syncHint = form.querySelector('[data-sync-hint]');
    const modes = form.querySelectorAll('[data-feed-mode]');
    const button = form.querySelector('[data-feed-add]');
    const full = form.getAttribute('data-full') === '1';
    const meta = JSON.parse(form.getAttribute('data-platform-meta') || '{}');

    function mode() {
        const checked = form.querySelector('[data-feed-mode]:checked');
        return checked ? checked.value : 'sync_only';
    }

    function ready() {
        const ota = mode() === 'ota';
        const ok = !full
            && title.value.trim() !== ''
            && url.value.trim() !== ''
            && (!ota || (
                platform.value !== ''
                && propertyUrl.value.trim() !== ''
                && consent.checked
            ));
        button.disabled = !ok;
        button.classList.toggle('is-off', !ok);
    }

    function applyPlatform() {
        const selected = platform.value;
        const item = selected && meta[selected] ? meta[selected] : null;
        if (item) {
            propertyUrl.placeholder = item.public_placeholder || '';
            url.placeholder = item.ical_placeholder || '';
        }
        ready();
    }

    function applyMode() {
        const ota = mode() === 'ota';
        if (otaFields) otaFields.hidden = !ota;
        if (syncHint) syncHint.hidden = ota;
        [platform, propertyUrl, consent].forEach(function (el) {
            if (el) el.required = ota;
        });
        applyPlatform();
    }

    [title, url, propertyUrl].forEach(function (el) {
        if (el) el.addEventListener('input', ready);
    });
    if (platform) platform.addEventListener('change', applyPlatform);
    if (consent) consent.addEventListener('change', ready);
    modes.forEach(function (el) {
        el.addEventListener('change', applyMode);
    });
    applyMode();
})();

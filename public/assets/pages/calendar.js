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
    const button = form.querySelector('[data-feed-add]');
    const full = form.getAttribute('data-full') === '1';
    const meta = JSON.parse(form.getAttribute('data-platform-meta') || '{}');
    let titleAuto = true;

    function ready() {
        const ok = !full
            && url.value.trim() !== ''
            && platform.value !== ''
            && propertyUrl.value.trim() !== ''
            && consent.checked;
        button.disabled = !ok;
        button.classList.toggle('is-off', !ok);
    }

    function applyPlatform() {
        const selected = platform.value;
        const item = selected && meta[selected] ? meta[selected] : null;
        if (item) {
            propertyUrl.placeholder = item.public_placeholder || '';
            url.placeholder = item.ical_placeholder || '';
            title.placeholder = item.label || '';
            if (titleAuto) title.value = item.label || '';
        } else {
            propertyUrl.placeholder = '';
            url.placeholder = '';
            title.placeholder = '';
            if (titleAuto) title.value = '';
        }
        ready();
    }

    form.addEventListener('submit', function () {
        if (title.value.trim() === '') {
            const item = platform.value && meta[platform.value] ? meta[platform.value] : null;
            title.value = item && item.label ? item.label : '';
        }
    });

    [title, url, propertyUrl].forEach(function (el) {
        if (el) el.addEventListener('input', ready);
    });
    title.addEventListener('input', function () {
        titleAuto = false;
    });
    if (platform) platform.addEventListener('change', applyPlatform);
    if (consent) consent.addEventListener('change', ready);
    applyPlatform();
})();

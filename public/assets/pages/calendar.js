(function () {
    const form = document.querySelector('[data-feed-form]');
    if (!form) {
        return;
    }
    const title = form.querySelector('[name="feed_title"]');
    const url = form.querySelector('[name="external_ical_url"]');
    const button = form.querySelector('[data-feed-add]');
    const full = form.getAttribute('data-full') === '1';
    function ready() {
        const ok = !full && title.value.trim() !== '' && url.value.trim() !== '';
        button.disabled = !ok;
        button.classList.toggle('is-off', !ok);
    }
    title.addEventListener('input', ready);
    url.addEventListener('input', ready);
    ready();
})();

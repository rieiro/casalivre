(function () {
    document.querySelectorAll('form[data-confirm-approve]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            if (!window.confirm(form.getAttribute('data-confirm-approve') || '')) {
                ev.preventDefault();
            }
        });
    });

    document.querySelectorAll('.ota-reject-form').forEach(function (form) {
        const preset = form.querySelector('[data-reject-preset]');
        const note = form.querySelector('[data-reject-note]');
        if (!preset || !note) return;
        preset.addEventListener('change', function () {
            if (preset.value && preset.value !== 'Outro motivo') {
                note.value = preset.value;
            } else if (preset.value === 'Outro motivo') {
                note.value = '';
                note.focus();
            }
        });
    });
})();

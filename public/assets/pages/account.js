(function () {
    const form = document.getElementById('account-form');
    const errorEl = document.getElementById('account-error');
    const okEl = document.getElementById('account-ok');

    if (errorEl && !errorEl.hidden && errorEl.textContent.trim() && window.CL && typeof window.CL.toast === 'function') {
        CL.toast('error', 'Atenção', errorEl.textContent.trim());
    }

    form.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        errorEl.hidden = true;
        okEl.hidden = true;
        const data = Object.fromEntries(new FormData(form).entries());
        try {
            await CL.api('/api/users/eu', { method: 'PUT', body: data, error: 'Erro ao salvar' });
            okEl.hidden = false;
            if (window.CL && typeof window.CL.toast === 'function') {
                CL.toast('success', 'Alterações salvas com sucesso', 'Seus dados de perfil foram atualizados.');
            }
        } catch (e) {
            errorEl.textContent = e.message;
            errorEl.hidden = false;
            if (window.CL && typeof window.CL.toast === 'function') {
                CL.toast('error', 'Erro ao salvar', e.message || 'Verifique as informações e tente novamente.');
            }
        }
    });
    const deleteForm = document.getElementById('delete-account');
    if (deleteForm) {
        deleteForm.addEventListener('submit', async function (ev) {
            ev.preventDefault();
            const ok = window.CL && typeof window.CL.confirm === 'function'
                ? await window.CL.confirm({
                    title: 'Excluir conta definitivamente?',
                    message: (CL.data() && CL.data().deleteConfirm) || 'Tem certeza que deseja excluir sua conta permanentemente? Esta ação desativará seu perfil e anúncios.',
                    confirmText: 'Sim, excluir minha conta',
                    cancelText: 'Cancelar',
                    danger: true
                })
                : true;

            if (!ok) return;

            try {
                if (window.CL && typeof window.CL.toast === 'function') {
                    CL.toast('info', 'Excluindo conta...', 'Processando cancelamento do cadastro.');
                }
                await CL.api('/api/users/eu', { method: 'DELETE', error: 'Erro ao excluir conta' });
                window.location.href = '/';
            } catch (e) {
                if (window.CL && typeof window.CL.toast === 'function') {
                    CL.toast('error', 'Erro ao excluir conta', e.message || 'Não foi possível excluir a conta.');
                } else {
                    console.error(e.message);
                }
            }
        });
    }
})();

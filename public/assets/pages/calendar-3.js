// Migração para API: intercepta envios do calendário e feeds com suporte a notificações amigáveis (toast)
(function () {
    const ref = encodeURIComponent(CL.data().hash || '');

    // Notificações amigáveis (toast) para mensagens do calendário (flash / SSR)
    function checkFlashAlerts() {
        document.querySelectorAll('[data-flash-alert]').forEach(flashEl => {
            if (!window.CL || typeof window.CL.toast !== 'function') return;
            const toneAttr = (flashEl.getAttribute('data-flash-tone') || 'ok').toLowerCase();
            const type = toneAttr === 'error' ? 'error' : (toneAttr === 'warning' ? 'warning' : (toneAttr === 'info' ? 'info' : 'success'));
            const text = flashEl.textContent.trim();
            if (!text) return;

            let title = 'Calendário';
            if (type === 'error') {
                title = 'Atenção';
            } else if (type === 'warning') {
                title = 'Aviso';
            } else if (text.toLowerCase().includes('sincroniza') || text.toLowerCase().includes('sync')) {
                title = 'Sincronização';
            } else if (text.toLowerCase().includes('feed') || text.toLowerCase().includes('link') || text.toLowerCase().includes('externo')) {
                title = 'Calendário externo';
            } else {
                title = 'Alterações salvas';
            }

            CL.toast(type, title, text);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', checkFlashAlerts);
    } else {
        checkFlashAlerts();
    }

    // Erro na tela (sem alert): mensagem da API (json.error) no topo do formulário e, por campo, ao lado do input
    // (json.erros = {campo: msg}, os mesmos nomes do formulário web).
    function showError(form, e) {
        form.querySelectorAll('[data-api-error]').forEach(el => el.remove());
        form.querySelectorAll('[aria-invalid]').forEach(el => el.removeAttribute('aria-invalid'));
        const box = document.createElement('p');
        box.className = 'alert error';
        box.setAttribute('data-api-error', '');
        box.setAttribute('role', 'alert');
        box.textContent = e.message;
        const head = form.querySelector('h2');
        if (head) head.after(box); else form.prepend(box);
        const erros = (e.data && typeof e.data.erros === 'object' && e.data.erros) || {};
        Object.keys(erros).forEach(campo => {
            const input = form.querySelector('[name="' + CSS.escape(campo) + '"]');
            if (!input) return;
            input.setAttribute('aria-invalid', 'true');
            const msg = document.createElement('small');
            msg.className = 'alert error fine';
            msg.setAttribute('data-api-error', '');
            msg.textContent = String(erros[campo]);
            input.after(msg);
        });
    }

    // Recarrega a página; o aviso (ok ou erro) vem do flash na sessão gravado pela API (sem ?msg= na URL).
    function reloadPage() {
        window.location.reload();
    }

    // Formulário principal do calendário
    const calForm = document.querySelector('[data-cal-form]');
    if (calForm) {
        calForm.addEventListener('submit', async function (ev) {
            const btn = ev.submitter || document.activeElement;
            const data = Object.fromEntries(new FormData(calForm).entries());
            if (btn && btn.name === 'acao') {
                data.acao = btn.value;
            }
            if (data.acao === 'cotacao' || (btn && btn.hasAttribute && btn.hasAttribute('data-cal-quote'))) {
                return;
            }
            ev.preventDefault();
            try {
                await CL.api('/api/imoveis/' + ref + '/calendario', { method: 'POST', body: data, error: 'Erro ao salvar calendário' });
                // Recarrega para refletir as mudanças no grid (a mensagem flash virá com a página e abrirá o toast)
                reloadPage();
            } catch (e) {
                showError(calForm, e);
                if (window.CL && typeof window.CL.toast === 'function') {
                    const title = (e.status === 409) ? 'Conflito de datas' : 'Erro ao salvar calendário';
                    CL.toast('error', title, e.message || 'Verifique as informações do calendário.');
                }
            }
        });
    }

    // Importar feed
    const feedForm = document.querySelector('[data-feed-form]');
    if (feedForm) {
        feedForm.addEventListener('submit', async function (ev) {
            ev.preventDefault();
            const data = Object.fromEntries(new FormData(feedForm).entries());
            try {
                await CL.api('/api/imoveis/' + ref + '/ical/importar', {
                    method: 'POST',
                    // Mesmos nomes do formulário web (a API recusa url/title).
                    body: {
                        external_ical_url: data.external_ical_url || '',
                        feed_title: data.feed_title || '',
                        mode: data.mode || 'sync_only',
                        platform: data.platform || '',
                        property_url: data.property_url || '',
                        consent: data.consent || ''
                    },
                    error: 'Erro ao importar feed',
                });
                window.location.reload();
            } catch (e) {
                if (e.status === 502) {
                    // Feed salvo, mas o download falhou: recarrega para mostrar o feed (NOK) e o erro no topo.
                    reloadPage();
                    return;
                }
                showError(feedForm, e);
                if (window.CL && typeof window.CL.toast === 'function') {
                    CL.toast('error', 'Erro ao importar feed', e.message || 'Verifique o link informado.');
                }
            }
        });
    }

    // Excluir feed (forms dentro da tabela)
    document.querySelectorAll('form[action*="/ical/"][action*="/excluir"]').forEach(form => {
        form.addEventListener('submit', async function (ev) {
            ev.preventDefault();
            const match = form.action.match(/\/ical\/(\d+)\/excluir/);
            if (!match) return;
            if (!confirm('Remover este calendário?')) return;
            try {
                await CL.api('/api/imoveis/' + ref + '/ical/' + match[1], { method: 'DELETE', error: 'Erro ao remover' });
                window.location.reload();
            } catch (e) {
                if (window.CL && typeof window.CL.toast === 'function') {
                    CL.toast('error', 'Erro ao remover calendário', e.message || 'Não foi possível remover o calendário.');
                } else {
                    reloadPage();
                }
            }
        });
    });

    // Sincronizar feed agora via API com notificação imediata
    document.querySelectorAll('form[action*="/ical/"][action*="/sincronizar"]').forEach(form => {
        form.addEventListener('submit', async function (ev) {
            ev.preventDefault();
            const match = form.action.match(/\/ical\/(\d+)\/sincronizar/);
            if (!match) return;
            const button = form.querySelector('button[type="submit"]');
            if (button) button.disabled = true;
            try {
                const res = await CL.api('/api/imoveis/' + ref + '/ical/' + match[1] + '/sincronizar', { method: 'POST', error: 'Erro ao sincronizar' });
                if (window.CL && typeof window.CL.toast === 'function') {
                    CL.toast('success', 'Sincronização', res.message || 'Sincronização iniciada. Os dados serão atualizados em alguns segundos.');
                }
                window.location.reload();
            } catch (e) {
                if (window.CL && typeof window.CL.toast === 'function') {
                    CL.toast('error', 'Erro na sincronização', e.message || 'Falha ao iniciar sincronização.');
                }
                if (button) button.disabled = false;
            }
        });
    });

    // Exportação iCal por plataforma: toggles, geração, cópia e revogação.
    const icalExport = document.querySelector('[data-ical-export]');
    const exportToast = function (type, text) {
        if (window.CL && typeof CL.toast === 'function') {
            CL.toast(type, type === 'error' ? 'Atenção' : 'Exportação iCal', String(text || ''));
        }
    };
    if (icalExport) {
        const manual = icalExport.querySelector('[data-ical-export-setting="manual"]');
        const imported = icalExport.querySelector('[data-ical-export-setting="imported"]');
        const saveSettings = async function () {
            try {
                await CL.api('/api/imoveis/' + ref + '/ical-exports/settings', {
                    method: 'PUT',
                    body: {
                        ical_export_manual_blocks: manual && manual.checked ? 1 : 0,
                        ical_export_imported_events: imported && imported.checked ? 1 : 0
                    },
                    error: icalExport.dataset.errorSettings || ''
                });
                exportToast('success', icalExport.dataset.saved || '');
            } catch (e) {
                exportToast('error', (e && e.message) || icalExport.dataset.errorSettings || '');
            }
        };
        if (manual) manual.addEventListener('change', saveSettings);
        if (imported) imported.addEventListener('change', saveSettings);

        const exportForm = icalExport.querySelector('[data-ical-export-form]');
        if (exportForm) {
            exportForm.addEventListener('submit', async function (ev) {
                ev.preventDefault();
                const button = exportForm.querySelector('button[type="submit"]');
                if (button) button.disabled = true;
                const data = Object.fromEntries(new FormData(exportForm).entries());
                try {
                    await CL.api('/api/imoveis/' + ref + '/ical-exports', {
                        method: 'POST',
                        body: {
                            target_platform: data.target_platform || '',
                            label: data.label || ''
                        },
                        error: icalExport.dataset.errorCreate || ''
                    });
                    window.location.reload();
                } catch (e) {
                    exportToast('error', (e && e.message) || icalExport.dataset.errorCreate || '');
                    if (button) button.disabled = false;
                }
            });
        }
    }

    // Os links exportados ficam no grupo da esquerda; cópia e revogação valem para a página toda.
    document.querySelectorAll('[data-ical-export-copy]').forEach(function (button) {
        button.addEventListener('click', async function () {
            const input = button.closest('.ical-export-link')?.querySelector('[data-ical-export-link]');
            if (!input) return;
            try {
                if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                    await navigator.clipboard.writeText(input.value);
                } else {
                    input.select();
                    document.execCommand('copy');
                }
                exportToast('success', (icalExport && icalExport.dataset.copied) || '');
            } catch (e) {
                exportToast('error', (icalExport && icalExport.dataset.copyError) || '');
            }
        });
    });

    document.querySelectorAll('[data-ical-export-revoke]').forEach(function (button) {
        button.addEventListener('click', async function () {
            const message = (icalExport && icalExport.dataset.revokeConfirm) || '';
            const confirmed = (window.CL && typeof CL.confirm === 'function')
                ? await CL.confirm({ title: 'Revogar', message: message, confirmText: 'Revogar', cancelText: 'Cancelar', danger: true })
                : confirm(message);
            if (!confirmed) return;
            button.disabled = true;
            try {
                await CL.api('/api/imoveis/' + ref + '/ical-exports/' + button.getAttribute('data-export-id'), {
                    method: 'DELETE',
                    error: (icalExport && icalExport.dataset.errorRevoke) || ''
                });
                window.location.reload();
            } catch (e) {
                exportToast('error', (e && e.message) || (icalExport && icalExport.dataset.errorRevoke) || '');
                button.disabled = false;
            }
        });
    });

    // Grupo deslizante: alterna as seções de importar e exportar calendário.
    document.querySelectorAll('[data-sync-tabs]').forEach(function (group) {
        const buttons = group.querySelectorAll('[data-sync-tab]');
        const panels = group.querySelectorAll('[data-sync-panel]');
        function activate(name) {
            group.setAttribute('data-sync-active', name);
            buttons.forEach(function (button) {
                const on = button.getAttribute('data-sync-tab') === name;
                button.classList.toggle('is-on', on);
                button.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            panels.forEach(function (panel) {
                const on = panel.getAttribute('data-sync-panel') === name;
                panel.setAttribute('aria-hidden', on ? 'false' : 'true');
                if (on) {
                    panel.removeAttribute('inert');
                } else {
                    panel.setAttribute('inert', '');
                }
            });
        }
        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                activate(button.getAttribute('data-sync-tab') || 'import');
            });
        });
        activate(group.getAttribute('data-sync-active') || 'import');
    });

    // Verificar a titularidade de um feed iCal (desafio unificado no calendário).
    document.querySelectorAll('form[action*="/ical/"][action*="/verificar"]').forEach(function (form) {
        form.addEventListener('submit', async function (ev) {
            ev.preventDefault();
            const match = form.action.match(/\/ical\/(\d+)\/verificar/);
            if (!match) return;
            const button = form.querySelector('button[type="submit"]');
            if (button) button.disabled = true;
            try {
                const res = await CL.api('/api/imoveis/' + ref + '/ical/' + match[1] + '/verificar', {
                    method: 'POST',
                    error: 'Erro ao verificar calendário'
                });
                if (window.CL && typeof CL.toast === 'function') {
                    if (res && res.verified === true) {
                        CL.toast('success', 'Calendário verificado', res.message || 'Verificação concluída.');
                    } else {
                        CL.toast('info', 'Em análise pela equipe', res.message || 'Aguardando a revisão da equipe.');
                    }
                }
                window.location.reload();
            } catch (e) {
                if (window.CL && typeof CL.toast === 'function') {
                    CL.toast('error', 'Verificação', (e && e.message) || 'Não foi possível verificar o calendário.');
                }
                if (button) button.disabled = false;
            }
        });
    });
})();

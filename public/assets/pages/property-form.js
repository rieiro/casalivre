(function () {
    const form = document.getElementById('property-form');
    if (!form) return;
    const editing = form.dataset.editing === '1';
    const hash = form.dataset.hash;

    form.addEventListener('submit', async function (ev) {
        // Deixa o submit normal se for o botão de excluir fotos (tem data-delete-photos)
        if (ev.submitter && ev.submitter.hasAttribute('data-delete-photos')) {
            return;
        }
        ev.preventDefault();
        // rooms[i][beds][j][campo] e amenity[chave] viram objetos/listas (issue #20); form-data.js carrega antes.
        if (!window.CasaLivreForm) {
            alert(form.dataset.scriptError || 'Erro ao carregar a página. Recarregue e tente de novo.');
            return;
        }
        const submitBtn = form.querySelector('[data-save-house]');
        const submitSpan = submitBtn ? submitBtn.querySelector('span') : null;
        const origText = submitSpan ? submitSpan.textContent : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.classList.add('is-off');
            if (submitSpan) submitSpan.textContent = 'Salvando...';
        }

        const data = window.CasaLivreForm.toObject(form);
        const url = editing ? '/api/casas/' + encodeURIComponent(hash) : '/api/casas';
        try {
            const json = await CL.api(url, { method: editing ? 'PUT' : 'POST', body: data, error: 'Erro ao salvar imóvel' });
            const newHash = (json.casa && json.casa.id) || hash;
            if (!editing) {
                window.location.href = '/imoveis/' + encodeURIComponent(newHash) + '/editar';
            } else {
                if (submitSpan) submitSpan.textContent = origText;
                form.dispatchEvent(new CustomEvent('form-saved', { bubbles: true }));
                showToast('success', 'Imóvel atualizado com sucesso', 'Todas as alterações foram salvas.');
            }
        } catch (e) {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.classList.remove('is-off');
                if (submitSpan) submitSpan.textContent = origText;
            }
            showToast('error', 'Erro ao salvar imóvel', e.message || 'Verifique as informações e tente novamente.');
        }
    });

    // "Testar Place ID": valida e pré-visualiza a reputação do Google sem salvar o imóvel.
    // Clique duplo não dispara duas requisições (trava + janela de 400 ms).
    const googlePlaceInput = form.querySelector('[data-google-place-input]');
    const googlePlaceButton = form.querySelector('[data-verify-google-place]');
    const googlePlaceStatus = form.querySelector('[data-google-place-status]');
    if (googlePlaceInput && googlePlaceButton && googlePlaceStatus) {
        let googlePlaceBusy = false;
        let googlePlaceLastClick = 0;
        const showGooglePlaceStatus = function (text, ok) {
            googlePlaceStatus.textContent = String(text || '');
            googlePlaceStatus.hidden = false;
            googlePlaceStatus.classList.toggle('is-ok', ok === true);
            googlePlaceStatus.classList.toggle('is-error', ok === false);
        };
        googlePlaceButton.addEventListener('click', async function () {
            const now = Date.now();
            if (googlePlaceBusy || now - googlePlaceLastClick < 400) return;
            googlePlaceLastClick = now;
            const value = String(googlePlaceInput.value || '').trim();
            if (value === '') {
                showGooglePlaceStatus(googlePlaceButton.dataset.empty || '', false);
                return;
            }
            googlePlaceBusy = true;
            googlePlaceButton.disabled = true;
            try {
                const res = await CL.api('/api/google-place/verificar', {
                    method: 'POST',
                    body: { google_place_id: value }
                });
                const rep = (res && res.google_reputation) || {};
                const rating = (rep.rating === null || rep.rating === undefined)
                    ? '-'
                    : Number(rep.rating).toFixed(1).replace('.', ',');
                const count = String(Math.max(0, Number(rep.user_rating_count || 0)));
                const template = String(googlePlaceButton.dataset.ok || '');
                showGooglePlaceStatus(template.replace('%s', rating).replace('%d', count), true);
            } catch (e) {
                showGooglePlaceStatus((e && e.message) || '', false);
            } finally {
                googlePlaceBusy = false;
                googlePlaceButton.disabled = false;
            }
        });
    }

    // Verificação de anúncio em plataformas parceiras: adicionar, gerar, checar e remover.
    // A tabela é dirigida por data-ota-*; cada linha carrega o seu verification id, então o
    // "Verificar agora" sempre usa o id correto (inclusive depois de gerar o desafio).
    // A ficha OTA fica DEPOIS do </form> do imóvel: buscar no documento, não dentro de form.
    const otaRoot = document.querySelector('[data-ota-root]');
    if (otaRoot) {
        const el = CL.el;
        const ds = otaRoot.dataset;
        const otaBase = '/api/properties/' + encodeURIComponent(hash) + '/verifications';
        const otaTbody = otaRoot.querySelector('[data-ota-tbody]');
        const otaPlatform = otaRoot.querySelector('[data-ota-platform]');
        const otaPublic = otaRoot.querySelector('[data-ota-public-url]');
        const otaIcal = otaRoot.querySelector('[data-ota-ical-url]');
        const otaAdd = otaRoot.querySelector('[data-ota-add]');
        const otaMessage = otaRoot.querySelector('[data-ota-message]');
        const otaTimers = new WeakMap();

        const otaShowMessage = function (text) {
            if (!otaMessage) return;
            otaMessage.textContent = String(text || '');
            otaMessage.hidden = !text;
        };
        const otaSyncAdd = function () {
            if (!otaAdd) return;
            const ready = !!(otaPlatform && otaPublic && otaIcal)
                && String(otaPlatform.value || '').trim() !== ''
                && String(otaPublic.value || '').trim() !== ''
                && String(otaIcal.value || '').trim() !== '';
            otaAdd.disabled = !ready;
            otaAdd.classList.toggle('is-off', !ready);
        };
        const otaStatusInfo = function (status) {
            if (status === 'pending_challenge') return { kind: 'pending', label: ds.otaStatusPending || '' };
            if (status === 'verified') return { kind: 'verified', label: ds.otaStatusVerified || '' };
            if (status === 'failed') return { kind: 'failed', label: ds.otaStatusFailed || '' };
            return { kind: 'unverified', label: ds.otaStatusUnverified || '' };
        };
        const otaRenderRow = function (row) {
            const status = String(row.status || 'unverified');
            const info = otaStatusInfo(status);
            const showGenerate = status === 'unverified' || status === 'failed';
            const showCheck = status === 'pending_challenge';
            return el('tr', {
                'data-ota-row': '',
                'data-ota-verification-id': String(row.id || ''),
                'data-ota-status': status
            },
                el('td', {},
                    el('strong', {}, String(row.platform_label || row.platform || '')),
                    el('br'),
                    el('span', { className: 'muted fine' }, String(row.public_url || ''))),
                el('td', {}, el('span', { className: 'prop-ota-status-badge is-' + info.kind, 'data-ota-row-status': '' }, info.label)),
                el('td', { 'data-ota-row-date': '' }, String(row.challenge_date_br || ds.otaNever || '—')),
                el('td', { 'data-ota-row-checked': '' }, String(row.last_checked_at_br || ds.otaNever || '—')),
                el('td', {}, el('div', { className: 'sync-actions' },
                    el('button', { type: 'button', className: 'primary', 'data-ota-generate': '', hidden: !showGenerate }, ds.otaGenerateLabel || ''),
                    el('button', { type: 'button', className: 'primary', 'data-ota-check': '', hidden: !showCheck }, ds.otaCheckLabel || ''),
                    el('button', { type: 'button', className: 'danger', 'data-ota-remove': '' }, ds.otaRemoveLabel || ''))));
        };
        const otaLoadRows = async function () {
            if (!otaTbody) return;
            try {
                const res = await CL.api(otaBase);
                const rows = Array.isArray(res.verifications) ? res.verifications : [];
                otaTbody.replaceChildren();
                if (rows.length === 0) {
                    otaTbody.appendChild(el('tr', {}, el('td', { colspan: '5', className: 'muted' }, ds.otaEmpty || '')));
                    return;
                }
                rows.forEach(function (row) {
                    otaTbody.appendChild(otaRenderRow(row));
                });
            } catch (e) {
                otaShowMessage((e && e.message) || '');
            }
        };
        const otaCooldown = function (button, seconds) {
            if (!button) return;
            const previous = otaTimers.get(button);
            if (previous) clearInterval(previous);
            let left = Math.max(1, Number(seconds) || 60);
            const render = function () {
                button.textContent = String(ds.otaCooldown || 'Verificar novamente em %ds...').replace('%d', String(left));
            };
            button.disabled = true;
            render();
            const timer = setInterval(function () {
                left -= 1;
                if (left <= 0) {
                    clearInterval(timer);
                    otaTimers.delete(button);
                    button.disabled = false;
                    button.textContent = ds.otaCheckLabel || '';
                    return;
                }
                render();
            }, 1000);
            otaTimers.set(button, timer);
        };
        const otaGenerate = async function (button, id) {
            button.disabled = true;
            try {
                const res = await CL.api(otaBase + '/' + id + '/generate', { method: 'POST' });
                otaShowMessage(res.message || '');
                await otaLoadRows();
            } catch (e) {
                otaShowMessage((e && e.message) || '');
                button.disabled = false;
            }
        };
        const otaCheck = async function (button, id) {
            button.disabled = true;
            button.textContent = ds.otaChecking || 'Verificando...';
            try {
                const res = await CL.api(otaBase + '/' + id + '/check', { method: 'POST' });
                otaShowMessage(res.message || '');
                if (res.status === 'verified' || res.status === 'expired') {
                    await otaLoadRows();
                    return;
                }
                otaCooldown(button, res.retry_after || 60);
            } catch (e) {
                otaShowMessage((e && e.message) || '');
                if (e && e.status === 429 && e.data && e.data.retry_after) {
                    otaCooldown(button, e.data.retry_after);
                } else {
                    button.disabled = false;
                    button.textContent = ds.otaCheckLabel || '';
                }
            }
        };
        const otaRemove = async function (button, id) {
            const confirmed = (window.CL && typeof window.CL.confirm === 'function')
                ? await window.CL.confirm({
                    title: ds.otaRemoveLabel || '',
                    message: ds.otaRemoveConfirm || '',
                    confirmText: ds.otaRemoveLabel || '',
                    cancelText: 'Cancelar',
                    danger: true
                })
                : true;
            if (!confirmed) return;
            button.disabled = true;
            try {
                await CL.api(otaBase + '/' + id, { method: 'DELETE' });
                otaShowMessage(ds.otaRemoved || '');
                await otaLoadRows();
            } catch (e) {
                otaShowMessage((e && e.message) || '');
                button.disabled = false;
            }
        };

        if (otaPlatform) otaPlatform.addEventListener('change', otaSyncAdd);
        if (otaPublic) otaPublic.addEventListener('input', otaSyncAdd);
        if (otaIcal) otaIcal.addEventListener('input', otaSyncAdd);
        otaSyncAdd();

        if (otaAdd) {
            otaAdd.addEventListener('click', async function () {
                otaAdd.disabled = true;
                try {
                    await CL.api(otaBase, {
                        method: 'POST',
                        body: {
                            platform: String(otaPlatform ? otaPlatform.value : ''),
                            public_url: String(otaPublic ? otaPublic.value : '').trim(),
                            ical_url: String(otaIcal ? otaIcal.value : '').trim()
                        }
                    });
                    if (otaIcal) otaIcal.value = '';
                    if (otaPublic) otaPublic.value = '';
                    otaShowMessage(ds.otaAdded || '');
                    await otaLoadRows();
                } catch (e) {
                    otaShowMessage((e && e.message) || '');
                } finally {
                    otaSyncAdd();
                }
            });
        }

        if (otaTbody) {
            otaTbody.addEventListener('click', function (ev) {
                const button = ev.target.closest('button');
                const row = button ? button.closest('[data-ota-row]') : null;
                if (!button || !row) return;
                const id = Number(row.getAttribute('data-ota-verification-id') || 0);
                if (!id) return;
                if (button.hasAttribute('data-ota-generate')) {
                    otaGenerate(button, id);
                } else if (button.hasAttribute('data-ota-check')) {
                    otaCheck(button, id);
                } else if (button.hasAttribute('data-ota-remove')) {
                    otaRemove(button, id);
                }
            });
        }
    }

    if (!editing || !hash) return;

    function showToast(type, title, message) {
        if (window.CL && typeof window.CL.toast === 'function') {
            return window.CL.toast(type, title, message);
        }
        let container = document.getElementById('prop-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'prop-toast-container';
            container.className = 'prop-toast-container';
            container.setAttribute('aria-live', 'polite');
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.className = 'prop-toast prop-toast-' + type;
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');

        const iconSvg = type === 'success'
            ? '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>'
            : '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';

        toast.innerHTML = '<div class="prop-toast-icon">' + iconSvg + '</div>' +
            '<div class="prop-toast-body">' +
            '<strong class="prop-toast-title">' + title + '</strong>' +
            (message ? '<p class="prop-toast-desc">' + message + '</p>' : '') +
            '</div>' +
            '<button type="button" class="prop-toast-close" aria-label="Fechar notificação">✕</button>';

        const closeBtn = toast.querySelector('.prop-toast-close');
        const dismiss = () => {
            toast.classList.add('is-hiding');
            setTimeout(() => {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            }, 300);
        };

        if (closeBtn) closeBtn.addEventListener('click', dismiss);
        setTimeout(dismiss, 4000);

        container.appendChild(toast);
        void toast.offsetWidth;
        toast.classList.add('is-visible');
    }

    // --- 1. Auto-polling: resolve fotos em processamento sem precisar de F5 ---
    function setupPhotoPoller() {
        if (!document.querySelector('[data-photo-wait]')) return;

        let attempts = 0;
        const maxAttempts = 30;

        const timer = setInterval(async () => {
            attempts++;
            const pendingNodes = document.querySelectorAll('[data-photo-wait]');
            if (attempts > maxAttempts || pendingNodes.length === 0) {
                clearInterval(timer);
                return;
            }
            try {
                const res = await CL.api('/api/imoveis/' + encodeURIComponent(hash) + '/editar');
                const photos = (res && res.fotos) || [];
                let stillPending = false;

                photos.forEach((photo) => {
                    const tile = document.querySelector('.photo-tile[data-photo-id="' + photo.id + '"]');
                    if (!tile) return;
                    const waitSpan = tile.querySelector('[data-photo-wait]');
                    if (waitSpan && photo.status === 'ready') {
                        const path = String(photo.path || '');
                        const thumbUrl = CL.photoUrl(path.replace(/-1920\.webp$/, '-320.webp'));
                        const img = CL.el('img', { src: thumbUrl, alt: 'Foto', loading: 'lazy' });
                        waitSpan.replaceWith(img);
                        tile.dataset.photoStatus = 'ready';
                    } else if (waitSpan && photo.status === 'failed') {
                        waitSpan.classList.add('is-failed');
                        waitSpan.removeAttribute('data-photo-wait');
                        tile.dataset.photoStatus = 'failed';
                    } else if (photo.status === 'processing') {
                        stillPending = true;
                    }
                });

                if (!stillPending) {
                    clearInterval(timer);
                }
            } catch (_) {
                // Transient error, continue polling
            }
        }, 1500);
    }

    setupPhotoPoller();

    // --- 2. Canvas downscaling & async upload ---
    async function downscalePhoto(file) {
        if (!file.type.match(/^image\/(jpeg|png|webp)$/) || file.size < 1500000 || typeof createImageBitmap !== 'function') {
            return file;
        }
        try {
            const bitmap = await createImageBitmap(file);
            const maxDim = 1920;
            let w = bitmap.width;
            let h = bitmap.height;
            if (w <= maxDim && h <= maxDim) return file;
            if (w > h) {
                h = Math.round((h * maxDim) / w);
                w = maxDim;
            } else {
                w = Math.round((w * maxDim) / h);
                h = maxDim;
            }
            const canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = h;
            const ctx = canvas.getContext('2d');
            if (!ctx) return file;
            ctx.drawImage(bitmap, 0, 0, w, h);
            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/webp', 0.85));
            if (blob && blob.size < file.size) {
                return new File([blob], file.name.replace(/\.[^.]+$/, '.webp'), { type: 'image/webp' });
            }
            return file;
        } catch (_) {
            return file;
        }
    }

    // --- 3. Pinpoint Map Location ---
    function setupPinpointMap() {
        const mapBox = document.getElementById('property-pinpoint-map');
        if (!mapBox) return;

        const fieldLat = document.getElementById('field-lat');
        const fieldLng = document.getElementById('field-lng');
        const dispLat = document.getElementById('pinpoint-lat-disp');
        const dispLng = document.getElementById('pinpoint-lng-disp');

        let currentLat = parseFloat(mapBox.dataset.lat || (fieldLat && fieldLat.value) || mapBox.dataset.cityLat || '-27.5954');
        let currentLng = parseFloat(mapBox.dataset.lng || (fieldLng && fieldLng.value) || mapBox.dataset.cityLng || '-48.5480');

        function updateCoords(lat, lng) {
            currentLat = lat;
            currentLng = lng;
            if (fieldLat) {
                fieldLat.value = lat.toFixed(6);
                fieldLat.dispatchEvent(new Event('input', { bubbles: true }));
                fieldLat.dispatchEvent(new Event('change', { bubbles: true }));
            }
            if (fieldLng) {
                fieldLng.value = lng.toFixed(6);
                fieldLng.dispatchEvent(new Event('input', { bubbles: true }));
                fieldLng.dispatchEvent(new Event('change', { bubbles: true }));
            }
            if (dispLat) dispLat.textContent = lat.toFixed(5);
            if (dispLng) dispLng.textContent = lng.toFixed(5);

            const saveBtn = form.querySelector('[data-save-house]');
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.classList.remove('is-off');
            }
        }

        if (!window.maplibregl || !window.pmtiles) {
            return;
        }

        try {
            maplibregl.setWorkerUrl(mapBox.dataset.worker);
            const protocol = new pmtiles.Protocol();
            maplibregl.addProtocol('pmtiles', protocol.tile);

            const origin = window.location.origin;
            const styleUrl = mapBox.dataset.style ? new URL(mapBox.dataset.style, origin).href : null;

            const map = new maplibregl.Map({
                container: 'property-pinpoint-map',
                style: styleUrl,
                center: [currentLng, currentLat],
                zoom: 14,
                attributionControl: false
            });

            map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');

            const marker = new maplibregl.Marker({ draggable: true, color: '#047857' })
                .setLngLat([currentLng, currentLat])
                .addTo(map);

            marker.on('dragend', function () {
                const lngLat = marker.getLngLat();
                updateCoords(lngLat.lat, lngLat.lng);
            });

            map.on('click', function (e) {
                marker.setLngLat(e.lngLat);
                updateCoords(e.lngLat.lat, e.lngLat.lng);
            });

            const cepInput = form.querySelector('[data-cep]');
            if (cepInput) {
                let lastCep = cepInput.value.replace(/\D/g, '');
                cepInput.addEventListener('change', async function () {
                    const digits = cepInput.value.replace(/\D/g, '').slice(0, 8);
                    if (digits.length === 8 && digits !== lastCep) {
                        lastCep = digits;
                        try {
                            const res = await fetch('/api/ceps?q=' + digits);
                            const json = await res.json();
                            if (json && json.lat && json.lng) {
                                const newLat = parseFloat(json.lat);
                                const newLng = parseFloat(json.lng);
                                marker.setLngLat([newLng, newLat]);
                                map.flyTo({ center: [newLng, newLat], zoom: 14 });
                                updateCoords(newLat, newLng);
                            }
                        } catch (_) {}
                    }
                });
            }
        } catch (e) {
            console.error('Error initializing pinpoint map:', e);
        }
    }
    setupPinpointMap();

    const uploadForm = document.querySelector('form[data-upload-limit]');
    if (uploadForm) {
        const fileInput = uploadForm.querySelector('input[type="file"]');
        const submitBtn = uploadForm.querySelector('button[type="submit"]');

        // Automatically trigger upload as soon as user selects photos in the file dialog
        if (fileInput) {
            fileInput.addEventListener('change', function () {
                if (fileInput.files && fileInput.files.length > 0) {
                    uploadForm.requestSubmit();
                }
            });
        }

        uploadForm.addEventListener('submit', async function (ev) {
            if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
                return;
            }
            ev.preventDefault();
            const originalBtnText = submitBtn ? submitBtn.textContent : '';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Enviando...';
            }

            const dropLabel = uploadForm.querySelector('.prop-upload-label');
            const dropTitle = uploadForm.querySelector('.prop-upload-title');
            const dropInfo = uploadForm.querySelector('.prop-upload-info');
            const origTitle = dropTitle ? dropTitle.textContent : '';
            const origInfo = dropInfo ? dropInfo.textContent : '';

            const rawFiles = Array.from(fileInput.files);
            if (dropLabel) dropLabel.classList.add('is-uploading');
            if (dropTitle) dropTitle.textContent = 'Enviando ' + rawFiles.length + ' foto(s)...';
            if (dropInfo) dropInfo.textContent = 'Otimizando e enviando imagens, por favor aguarde...';

            try {
                const formData = new FormData();
                const csrfInput = uploadForm.querySelector('input[name="_csrf"]');
                if (csrfInput) {
                    formData.append('_csrf', csrfInput.value);
                }

                for (let i = 0; i < rawFiles.length; i++) {
                    const scaled = await downscalePhoto(rawFiles[i]);
                    formData.append('fotos[]', scaled);
                }

                const res = await fetch('/api/casas/' + encodeURIComponent(hash) + '/fotos', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-Token': (CL.data() && CL.data().csrf) || (csrfInput ? csrfInput.value : '')
                    }
                });

                if (res.ok) {
                    showToast('success', 'Fotos enviadas!', 'Suas fotos foram salvas com sucesso.');
                    setTimeout(() => window.location.reload(), 600);
                } else {
                    const data = await res.json().catch(() => ({}));
                    showToast('error', 'Erro ao enviar fotos', data.error || 'Não foi possível salvar as fotos enviadas.');
                    if (dropLabel) dropLabel.classList.remove('is-uploading');
                    if (dropTitle) dropTitle.textContent = origTitle;
                    if (dropInfo) dropInfo.textContent = origInfo;
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = originalBtnText;
                    }
                }
            } catch (err) {
                showToast('error', 'Falha de conexão', 'Não foi possível enviar as fotos. Verifique sua conexão.');
                if (dropLabel) dropLabel.classList.remove('is-uploading');
                if (dropTitle) dropTitle.textContent = origTitle;
                if (dropInfo) dropInfo.textContent = origInfo;
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalBtnText;
                }
            }
        });
    }

    // --- 4. Friendly Photo Deletion Notification & Confirmation ---
    function setupPhotoDeletion() {
        // Exibir notificação amigável de sucesso se recém-excluído via reload/flash
        try {
            const deletedNotice = sessionStorage.getItem('photo_deleted_toast');
            if (deletedNotice) {
                sessionStorage.removeItem('photo_deleted_toast');
                const data = JSON.parse(deletedNotice);
                showToast(data.type || 'success', data.title || 'Fotos excluídas com sucesso', data.message || 'As fotos selecionadas foram removidas.');
            }
        } catch (_) {}

        const galleryForm = document.querySelector('form.prop-gallery-form');
        if (!galleryForm) return;

        galleryForm.addEventListener('submit', async function (ev) {
            const isSingleRemove = ev.submitter && ev.submitter.hasAttribute('data-photo-remove');
            const selectedCheckboxes = galleryForm.querySelectorAll('.photo-check:checked');
            const count = selectedCheckboxes.length;

            if (!isSingleRemove && count === 0) {
                ev.preventDefault();
                return;
            }

            ev.preventDefault();

            const title = isSingleRemove
                ? 'Remover foto com falha?'
                : (count === 1 ? 'Apagar foto selecionada?' : 'Apagar fotos selecionadas?');
            const message = isSingleRemove
                ? 'A imagem com falha será removida permanentemente. Deseja continuar?'
                : (count === 1
                    ? 'A foto selecionada será removida permanentemente do imóvel. Deseja continuar?'
                    : 'As ' + count + ' fotos selecionadas serão removidas permanentemente do imóvel. Deseja continuar?');
            const confirmBtnText = isSingleRemove ? 'Sim, remover' : 'Sim, apagar';

            const confirmed = window.CL && typeof window.CL.confirm === 'function'
                ? await window.CL.confirm({
                    title: title,
                    message: message,
                    confirmText: confirmBtnText,
                    cancelText: 'Cancelar',
                    danger: true
                })
                : window.confirm(galleryForm.dataset.confirm || 'Apagar fotos?');

            if (!confirmed) {
                return;
            }

            try {
                sessionStorage.setItem('photo_deleted_toast', JSON.stringify({
                    type: 'success',
                    title: 'Fotos excluídas com sucesso',
                    message: isSingleRemove
                        ? 'A foto com falha foi removida.'
                        : (count === 1 ? 'A foto selecionada foi removida.' : count + ' fotos foram removidas com sucesso.')
                }));
            } catch (_) {}

            showToast('info', 'Excluindo fotos...', 'Removendo imagens do imóvel.');

            if (isSingleRemove && ev.submitter.getAttribute('formaction')) {
                galleryForm.setAttribute('action', ev.submitter.getAttribute('formaction'));
            }

            HTMLFormElement.prototype.submit.call(galleryForm);
        });
    }
    setupPhotoDeletion();

    const deletePropForm = document.querySelector('form[action*="/excluir"]:not([action*="/fotos/"])');
    if (deletePropForm) {
        deletePropForm.addEventListener('submit', async function (ev) {
            ev.preventDefault();
            const confirmed = window.CL && typeof window.CL.confirm === 'function'
                ? await window.CL.confirm({
                    title: 'Inativar este imóvel?',
                    message: deletePropForm.dataset.confirmSubmit || 'O imóvel deixará de aparecer nas buscas e na vitrine. Deseja realmente inativar?',
                    confirmText: 'Sim, inativar imóvel',
                    cancelText: 'Cancelar',
                    danger: true
                })
                : true;

            if (confirmed) {
                if (window.CL && typeof window.CL.toast === 'function') {
                    window.CL.toast('info', 'Inativando imóvel...', 'Redirecionando para o painel.');
                }
                deletePropForm.submit();
            }
        });
    }
})();

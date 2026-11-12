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
        const data = window.CasaLivreForm.toObject(form);
        const url = editing ? '/api/casas/' + encodeURIComponent(hash) : '/api/casas';
        try {
            const json = await CL.api(url, { method: editing ? 'PUT' : 'POST', body: data, error: 'Erro ao salvar imóvel' });
            const newHash = (json.casa && json.casa.id) || hash;
            if (!editing) {
                window.location.href = '/imoveis/' + encodeURIComponent(newHash) + '/editar';
            } else {
                alert('Imóvel atualizado');
                window.location.reload();
            }
        } catch (e) {
            alert(e.message);
        }
    });

    if (!editing || !hash) return;

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

    const uploadForm = document.querySelector('form[data-upload-limit]');
    if (uploadForm) {
        const fileInput = uploadForm.querySelector('input[type="file"]');
        const submitBtn = uploadForm.querySelector('button[type="submit"]');

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

            try {
                const formData = new FormData();
                const csrfInput = uploadForm.querySelector('input[name="_csrf"]');
                if (csrfInput) {
                    formData.append('_csrf', csrfInput.value);
                }

                const rawFiles = Array.from(fileInput.files);
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
                    window.location.reload();
                } else {
                    const data = await res.json().catch(() => ({}));
                    alert(data.error || 'Erro ao enviar fotos');
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = originalBtnText;
                    }
                }
            } catch (err) {
                alert('Falha de conexão ao enviar fotos');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalBtnText;
                }
            }
        });
    }
})();

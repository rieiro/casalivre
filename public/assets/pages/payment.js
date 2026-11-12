(function () {
    "use strict";

    // 30-minute hold timer
    const timerEl = document.getElementById("hold-timer");
    const expiresAt = timerEl ? parseInt(timerEl.getAttribute("data-expires") || "0", 10) : 0;

    function updateTimer() {
        if (!timerEl || expiresAt === 0) return;
        const now = Math.floor(Date.now() / 1000);
        const remaining = Math.max(0, expiresAt - now);
        const minutes = Math.floor(remaining / 60);
        const seconds = remaining % 60;
        const str = String(minutes).padStart(2, "0") + ":" + String(seconds).padStart(2, "0");
        timerEl.textContent = str;
        if (remaining <= 0) {
            timerEl.textContent = "Tempo esgotado";
            const timerBox = timerEl.closest(".payment-hold-timer-box");
            if (timerBox) {
                timerBox.classList.add("is-expired");
            }
        } else if (remaining < 300) {
            const timerBox = timerEl.closest(".payment-hold-timer-box");
            if (timerBox) {
                timerBox.classList.add("is-urgent");
            }
        }
    }

    if (timerEl && expiresAt > 0) {
        updateTimer();
        setInterval(updateTimer, 1000);
    }

    // Copy Pix Button with Premium Feedback
    const copyBtn = document.getElementById("btn-copy-pix");
    const pixInput = document.getElementById("pix-payload");
    const copyStatus = document.getElementById("pix-copy-status");

    if (pixInput) {
        pixInput.addEventListener("click", function () {
            pixInput.select();
            pixInput.setSelectionRange(0, 99999);
        });
    }

    if (copyBtn && pixInput) {
        const originalHtml = copyBtn.innerHTML;
        let resetTimeout = null;

        copyBtn.addEventListener("click", function () {
            pixInput.select();
            pixInput.setSelectionRange(0, 99999);

            const textToCopy = pixInput.value.trim();
            if (!textToCopy) return;

            function onCopySuccess() {
                copyBtn.classList.add("is-copied");
                copyBtn.innerHTML = "<svg width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='3' aria-hidden='true'><polyline points='20 6 9 17 4 12'/></svg><span>✓ Código Pix Copiado com Sucesso!</span>";
                if (copyStatus) {
                    copyStatus.textContent = "Código copiado! Agora abra o app do seu banco e cole na opção Pix Copia e Cola.";
                }

                if (resetTimeout) clearTimeout(resetTimeout);
                resetTimeout = setTimeout(function () {
                    copyBtn.classList.remove("is-copied");
                    copyBtn.innerHTML = originalHtml;
                    if (copyStatus) copyStatus.textContent = "";
                }, 4500);
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(textToCopy).then(onCopySuccess).catch(function () {
                    // Fallback to execCommand
                    document.execCommand("copy");
                    onCopySuccess();
                });
            } else {
                document.execCommand("copy");
                onCopySuccess();
            }
        });
    }
})();

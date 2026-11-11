(function () {
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
            timerEl.textContent = "Expirado";
            timerEl.classList.add("alert", "error");
        }
    }

    if (timerEl && expiresAt > 0) {
        updateTimer();
        setInterval(updateTimer, 1000);
    }

    // Copy Pix Button
    const copyBtn = document.getElementById("btn-copy-pix");
    const pixInput = document.getElementById("pix-payload");
    const copyStatus = document.getElementById("pix-copy-status");

    if (copyBtn && pixInput) {
        copyBtn.addEventListener("click", function () {
            pixInput.select();
            pixInput.setSelectionRange(0, 99999);
            navigator.clipboard.writeText(pixInput.value).then(function () {
                if (copyStatus) copyStatus.textContent = "✓ Código Pix copiado para a área de transferência!";
            }).catch(function () {
                if (copyStatus) copyStatus.textContent = "Selecione e copie o texto acima.";
            });
        });
    }
})();

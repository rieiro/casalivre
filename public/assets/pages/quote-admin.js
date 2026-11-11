(function () {
    const btn = document.getElementById("btn-copy-wa");
    const status = document.getElementById("copy-status");
    if (btn) {
        btn.addEventListener("click", function () {
            const msg = btn.getAttribute("data-msg") || "";
            navigator.clipboard.writeText(msg).then(function () {
                if (status) {
                    status.textContent = "✓ Mensagem e link copiados para a área de transferência! Cole no WhatsApp do hóspede.";
                }
            }).catch(function () {
                if (status) {
                    status.textContent = "Selecione e copie o link acima.";
                }
            });
        });
    }
})();

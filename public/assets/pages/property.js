// Detalhe do imóvel via API, montado sem innerHTML (textContent/createElement).
(function () {
    const el = CL.el;
    const page = CL.data();
    const hash = String(page.hash || "");
    const params = new URLSearchParams(window.location.search);
    const box = document.getElementById("property-detail");
    if (!box) return;

    CL.api("/api/imoveis/" + encodeURIComponent(hash) + "?" + params.toString())
        .then((data) => {
            const quote = data.quote ? (data.quote.nightly + " / noite (" + data.quote.nights + " noites)") : ("R$ " + data.nightly_price + " / noite");
            
            // Amenities tags
            const amenityLabels = {
                wifi: "Wi-Fi", kitchen: "Cozinha", pool: "Piscina", bbq: "Churrasqueira",
                garage: "Garagem", ac: "Ar condicionado", tv: "TV", workspace: "Espaço de trabalho",
                washer: "Máquina de lavar", iron: "Ferro de passar", elevator: "Elevador",
                sea_view: "Vista para o mar", balcony: "Varanda", pets: "Aceita pets"
            };
            const amenityElements = (data.amenities || []).map((key) => {
                const label = amenityLabels[key] || key;
                return el("span", { className: "badge" }, "✓ " + label);
            });
            const amenitiesBox = amenityElements.length > 0 ? el("div", { className: "panel mt-16" },
                el("h3", {}, "Comodidades oferecidas"),
                el("div", { className: "cover-actions mt-8" }, ...amenityElements)
            ) : null;

            // Details and rules
            const detailsBox = el("div", { className: "panel mt-16" },
                el("h3", {}, "Regras e horários"),
                el("p", {}, "Check-in: a partir das " + (data.checkin_time || "15:00") + " · Check-out: até " + (data.checkout_time || "11:00")),
                el("p", {}, "Estadia mínima: " + (data.min_nights || 1) + " noite(s)" + (data.cleaning_fee ? " · Taxa de limpeza: R$ " + data.cleaning_fee : ""))
            );

            // Guest Calendar
            const blockedMap = {};
            (data.blocked_days || []).forEach((b) => {
                if (b.blocked) blockedMap[b.day] = true;
            });

            function buildMonth(offset) {
                const now = new Date();
                const target = new Date(now.getFullYear(), now.getMonth() + offset, 1);
                const monthName = target.toLocaleDateString("pt-BR", { month: "long", year: "numeric" });
                const daysInMonth = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate();
                const startDay = (target.getDay() + 6) % 7; // Monday = 0

                const dayCells = [];
                for (let i = 0; i < startDay; i++) {
                    dayCells.push(el("span", {}));
                }
                for (let d = 1; d <= daysInMonth; d++) {
                    const iso = target.getFullYear() + "-" + String(target.getMonth() + 1).padStart(2, "0") + "-" + String(d).padStart(2, "0");
                    const isBlocked = blockedMap[iso];
                    const isPast = new Date(iso + "T23:59:59") < now;
                    const cellClass = (isBlocked || isPast) ? "cal-day sistema" : "cal-day";
                    const btn = el("button", {
                        type: "button",
                        className: cellClass,
                        disabled: (isBlocked || isPast),
                        title: isBlocked ? "Data indisponível" : "Data disponível"
                    }, el("span", {}, String(d)));
                    dayCells.push(btn);
                }

                const weekdays = ["Seg", "Ter", "Qua", "Qui", "Sex", "Sáb", "Dom"].map((w) => el("span", {}, w));

                return el("section", { className: "cal-month is-shown mt-12" },
                    el("h4", { className: "mt-8" }, monthName.charAt(0).toUpperCase() + monthName.slice(1)),
                    el("div", { className: "cal-weekdays mt-8" }, ...weekdays),
                    el("div", { className: "cal-grid" }, ...dayCells)
                );
            }

            const calendarBox = el("div", { className: "panel mt-16" },
                el("h3", {}, "Calendário de Disponibilidade"),
                el("ul", { className: "cal-legend mt-8" },
                    el("li", {}, el("span", { className: "swatch open" }), " Disponível para reserva"),
                    el("li", {}, el("span", { className: "swatch sistema" }), " Bloqueado / Reservado")
                ),
                el("div", { className: "cal-months" }, buildMonth(0), buildMonth(1))
            );

            // Review form
            const form = el("form", { className: "panel mt-16", id: "review-form", method: "post", action: "/imoveis/" + encodeURIComponent(hash) + "/avaliar" },
                el("h2", {}, "Avaliar estadia"),
                el("input", { type: "hidden", name: "_csrf", value: String(page.csrf || "") }),
                el("label", {}, "Nome ", el("input", { name: "author", required: true })),
                el("label", {}, "Nota (1 a 10) ", el("input", { type: "number", name: "score", min: "1", max: "10", required: true })),
                el("label", {}, "Comentário ", el("textarea", { name: "comment", rows: "3" })),
                el("button", { className: "primary", type: "submit" }, "Publicar avaliação"));

            box.replaceChildren(...[
                el("h1", {}, data.title || ""),
                el("p", {}, [data.neighborhood, data.city].filter(Boolean).join(" · ") + " · até " + data.max_guests + " hóspedes · " + data.bedrooms + " quartos · " + data.beds + " camas"),
                data.score ? el("p", {}, "Nota dos hóspedes: " + data.score + " / 10") : null,
                el("div", { className: "gallery" }, (data.photos || []).map((p) => CL.photoImg(p.path, "(max-width: 600px) 50vw, 300px", ""))),
                el("p", { className: "mt-12" }, data.description || ""),
                el("p", { className: "price" }, quote),
                amenitiesBox,
                detailsBox,
                calendarBox,
                form,
            ].filter(Boolean));
        })
        .catch(() => {
            box.replaceChildren(el("p", {}, "Imóvel não encontrado."));
        });
})();

// Detalhe do imóvel via API, montado sem innerHTML (textContent/createElement) com design luxury.
(function () {
    const el = CL.el;
    const page = CL.data();
    const hash = String(page.hash || "");
    const params = new URLSearchParams(window.location.search);
    const box = document.getElementById("property-detail");
    if (!box) return;

    CL.api("/api/imoveis/" + encodeURIComponent(hash) + "?" + params.toString())
        .then((data) => {
            const nightly = (data.quote && data.quote.nightly) ? data.quote.nightly : data.nightly_price;
            const quoteText = data.quote ? (data.quote.nightly + " / noite (" + data.quote.nights + " noites)") : ("R$ " + data.nightly_price + " / noite");
            const backUrl = (params.get("volta") && CL.safeHref(params.get("volta"))) || "/";

            // 1. Top Navigation
            const topNav = el("div", { className: "prop-view-top-nav" },
                el("a", { className: "prop-view-back", href: backUrl },
                    "← Voltar à busca"
                )
            );

            // 2. Header Hero
            const metaItems = [
                data.score ? el("span", { className: "prop-view-score-badge" }, "★ " + Number(data.score).toFixed(1).replace(".", ",")) : null,
                data.score ? el("span", { className: "prop-view-meta-dot" }, "·") : null,
                el("span", { className: "prop-view-meta-item" }, "📍 " + [data.city, data.neighborhood].filter(Boolean).join(", ")),
                el("span", { className: "prop-view-meta-dot" }, "·"),
                el("span", { className: "prop-view-meta-item" }, "👥 até " + data.max_guests + " hóspedes"),
                el("span", { className: "prop-view-meta-dot" }, "·"),
                el("span", { className: "prop-view-meta-item" }, "🚪 " + data.bedrooms + " quartos"),
                el("span", { className: "prop-view-meta-dot" }, "·"),
                el("span", { className: "prop-view-meta-item" }, "🛏️ " + data.beds + " camas"),
            ].filter(Boolean);

            const header = el("header", { className: "prop-view-header" },
                el("span", { className: "prop-view-eyebrow" }, "ALUGUEL POR TEMPORADA · " + String(data.city || "").toUpperCase()),
                el("h1", { className: "prop-view-title" }, data.title || ""),
                el("div", { className: "prop-view-meta" }, ...metaItems)
            );

            // 3. Luxury Photo Mosaic Gallery
            const photos = data.photos || [];
            let galleryMosaic;
            if (photos.length === 0) {
                galleryMosaic = el("div", { className: "prop-view-mosaic" },
                    el("div", { className: "prop-view-photo-single" },
                        el("img", { src: "/assets/placeholder.svg", alt: "Foto de " + (data.title || ""), loading: "eager" })
                    )
                );
            } else if (photos.length === 1) {
                galleryMosaic = el("div", { className: "prop-view-mosaic" },
                    el("div", { className: "prop-view-photo-single" },
                        CL.photoImg(photos[0].path, "(max-width: 900px) 100vw, 1200px", "Foto de " + (data.title || ""))
                    )
                );
            } else {
                const secondaryPhotos = photos.slice(1, 5).map((photo, idx) => {
                    const isFourth = idx === 3 && photos.length > 5;
                    const item = el("div", { className: "prop-view-photo-item" + (isFourth ? " has-more" : "") },
                        CL.photoImg(photo.path, "(max-width: 600px) 50vw, 350px", "Foto de " + (data.title || ""))
                    );
                    if (isFourth) {
                        item.appendChild(el("div", { className: "prop-view-more-badge" }, "📷 +" + (photos.length - 5) + " fotos"));
                    }
                    return item;
                });

                galleryMosaic = el("div", { className: "prop-view-mosaic" },
                    el("div", { className: "prop-view-photo-main" },
                        CL.photoImg(photos[0].path, "(max-width: 900px) 100vw, 700px", "Foto principal de " + (data.title || ""))
                    ),
                    el("div", { className: "prop-view-photo-grid" }, ...secondaryPhotos)
                );
            }

            // 4. Host card & perks
            const hostCard = el("div", { className: "prop-view-host-card" },
                el("div", { className: "prop-view-host-avatar" }, "🏡"),
                el("div", { className: "prop-view-host-info" },
                    el("strong", { className: "prop-view-host-title" }, "Espaço inteiro em " + (data.city || "")),
                    el("p", { className: "prop-view-host-desc" }, "Imóvel verificado e preparado para uma estadia inesquecível com privacidade total.")
                )
            );

            const perksBar = el("div", { className: "prop-view-perks-bar" },
                el("div", { className: "prop-view-perk" },
                    el("span", { className: "prop-view-perk-icon" }, "🕒"),
                    el("div", {},
                        el("strong", {}, "Check-in facilitado"),
                        el("span", {}, "a partir das " + (data.checkin_time || "15:00"))
                    )
                ),
                el("div", { className: "prop-view-perk" },
                    el("span", { className: "prop-view-perk-icon" }, "🛡️"),
                    el("div", {},
                        el("strong", {}, "Reserva Direta"),
                        el("span", {}, "sem intermediários ou taxas abusivas")
                    )
                ),
                el("div", { className: "prop-view-perk" },
                    el("span", { className: "prop-view-perk-icon" }, "✨"),
                    el("div", {},
                        el("strong", {}, "Ambiente Cuidado"),
                        el("span", {}, "espaço higienizado e equipado")
                    )
                )
            );

            // 5. Description Card
            const descriptionCard = el("div", { className: "prop-view-card" },
                el("h2", { className: "prop-view-card-title" }, "Sobre este imóvel"),
                el("p", { className: "prop-view-description" }, data.description || "")
            );

            // 6. Amenities Showcase
            const amenityLabels = {
                wifi: "Wi-Fi", kitchen: "Cozinha completa", pool: "Piscina privativa", bbq: "Churrasqueira",
                garage: "Garagem coberta", ac: "Ar-condicionado", tv: "Smart TV", workspace: "Espaço de trabalho",
                washer: "Máquina de lavar", iron: "Ferro de passar", elevator: "Elevador",
                sea_view: "Vista para o mar", balcony: "Varanda gourmet", pets: "Aceita animais de estimação",
                garden: "Jardim", hot_tub: "Banheira de hidromassagem", sauna: "Sauna", fireplace: "Lareira", heating: "Aquecimento"
            };
            const amenityIcons = {
                wifi: "📶", pool: "🏊", bbq: "🥩", ac: "❄️", garage: "🚗", sea_view: "🌊",
                kitchen: "🍳", tv: "📺", washer: "🧺", workspace: "💻", balcony: "🌅",
                garden: "🌳", pets: "🐾", elevator: "🛗", hot_tub: "🛁", sauna: "🧖", fireplace: "🔥", heating: "♨️"
            };

            const amenityElements = (data.amenities || [])
                .filter((key) => key !== "noise")
                .map((key) => {
                    const label = amenityLabels[key] || key;
                    const icon = amenityIcons[key] || "✓";
                    return el("div", { className: "prop-view-amenity-pill" },
                        el("span", { className: "prop-view-amenity-icon" }, icon),
                        el("span", {}, label)
                    );
                });

            const amenitiesBox = el("div", { className: "prop-view-card prop-view-amenities" },
                el("h2", { className: "prop-view-card-title" }, "O que este espaço oferece"),
                amenityElements.length > 0
                    ? el("div", { className: "prop-view-amenities-grid" }, ...amenityElements)
                    : el("p", { className: "muted" }, "Comodidades completas e essenciais para sua hospedagem.")
            );

            // 7. Interactive Availability Calendar
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

                return el("section", { className: "cal-month is-shown" },
                    el("h4", { className: "mt-8" }, monthName.charAt(0).toUpperCase() + monthName.slice(1)),
                    el("div", { className: "cal-weekdays mt-8" }, ...weekdays),
                    el("div", { className: "cal-grid" }, ...dayCells)
                );
            }

            const calendarBox = el("div", { className: "prop-view-card" },
                el("h2", { className: "prop-view-card-title" }, "Calendário de Disponibilidade"),
                el("ul", { className: "cal-legend mt-8" },
                    el("li", {}, el("span", { className: "swatch open" }), " Disponível para reserva"),
                    el("li", {}, el("span", { className: "swatch sistema" }), " Bloqueado / Reservado")
                ),
                el("div", { className: "cal-months" }, buildMonth(0), buildMonth(1))
            );

            // 8. Rules & Quiet Hours Card
            const noiseFrom = data.noise_from || "22:00";
            const noiseUntil = data.noise_until || "07:00";
            const rulesList = [
                el("li", {}, "🕒 Check-in: a partir das " + (data.checkin_time || "15:00")),
                el("li", {}, "🕚 Check-out: até às " + (data.checkout_time || "11:00")),
                el("li", {}, "📅 Estadia mínima: " + (data.min_nights || 1) + " noite(s)"),
                el("li", { className: "prop-view-quiet-rule" },
                    "🌙 Lei do Silêncio: das " + noiseFrom + " às " + noiseUntil + " (não é permitido fazer barulho)."
                )
            ];

            const rulesCard = el("div", { className: "prop-view-card" },
                el("h2", { className: "prop-view-card-title" }, "Regras da casa & Convivência"),
                el("ul", { className: "prop-view-rules-list" }, ...rulesList)
            );

            // 9. Left Main Column
            const mainCol = el("div", { className: "prop-view-main-col" },
                hostCard,
                perksBar,
                descriptionCard,
                amenitiesBox,
                calendarBox,
                rulesCard
            );

            // 11. Right Sticky Sidebar (Booking Card)
            const sideCol = el("aside", { className: "prop-view-side-col" },
                el("div", { className: "prop-view-booking-card" },
                    el("div", { className: "prop-view-card-price-row" },
                        el("div", { className: "prop-view-card-price-group" },
                            el("span", { className: "prop-view-price-val" }, "R$ " + nightly),
                            el("span", { className: "prop-view-price-unit" }, "/ noite")
                        ),
                        data.score ? el("span", { className: "prop-view-card-score" }, "★ " + Number(data.score).toFixed(1).replace(".", ",")) : null
                    ),
                    el("div", { className: "prop-view-card-details" },
                        el("div", { className: "prop-view-card-row" },
                            el("span", {}, "Check-in"),
                            el("strong", {}, "a partir das " + (data.checkin_time || "15:00"))
                        ),
                        el("div", { className: "prop-view-card-row" },
                            el("span", {}, "Check-out"),
                            el("strong", {}, "até " + (data.checkout_time || "11:00"))
                        ),
                        el("div", { className: "prop-view-card-row" },
                            el("span", {}, "Estadia mínima"),
                            el("strong", {}, (data.min_nights || 1) + " noite(s)")
                        ),
                        data.cleaning_fee ? el("div", { className: "prop-view-card-row" },
                            el("span", {}, "Taxa de limpeza"),
                            el("strong", {}, "R$ " + data.cleaning_fee)
                        ) : null,
                        data.quote ? el("div", { className: "prop-view-card-total-box" },
                            el("div", { className: "prop-view-card-row total" },
                                el("span", {}, "Total (" + data.quote.nights + " noites)"),
                                el("strong", {}, "R$ " + data.quote.total)
                            )
                        ) : null
                    ),
                    el("p", { className: "price", hidden: true }, quoteText),
                    el("a", { className: "btn-dash-primary prop-view-cta-btn", href: "#property-calendar" },
                        "Reservar esta acomodação"
                    ),
                    el("div", { className: "prop-view-card-trust" },
                        el("div", { className: "prop-view-trust-item" },
                            el("span", {}, "🛡️"),
                            el("span", {}, "Reserva segura e direta com o anfitrião")
                        ),
                        el("div", { className: "prop-view-trust-item" },
                            el("span", {}, "⚡"),
                            el("span", {}, "Confirmação sem intermediários ou sobretaxas")
                        ),
                        el("div", { className: "prop-view-trust-item" },
                            el("span", {}, "💬"),
                            el("span", {}, "Suporte e contato direto com o proprietário")
                        )
                    )
                )
            );

            // 12. Two Column Content Layout
            const content = el("div", { className: "prop-view-content" },
                mainCol,
                sideCol
            );

            // Mount full luxury view into container
            box.replaceChildren(
                topNav,
                header,
                galleryMosaic,
                content
            );
        })
        .catch((err) => {
            console.error("Erro ao renderizar detalhes do imóvel:", err);
            box.replaceChildren(el("p", { className: "muted" }, (err && err.status === 404) ? "Imóvel não encontrado." : "Imóvel indisponível no momento."));
        });
})();

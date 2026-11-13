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
                .filter((key) => key !== "noise" && key !== "noise_from" && key !== "noise_until")
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

            // 7. Interactive Availability Calendar & Booking State
            const blockedMap = {};
            const priceMap = {};
            (data.blocked_days || []).forEach((b) => {
                if (b.blocked) blockedMap[b.day] = true;
                if (b.price) priceMap[b.day] = Number(b.price);
            });

            const now = new Date();
            const todayIso = now.getFullYear() + "-" + String(now.getMonth() + 1).padStart(2, "0") + "-" + String(now.getDate()).padStart(2, "0");
            let currentMonthOffset = 0;

            let selectedCheckin = params.get("checkin") || null;
            let selectedCheckout = params.get("checkout") || null;

            if (selectedCheckin && (selectedCheckin < todayIso || blockedMap[selectedCheckin])) {
                selectedCheckin = null;
                selectedCheckout = null;
            }
            if (selectedCheckin && selectedCheckout) {
                if (selectedCheckout <= selectedCheckin || blockedMap[selectedCheckout]) {
                    selectedCheckout = null;
                } else {
                    let cur = new Date(selectedCheckin + "T12:00:00");
                    const end = new Date(selectedCheckout + "T12:00:00");
                    while (cur < end) {
                        const curIso = cur.getFullYear() + "-" + String(cur.getMonth() + 1).padStart(2, "0") + "-" + String(cur.getDate()).padStart(2, "0");
                        if (blockedMap[curIso]) {
                            selectedCheckout = null;
                            break;
                        }
                        cur.setDate(cur.getDate() + 1);
                    }
                }
            }

            function formatDateBr(iso) {
                if (!iso) return "Selecione";
                const parts = iso.split("-");
                return parts[2] + "/" + parts[1] + "/" + parts[0];
            }

            // Date Input Boxes
            const displayCheckin = el("span", { className: "prop-view-date-val", id: "display-checkin" }, formatDateBr(selectedCheckin));
            const displayCheckout = el("span", { className: "prop-view-date-val", id: "display-checkout" }, formatDateBr(selectedCheckout));
            const boxCheckin = el("div", { className: "prop-view-date-box active", id: "box-checkin" },
                el("span", { className: "prop-view-date-label" }, "Check-in"),
                displayCheckin
            );
            const boxCheckout = el("div", { className: "prop-view-date-box", id: "box-checkout" },
                el("span", { className: "prop-view-date-label" }, "Check-out"),
                displayCheckout
            );
            boxCheckin.addEventListener("click", () => {
                boxCheckin.classList.add("active");
                boxCheckout.classList.remove("active");
            });
            boxCheckout.addEventListener("click", () => {
                boxCheckout.classList.add("active");
                boxCheckin.classList.remove("active");
            });
            const dateInputs = el("div", { className: "prop-view-date-inputs" }, boxCheckin, boxCheckout);

            // Double Calendar Navigation & Header
            const btnPrev = el("button", {
                type: "button",
                className: "prop-view-cal-nav-btn",
                id: "cal-nav-prev",
                disabled: true,
                title: "Mês anterior"
            }, "‹");
            const btnNext = el("button", {
                type: "button",
                className: "prop-view-cal-nav-btn",
                id: "cal-nav-next",
                title: "Próximo mês"
            }, "›");
            btnPrev.addEventListener("click", () => {
                if (currentMonthOffset > 0) {
                    currentMonthOffset--;
                    renderDoubleCalendar();
                }
            });
            btnNext.addEventListener("click", () => {
                currentMonthOffset++;
                renderDoubleCalendar();
            });
            const calHeader = el("div", { className: "prop-view-cal-header" },
                el("span", { className: "prop-view-cal-title" }, "Selecione as datas"),
                el("div", { className: "prop-view-cal-nav" }, btnPrev, btnNext)
            );

            // Double Calendar Container
            const doubleCalContainer = el("div", { className: "prop-view-double-cal", id: "booking-double-cal" });

            function buildMiniMonth(monthOffset) {
                const target = new Date(now.getFullYear(), now.getMonth() + monthOffset, 1);
                const monthName = target.toLocaleDateString("pt-BR", { month: "long", year: "numeric" });
                const daysInMonth = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate();
                const startDay = (target.getDay() + 6) % 7; // Monday = 0

                const dayCells = [];
                for (let i = 0; i < startDay; i++) {
                    dayCells.push(el("span", {}));
                }

                for (let d = 1; d <= daysInMonth; d++) {
                    const iso = target.getFullYear() + "-" + String(target.getMonth() + 1).padStart(2, "0") + "-" + String(d).padStart(2, "0");
                    const isPast = iso < todayIso;
                    const isBlocked = Boolean(blockedMap[iso]);

                    let cellClass = "cal-day-cell";
                    let disabled = false;
                    let title = "Data disponível";

                    if (isPast) {
                        cellClass += " cal-day-past";
                        disabled = true;
                        title = "Data passada";
                    } else if (isBlocked) {
                        cellClass += " cal-day-blocked";
                        disabled = true;
                        title = "Data indisponível";
                    } else {
                        cellClass += " cal-day-avail";
                        if (selectedCheckin && iso === selectedCheckin) {
                            cellClass += " cal-day-selected cal-day-start";
                        } else if (selectedCheckout && iso === selectedCheckout) {
                            cellClass += " cal-day-selected cal-day-end";
                        } else if (selectedCheckin && selectedCheckout && iso > selectedCheckin && iso < selectedCheckout) {
                            cellClass += " cal-day-in-range";
                        }
                    }

                    const cellBtn = el("button", {
                        type: "button",
                        className: cellClass,
                        disabled: disabled,
                        title: title
                    }, String(d));

                    if (!disabled) {
                        cellBtn.addEventListener("click", () => handleDateClick(iso));
                    }

                    dayCells.push(cellBtn);
                }

                const weekdays = ["S", "T", "Q", "Q", "S", "S", "D"].map((w) => el("span", {}, w));

                return el("div", { className: "cal-month-box" },
                    el("h4", {}, monthName.charAt(0).toUpperCase() + monthName.slice(1)),
                    el("div", { className: "cal-mini-weekdays" }, ...weekdays),
                    el("div", { className: "cal-mini-grid" }, ...dayCells)
                );
            }

            function handleDateClick(iso) {
                if (!selectedCheckin || (selectedCheckin && selectedCheckout)) {
                    selectedCheckin = iso;
                    selectedCheckout = null;
                    boxCheckin.classList.remove("active");
                    boxCheckout.classList.add("active");
                } else if (selectedCheckin && !selectedCheckout) {
                    if (iso <= selectedCheckin) {
                        selectedCheckin = iso;
                        selectedCheckout = null;
                        boxCheckin.classList.remove("active");
                        boxCheckout.classList.add("active");
                    } else {
                        let blockedFound = false;
                        let cur = new Date(selectedCheckin + "T12:00:00");
                        const end = new Date(iso + "T12:00:00");
                        while (cur < end) {
                            const curIso = cur.getFullYear() + "-" + String(cur.getMonth() + 1).padStart(2, "0") + "-" + String(cur.getDate()).padStart(2, "0");
                            if (blockedMap[curIso]) {
                                blockedFound = true;
                                break;
                            }
                            cur.setDate(cur.getDate() + 1);
                        }
                        if (blockedFound) {
                            selectedCheckin = iso;
                            selectedCheckout = null;
                            boxCheckin.classList.remove("active");
                            boxCheckout.classList.add("active");
                        } else {
                            selectedCheckout = iso;
                            boxCheckin.classList.remove("active");
                            boxCheckout.classList.remove("active");
                        }
                    }
                }
                renderDoubleCalendar();
            }

            // Legend
            const calLegend = el("div", { className: "prop-view-cal-legend" },
                el("div", { className: "prop-view-cal-legend-item" },
                    el("span", { className: "prop-view-cal-legend-dot avail" }),
                    el("span", {}, "Disponível")
                ),
                el("div", { className: "prop-view-cal-legend-item" },
                    el("span", { className: "prop-view-cal-legend-dot blocked" }),
                    el("span", {}, "Bloqueado")
                ),
                el("div", { className: "prop-view-cal-legend-item" },
                    el("span", { className: "prop-view-cal-legend-dot selected" }),
                    el("span", {}, "Selecionado")
                )
            );

            // Form inputs and dynamic elements
            const hiddenCsrf = el("input", { type: "hidden", name: "_csrf", value: (page && page.csrf) || "" });
            const hiddenCheckin = el("input", { type: "hidden", name: "checkin", id: "booking-checkin", value: selectedCheckin || "" });
            const hiddenCheckout = el("input", { type: "hidden", name: "checkout", id: "booking-checkout", value: selectedCheckout || "" });
            const hiddenVolta = el("input", { type: "hidden", name: "volta", value: backUrl });

            const detailsCard = el("div", { className: "prop-view-card-details" },
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
                    el("strong", {}, "R$ " + Number(data.cleaning_fee).toLocaleString("pt-BR"))
                ) : null
            );

            const warningBox = el("p", { className: "muted", hidden: true });

            const totalSummaryLabel = el("span", { id: "total-summary-label" }, "Diárias");
            const totalSummaryVal = el("strong", { id: "total-summary-val" }, "R$ 0");
            const totalSummaryRow = el("div", { className: "prop-view-card-row" }, totalSummaryLabel, totalSummaryVal);

            const totalCleaningVal = el("strong", { id: "total-cleaning-val" }, "R$ 0");
            const totalCleaningRow = el("div", { className: "prop-view-card-row", hidden: true },
                el("span", {}, "Taxa de limpeza"),
                totalCleaningVal
            );

            const totalGrandLabel = el("span", { id: "total-grand-label" }, "Total");
            const totalGrandVal = el("strong", { id: "total-grand-val" }, "R$ 0");
            const totalGrandRow = el("div", { className: "prop-view-card-row total" }, totalGrandLabel, totalGrandVal);

            const totalBox = el("div", { className: "prop-view-card-total-box", hidden: true },
                totalSummaryRow,
                totalCleaningRow,
                totalGrandRow
            );

            const btnConfirm = el("button", {
                type: "submit",
                className: "btn-dash-primary prop-view-cta-btn",
                id: "btn-confirm-booking",
                disabled: true
            },
                el("svg", { width: "18", height: "18", viewBox: "0 0 24 24", fill: "none", stroke: "currentColor", strokeWidth: "2.5" },
                    el("path", { d: "M20 6L9 17l-5-5" })
                ),
                el("span", {}, "Confirmar reserva")
            );

            const bookingForm = el("form", {
                className: "prop-view-booking-form mt-16",
                method: "post",
                action: "/imoveis/" + encodeURIComponent(hash) + "/reservar"
            },
                hiddenCsrf,
                hiddenCheckin,
                hiddenCheckout,
                hiddenVolta,
                detailsCard,
                warningBox,
                totalBox,
                btnConfirm
            );

            function updateDisplayAndQuote() {
                displayCheckin.textContent = formatDateBr(selectedCheckin);
                displayCheckout.textContent = formatDateBr(selectedCheckout);
                hiddenCheckin.value = selectedCheckin || "";
                hiddenCheckout.value = selectedCheckout || "";

                if (selectedCheckin && selectedCheckout) {
                    const d1 = new Date(selectedCheckin + "T12:00:00");
                    const d2 = new Date(selectedCheckout + "T12:00:00");
                    const nights = Math.round((d2 - d1) / (1000 * 60 * 60 * 24));
                    const minNights = Number(data.min_nights || 1);
                    const cleaningFee = Number(data.cleaning_fee || 0);

                    if (nights < minNights) {
                        warningBox.textContent = "Estadia mínima: " + minNights + " noites.";
                        warningBox.hidden = false;
                        totalBox.hidden = true;
                        btnConfirm.disabled = true;
                    } else {
                        warningBox.hidden = true;
                        let totalNightsPrice = 0;
                        let cur = new Date(selectedCheckin + "T12:00:00");
                        for (let i = 0; i < nights; i++) {
                            const curIso = cur.getFullYear() + "-" + String(cur.getMonth() + 1).padStart(2, "0") + "-" + String(cur.getDate()).padStart(2, "0");
                            totalNightsPrice += (priceMap[curIso] !== undefined ? Number(priceMap[curIso]) : Number(data.nightly_price));
                            cur.setDate(cur.getDate() + 1);
                        }
                        const grandTotal = totalNightsPrice + cleaningFee;

                        totalSummaryLabel.textContent = "Total (" + nights + " noites)";
                        totalSummaryVal.textContent = "R$ " + Number(totalNightsPrice).toLocaleString("pt-BR");
                        if (cleaningFee > 0) {
                            totalCleaningRow.hidden = false;
                            totalCleaningVal.textContent = "R$ " + Number(cleaningFee).toLocaleString("pt-BR");
                        } else {
                            totalCleaningRow.hidden = true;
                        }
                        totalGrandVal.textContent = "R$ " + Number(grandTotal).toLocaleString("pt-BR");
                        totalBox.hidden = false;
                        btnConfirm.disabled = false;
                    }
                } else {
                    warningBox.hidden = true;
                    totalBox.hidden = true;
                    btnConfirm.disabled = true;
                }
            }

            function renderDoubleCalendar() {
                btnPrev.disabled = (currentMonthOffset <= 0);
                doubleCalContainer.replaceChildren(
                    buildMiniMonth(currentMonthOffset),
                    buildMiniMonth(currentMonthOffset + 1)
                );
                updateDisplayAndQuote();
            }

            // Initial render of calendar
            renderDoubleCalendar();

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
                rulesCard
            );

            // 10. Right Sticky Sidebar (Booking Card)
            const sideCol = el("aside", { className: "prop-view-side-col" },
                el("div", { className: "prop-view-booking-card" },
                    el("div", { className: "prop-view-card-price-row" },
                        el("div", { className: "prop-view-card-price-group" },
                            el("span", { className: "prop-view-price-val" }, "R$ " + nightly),
                            el("span", { className: "prop-view-price-unit" }, "/ noite")
                        ),
                        data.score ? el("span", { className: "prop-view-card-score" }, "★ " + Number(data.score).toFixed(1).replace(".", ",")) : null
                    ),
                    dateInputs,
                    calHeader,
                    doubleCalContainer,
                    calLegend,
                    bookingForm,
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

            // 11. Two Column Content Layout
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

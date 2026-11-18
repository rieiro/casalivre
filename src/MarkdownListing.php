<?php

declare(strict_types=1);

/**
 * Markdown rendering of a property listing (Architecture §3.2).
 * Strictly LGPD safe: no PII, no coordinates, no address, no CPF, no Pix.
 *
 * @param array<string, mixed> $listing Result of public_listing()
 */
function render_property_markdown(array $listing, string $host = ''): string
{
    $host = $host !== '' ? $host : ($_SERVER['HTTP_HOST'] ?? 'casalivre.com.br');
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'https';
    $canonicalUrl = $scheme . '://' . $host . '/imoveis/' . $listing['id'];

    $ruleKeys = ['noise', 'noise_from', 'noise_until', 'smoking', 'pets', 'sand_shower', 'wet_elevator', 'parties'];
    $rawAmenities = is_array($listing['amenities'] ?? null) ? $listing['amenities'] : [];
    $filteredAmenities = array_values(array_filter(
        $rawAmenities,
        static fn ($k) => !in_array((string) $k, $ruleKeys, true)
    ));
    $amenitiesStr = !empty($filteredAmenities) ? implode(', ', $filteredAmenities) : '-';
    $neighborhood = !empty($listing['neighborhood']) ? $listing['neighborhood'] : '-';

    // House rules
    $rules = $listing['house_rules'] ?? null;
    if (!is_array($rules) && class_exists('Amenities')) {
        $rules = Amenities::rules($listing);
    }

    $quietFrom = $rules['quiet_hours']['from'] ?? ($listing['quiet_hours_from'] ?? ($listing['noise_from'] ?? '22:00'));
    $quietUntil = $rules['quiet_hours']['until'] ?? ($listing['quiet_hours_until'] ?? ($listing['noise_until'] ?? '07:00'));
    $quietEnabled = $rules['quiet_hours']['enabled'] ?? (!empty($listing['noise']));

    $smokingAllowed = !empty($rules['smoking_allowed']) || !empty($listing['smoking']);
    $petsAllowed = !empty($rules['pets_allowed']) || !empty($listing['pets']);
    $partiesAllowed = !empty($rules['parties_allowed']) || !empty($listing['parties']);
    $sandShower = !empty($rules['sand_shower_required']) || !empty($listing['sand_shower']);
    $wetElevator = !empty($rules['wet_clothes_in_elevator_prohibited']) || !empty($listing['wet_elevator']);

    $lines = [
        '# ' . ($listing['title'] ?? ''),
        'url: ' . $canonicalUrl,
        'city: ' . ($listing['city'] ?? ''),
        'neighborhood: ' . $neighborhood,
        'guests: ' . ($listing['max_guests'] ?? 0),
        'bedrooms: ' . ($listing['bedrooms'] ?? 0),
        'beds: ' . ($listing['beds'] ?? 0),
        'nightly_brl: ' . ($listing['nightly_price'] ?? 0),
        'cleaning_brl: ' . ($listing['cleaning_fee'] ?? 0),
        'min_nights: ' . ($listing['min_nights'] ?? 1),
        'checkin: ' . ($listing['checkin_time'] ?? '14:00'),
        'checkout: ' . ($listing['checkout_time'] ?? '11:00'),
        'quiet_hours: ' . ($quietEnabled ? "{$quietFrom} às {$quietUntil} (horário de silêncio — proibido barulho / quiet hours - no noise allowed)" : 'nenhum'),
        'amenities: ' . $amenitiesStr,
        '',
        '## Resumo',
        trim((string) ($listing['summary'] ?? '')),
        '',
        '## Descrição',
        trim((string) ($listing['description'] ?? '')),
        '',
        '## Regras da Casa (House Rules)',
        '- Check-in: a partir das ' . ($listing['checkin_time'] ?? '14:00'),
        '- Check-out: até às ' . ($listing['checkout_time'] ?? '11:00'),
        '- Estadia mínima: ' . ($listing['min_nights'] ?? 1) . ' noite(s)',
    ];

    if ($quietEnabled) {
        $lines[] = "- Horário de Silêncio (Quiet hours): das {$quietFrom} às {$quietUntil} (não é permitido fazer barulho / quiet hours - no noise allowed).";
    }
    $lines[] = '- Fumo: ' . ($smokingAllowed ? 'Permitido fumar (Smoking allowed)' : 'Proibido fumar (No smoking allowed)');
    $lines[] = '- Animais de estimação: ' . ($petsAllowed ? 'Aceita animais de estimação (Pets allowed)' : 'Não aceita animais (No pets allowed)');
    $lines[] = '- Festas e eventos: ' . ($partiesAllowed ? 'Permitido realizar eventos (Parties allowed)' : 'Proibido festas ou eventos (No parties or events)');
    if ($sandShower) {
        $lines[] = '- Ducha externa: Usar ducha externa para remover a areia antes de entrar (Use outdoor shower to remove sand).';
    }
    if ($wetElevator) {
        $lines[] = '- Elevador social: Proibição de uso com trajes de banho molhados (No wet swimwear in elevators).';
    }
    $lines[] = '';

    return implode("
", $lines);
}

/**
 * Checks whether the request prefers text/markdown over text/html.
 */
function prefers_markdown(): bool
{
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    if ($accept === '' || !str_contains($accept, 'text/markdown')) {
        return false;
    }

    $qMarkdown = 0.0;
    $qHtml = 0.0;

    foreach (explode(',', $accept) as $part) {
        $sub = explode(';', trim($part));
        $media = strtolower(trim($sub[0]));
        $q = 1.0;
        if (isset($sub[1]) && preg_match('#q=([0-9.]+)#', $sub[1], $m)) {
            $q = (float) $m[1];
        }
        if ($media === 'text/markdown') {
            $qMarkdown = max($qMarkdown, $q);
        } elseif ($media === 'text/html' || $media === '*/*') {
            $qHtml = max($qHtml, $q);
        }
    }

    return $qMarkdown > 0.0 && $qMarkdown >= $qHtml;
}

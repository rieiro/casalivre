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

    $amenitiesStr = !empty($listing['amenities']) ? implode(', ', $listing['amenities']) : '-';
    $neighborhood = !empty($listing['neighborhood']) ? $listing['neighborhood'] : '-';

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
        'amenities: ' . $amenitiesStr,
        '',
        '## Resumo',
        trim((string) ($listing['summary'] ?? '')),
        '',
        '## Descrição',
        trim((string) ($listing['description'] ?? '')),
        '',
    ];

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

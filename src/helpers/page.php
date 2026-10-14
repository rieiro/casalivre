<?php

declare(strict_types=1);

function summary_from(string $description, int $maxWords = 45): string
{
    $words = preg_split('/\s+/u', trim($description)) ?: [];
    $words = array_values(array_filter($words, static fn ($w) => $w !== ''));
    if (count($words) > $maxWords) {
        $words = array_slice($words, 0, $maxWords);
    }
    return implode(' ', $words);
}

function word_count(string $text): int
{
    $words = preg_split('/\s+/u', trim($text)) ?: [];
    return count(array_filter($words, static fn ($w) => $w !== ''));
}

function review_score(int $propertyId): ?float
{
    return HouseRepository::averageScore($propertyId);
}

/**
 * Dados do servidor para os scripts da página (CSP sem script inline): bloco JSON não executável,
 * lido por CL.data() em /assets/safe-dom.js.
 */
function page_data(array $data = []): array
{
    static $registry = [];
    $registry = array_merge($registry, $data);
    return $registry;
}

/**
 * Bloco JSON único (<script type="application/json" id="page-data">) emitido no <head> pelo layout:
 * dados registrados pela view + csrf (fonte única do token para CL.api) + textos de erro do CL.api.
 */
function page_data_script(string $csrf): string
{
    $data = page_data() + [
        'csrf' => $csrf,
        'i18n' => ['netError' => t('js.net_error'), 'requestFailed' => t('js.request_failed')],
    ];
    return '<script type="application/json" id="page-data">'
        . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . '</script>';
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/../GooglePlacesService.php';
require_once __DIR__ . '/Resultado.php';

/**
 * Valida e pré-visualiza um google_place_id antes de salvar (ou depois, no painel do anfitrião).
 * A regra fica aqui; a API (/api/google-place/verificar) só traduz Resultado para JSON.
 */
final class GooglePlaceVerify
{
    public static function run(int $userId, mixed $rawPlaceId, string $locale = 'pt-BR'): Resultado
    {
        $check = GooglePlacesService::validatePlaceId($rawPlaceId);
        if (!$check['ok']) {
            return new Resultado('invalido', (string) ($check['error'] ?? t('err.google_place_id')));
        }
        if (($check['value'] ?? null) === null) {
            return new Resultado('invalido', t('err.google_place_id'));
        }

        $result = GooglePlacesService::verifyPlaceId($check['value'], $locale);
        if ($result['ok']) {
            return new Resultado('ok', null, ['google_reputation' => $result['data'] ?? null]);
        }

        $code = (string) ($result['error_code'] ?? 'upstream_error');
        $invalido = in_array($code, ['invalid_place_id', 'not_found'], true);
        $message = (string) ($result['error_message']
            ?? t($invalido ? 'err.google_place_not_found' : 'err.google_place_unavailable'));

        return new Resultado($invalido ? 'invalido' : 'indisponivel', $message);
    }
}

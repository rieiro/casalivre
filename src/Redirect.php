<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers/url.php';
require_once __DIR__ . '/CityRepository.php';

/**
 * Redirect para um caminho interno mantendo a query da requisição. Classe (não função global): helpers_split_test
 * fixa o conjunto de funções de src/helpers*.
 */
final class Redirect
{
    /**
     * Location relativa: $path (montado pelo chamador só de partes validadas) + '?' + QUERY_STRING original, se houver.
     * Nunca usa o Host nem a URI inteira, então não vira redirect aberto.
     */
    public static function withQuery(string $path): never
    {
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        redirect($path . ($query !== '' ? '?' . $query : ''));
    }

    /**
     * Old localidade= URL that resolves to one place → 301 to the same path with local_id.
     * Two or more hits, or none, stay on the page. local_id already in the query is left as-is.
     */
    public static function toResolvedPlace(string $path): void
    {
        if (array_key_exists('local_id', $_GET) || !is_string($_GET['localidade'] ?? null)) {
            return;
        }
        $place = CityRepository::resolve($_GET['localidade'])['place'];
        if ($place === null) {
            return;
        }
        $params = $_GET;
        unset($params['localidade']);
        $params = ['local_id' => $place['id']] + $params;
        http_response_code(301);
        redirect($path . '?' . http_build_query($params));
    }
}

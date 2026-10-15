<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers/url.php';

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
}

<?php

declare(strict_types=1);

/**
 * Redação de dados sensíveis em rotas/textos destinados a logs.
 * Compartilhado por ExceptionShield (PR #1) e RequestLog (PR #5): manter este
 * arquivo idêntico nas duas branches para o merge não conflitar.
 */
final class Redact
{
    /** Segmentos de 32 hex (ical_hash e tokens similares) viram {hash}. */
    public static function path(string $path): string
    {
        return preg_replace('/(?<![a-f0-9])[a-f0-9]{32}(?![a-f0-9])/i', '{hash}', $path) ?? $path;
    }

    /** REQUEST_URI -> só o caminho (sem query string nem fragmento), com hashes redigidos. */
    public static function route(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            $path = preg_replace('/[?#].*$/s', '', $uri) ?? '';
        }
        return self::path($path);
    }

    /** Texto livre (mensagens, stack traces): hashes redigidos e query strings removidas. */
    public static function text(string $text): string
    {
        $text = preg_replace('/(\/[^\s?#"\']*)\?[^\s"\']*/', '$1?{query}', $text) ?? $text;
        return self::path($text);
    }
}

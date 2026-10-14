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

    /**
     * Para o error.log: além de text(), mascara e-mails, valores de chaves sensíveis
     * (senha/password/token/secret/_csrf...) e sequências longas tipo token.
     */
    public static function secrets(string $text): string
    {
        $text = self::text($text);
        $text = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '{email}', $text) ?? $text;
        $text = preg_replace('/\b((?:password|passwd|senha|pass|token|secret|segredo|_csrf|api[_-]?key|authorization)[A-Za-z_]*)(["\']?\s*(?:=>|[:=])\s*)(["\']?)[^\s"\',;&)]+/i', '$1$2$3{redacted}', $text) ?? $text;
        return preg_replace('/(?<![A-Za-z0-9_\-])[A-Za-z0-9_\-]{32,}(?![A-Za-z0-9_\-])/', '{token}', $text) ?? $text;
    }

    /** Texto livre (mensagens, stack traces): hashes redigidos e query strings removidas. */
    public static function text(string $text): string
    {
        $text = preg_replace('/(\/[^\s?#"\']*)\?[^\s"\']*/', '$1?{query}', $text) ?? $text;
        return self::path($text);
    }
}

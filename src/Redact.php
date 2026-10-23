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
     * Para o error.log: além de text(), mascara credenciais em URL (user:pass@), Bearer/Basic,
     * valores de chaves sensíveis (inclusive user[password], SMTP_PASS, valor entre aspas inteiro),
     * e-mails, CPF, telefones e sequências longas tipo token.
     */
    public static function secrets(string $text): string
    {
        $text = self::text($text);
        $text = preg_replace('#\b([a-z][a-z0-9+.\-]*://)[^\s/@]+@#i', '$1{userinfo}@', $text) ?? $text;
        $text = preg_replace('/\b(Bearer|Basic)\s+[^\s"\',;]+/i', '$1 {redacted}', $text) ?? $text;
        $text = preg_replace_callback(
            '/(?<![A-Za-z0-9])([A-Za-z0-9_\-\[\]]*(?:pass|senha|token|secret|segredo|csrf|api[_\-]?key|authorization|cookie)[A-Za-z0-9_\-\[\]]*)'
            . '(["\']?\]?\s*(?:=>|[:=])\s*)("[^"]*"|\'[^\']*\'|[^\s"\',;&)]+)/i',
            static function (array $m): string {
                $q = $m[3][0] ?? '';
                $wrap = ($q === '"' || $q === "'") ? $q : '';
                return $m[1] . $m[2] . $wrap . '{redacted}' . $wrap;
            },
            $text
        ) ?? $text;
        $text = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '{email}', $text) ?? $text;
        $text = preg_replace('/(?<![\d.\-])\d{3}\.?\d{3}\.?\d{3}-?\d{2}(?![\d.\-])/', '{cpf}', $text) ?? $text;
        $text = preg_replace('/(?:\+?55\s?)?(?:\(\d{2}\)\s?|(?<!\d)\d{2}\s)9?\d{4}-?\d{4}(?!\d)|(?<![\d.\-])\d{2}\s?9?\d{4}-\d{4}(?!\d)|(?<!\d)\d{2}9\d{8}(?!\d)/', '{telefone}', $text) ?? $text;
        return preg_replace('/(?<![A-Za-z0-9_\-])[A-Za-z0-9_\-]{32,}(?![A-Za-z0-9_\-])/', '{token}', $text) ?? $text;
    }

    /** Texto livre (mensagens, stack traces): hashes redigidos e query strings removidas. */
    public static function text(string $text): string
    {
        $text = preg_replace('/(\/[^\s?#"\']*)\?[^\s"\']*/', '$1?{query}', $text) ?? $text;
        return self::path($text);
    }

    /**
     * INTERNAL_ERROR message for the PG activity log (spec §13 Q7): secrets() plus CNPJ, JWT, DSN and runs of
     * 6+ digits. The caller caps the length (200 chars).
     */
    public static function logMessage(string $text): string
    {
        $text = preg_replace('/\b(?:pgsql|mysql|sqlite|redis|mongodb(?:\+srv)?|valkey):(?:\/\/)?[^\s"\']+/i', '{dsn}', $text) ?? $text;
        $text = preg_replace('/\beyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/', '{jwt}', $text) ?? $text;
        $text = self::secrets($text);
        $text = preg_replace('/(?<!\d)\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}(?!\d)/', '{cnpj}', $text) ?? $text;
        return preg_replace('/\d{6,}/', '{num}', $text) ?? $text;
    }

    /**
     * Search term for logs (demand-log redaction, docs/lgpd/lia-demand-log.md item 1 and §4.6): null when the raw
     * text looks like an email, URL, phone, CPF/CNPJ or has 6+ digits in total; otherwise folded (lowercase, no
     * accents), single spaces, at most 64 chars.
     */
    public static function searchTerm(string $raw): ?string
    {
        if (preg_match('/@|:\/\/|\bwww\./i', $raw) === 1 || preg_match_all('/\d/', $raw) >= 6) {
            return null;
        }
        $t = mb_strtolower(trim($raw));
        $t = strtr($t, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
        ]);
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
        $t = mb_substr($t, 0, 64);
        return $t === '' ? null : $t;
    }
}

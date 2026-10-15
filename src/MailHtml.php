<?php

declare(strict_types=1);

require_once __DIR__ . '/Mailer.php';

/**
 * Parte HTML dos e-mails (Sprint 3 PR 11, Etapa 5): o mesmo texto da parte text/plain, com cabeçalho e rodapé do tema
 * da plataforma (nome e cores do brand.php) e estilo só inline. Sem script, sem imagem nem CSS externos.
 *  - Todo texto passa por esc() (htmlspecialchars ENT_QUOTES | ENT_HTML5, UTF-8).
 *  - Só vira link (<a>) URL http/https sobre APP_URL ou https de domínio verificado de grupo (GrupoResolvedor::doDominio); qualquer
 *    outra URL fica como texto. Nada vem do Host da requisição.
 *  - Cores só #rgb/#rrggbb (senão o padrão do app.css).
 */
final class MailHtml
{
    /** Padrão do :root de public/assets/app.css. */
    private const DEFAULTS = ['--brand' => '#003580', '--ink' => '#1a1a1a', '--bg' => '#f6f7f8', '--surface' => '#fff', '--muted' => '#5c6b73', '--line' => '#d7dde2', '--link' => '#0b57d0'];

    public static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array{nome?:string,tokens?:array<string,string>} $brand
     * @param callable(string):bool|null $allowed URL pode virar link? (padrão: isAllowedLink)
     */
    public static function render(string $subject, string $text, array $brand, ?callable $allowed = null): string
    {
        $allowed ??= [self::class, 'isAllowedLink'];
        $c = [];
        foreach (self::DEFAULTS as $k => $def) {
            $v = (string) ($brand['tokens'][$k] ?? '');
            $c[$k] = preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/', $v) === 1 ? $v : $def;
        }
        $nome = trim((string) ($brand['nome'] ?? '')) ?: 'CasaLivre';
        $paragraphs = '';
        foreach (preg_split("/(?:\r?\n){2,}/", trim($text)) ?: [] as $para) {
            $lines = array_map(static fn (string $l): string => self::linkify($l, $allowed, $c['--link']), preg_split("/\r?\n/", $para) ?: []);
            $paragraphs .= '<p style="margin:0 0 16px 0;">' . implode('<br>', $lines) . "</p>\r\n";
        }
        $site = Mailer::linkFromAppUrl('http://localhost/');
        $footerLink = $allowed($site) ? self::link($site, $c['--link']) : self::esc($site);
        return "<!DOCTYPE html>\r\n<html lang=\"pt-BR\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width\">"
            . '<title>' . self::esc($subject) . "</title></head>\r\n"
            . '<body style="margin:0;padding:0;background:' . $c['--bg'] . ';color:' . $c['--ink'] . ';font-family:Arial,Helvetica,sans-serif;">' . "\r\n"
            . '<div style="max-width:600px;margin:0 auto;padding:24px 12px;">' . "\r\n"
            . '<div style="background:' . $c['--brand'] . ';color:#ffffff;padding:16px 20px;font-size:20px;font-weight:bold;">' . self::esc($nome) . "</div>\r\n"
            . '<div style="background:' . $c['--surface'] . ';padding:20px;font-size:15px;line-height:1.5;">' . "\r\n" . $paragraphs . "</div>\r\n"
            . '<div style="padding:12px 20px;font-size:12px;color:' . $c['--muted'] . ';border-top:1px solid ' . $c['--line'] . ';">'
            . 'Enviado por ' . self::esc($nome) . ' · ' . $footerLink . "</div>\r\n"
            . "</div>\r\n</body></html>\r\n";
    }

    /** Link permitido: http/https sem usuário, sobre APP_URL, ou https de domínio verificado de grupo (sem porta). */
    public static function isAllowedLink(string $url): bool
    {
        $p = parse_url($url);
        if (!is_array($p) || !in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true) || empty($p['host'])
            || isset($p['user']) || isset($p['pass'])) {
            return false;
        }
        try {
            $base = rtrim(Mailer::linkFromAppUrl('http://localhost/'), '/');
        } catch (RuntimeException) {
            return false;
        }
        if ($url === $base || str_starts_with($url, $base . '/') || str_starts_with($url, $base . '?')) {
            return true;
        }
        if (strtolower((string) $p['scheme']) !== 'https' || isset($p['port'])) {
            return false;
        }
        require_once __DIR__ . '/GrupoResolvedor.php';
        try {
            // Mesma regra do resolvedor: Host normalizado, só domínio verificado, nunca o da plataforma (nem subdomínio).
            return GrupoResolvedor::doDominio((string) $p['host']) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    private static function linkify(string $line, callable $allowed, string $color): string
    {
        $out = '';
        $pos = 0;
        if (preg_match_all('#https?://[^\s<>"\']+#i', $line, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[0] as [$url, $off]) {
                $url = rtrim($url, '.,;:)');
                $out .= self::esc(substr($line, $pos, $off - $pos));
                $out .= $allowed($url) ? self::link($url, $color) : self::esc($url);
                $pos = $off + strlen($url);
            }
        }
        return $out . self::esc(substr($line, $pos));
    }

    private static function link(string $url, string $color): string
    {
        return '<a href="' . self::esc($url) . '" style="color:' . $color . ';">' . self::esc($url) . '</a>';
    }
}

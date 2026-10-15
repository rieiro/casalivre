<?php

declare(strict_types=1);

require_once __DIR__ . '/AssetManifest.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/MapStyle.php';

/**
 * Temas da plataforma (Sprint 3, Etapa 2).
 *
 * Cada tema é uma pasta themes/<nome>/ com um brand.php (nome da marca, rótulo, logo, favicon e tokens CSS).
 * O build (ThemeBuilder, via scripts/build-assets.php) gera public/assets/themes/<nome>.css (+ logo/favicon) e
 * o manifesto de assets. A lista de temas válidos é a do manifesto: só existe tema se "/assets/themes/<nome>.css" estiver lá
 * e o nome casar com NAME_RE (sem "/", "." ou "..": nunca vira caminho fora de themes/).
 * settings.theme fora da lista cai no tema padrão.
 */
final class Theme
{
    public const DEFAULT = 'padrao';
    public const NAME_RE = '/^[a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?$/';
    public const SETTING = 'theme';
    /** Arquivos de imagem que um tema pode declarar (logo, favicon); fonte única (AssetManifest e build usam). */
    public const IMAGE_EXT = ['svg', 'png', 'ico'];
    private const ASSET_PREFIX = '/assets/themes/';

    public static function sourceDir(): string
    {
        return dirname(__DIR__) . '/themes';
    }

    /** Caminho público sob /assets/themes/ (ex.: assetPath('padrao.css')). */
    public static function assetPath(string $rest = ''): string
    {
        return self::ASSET_PREFIX . $rest;
    }

    public static function cssPath(string $name): string
    {
        return self::assetPath($name . '.css');
    }

    /** Nomes válidos (manifesto de assets + pasta do tema), ordenados. @return list<string> */
    public static function available(?array $manifest = null): array
    {
        $manifest ??= AssetManifest::load();
        $out = [];
        foreach (array_keys($manifest) as $path) {
            if (preg_match('#^' . preg_quote(self::ASSET_PREFIX, '#') . '([^/]+)\.css$#', (string) $path, $m) === 1 && self::isValidName($m[1])
                && is_file(self::sourceDir() . '/' . $m[1] . '/brand.php')) {
                $out[] = $m[1];
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    public static function isValidName(string $name): bool
    {
        return preg_match(self::NAME_RE, $name) === 1;
    }

    public static function isAvailable(string $name): bool
    {
        // available() só devolve nomes que casam com NAME_RE.
        return in_array($name, self::available(), true);
    }

    /** Tema em uso: settings.theme se estiver na lista; senão o padrão. */
    public static function current(): string
    {
        $name = trim(Settings::get(self::SETTING));
        return self::isAvailable($name) ? $name : self::DEFAULT;
    }

    /**
     * brand.php do tema, validado. Só lê temas da lista (ou o padrão), nunca um caminho vindo de fora.
     * @return array{nome:string,rotulo:string,logo:?string,favicon:?string,tokens:array<string,string>,tokens_escuro:array<string,string>,mapa:array<string,string>}
     */
    public static function brand(?string $name = null): array
    {
        $name ??= self::current();
        if (!self::isValidName($name)) {
            $name = self::DEFAULT;
        }
        return self::readBrand(self::sourceDir() . '/' . $name . '/brand.php');
    }

    /** Lê e valida um brand.php (usado também pelo build). */
    public static function readBrand(string $file): array
    {
        $raw = is_file($file) ? require $file : [];
        $raw = is_array($raw) ? $raw : [];
        $str = static fn (mixed $v, string $fallback): string => is_string($v) && trim($v) !== '' ? trim($v) : $fallback;
        $asset = static function (mixed $v): ?string {
            if (!is_string($v) || preg_match('/^[a-z0-9][a-z0-9_-]*\.(' . implode('|', self::IMAGE_EXT) . ')$/', $v) !== 1) {
                return null;
            }
            return $v;
        };
        $isDefault = basename(dirname($file)) === self::DEFAULT;
        return [
            // Sem nome próprio, vale o do tema padrão (themes/padrao/brand.php é a fonte do nome da marca).
            'nome' => $str($raw['nome'] ?? null, $isDefault ? '' : self::brand(self::DEFAULT)['nome']),
            'rotulo' => $str($raw['rotulo'] ?? null, basename(dirname($file))),
            'logo' => $asset($raw['logo'] ?? null),
            'favicon' => $asset($raw['favicon'] ?? null),
            'tokens' => self::cleanTokens($raw['tokens'] ?? []),
            'tokens_escuro' => self::cleanTokens($raw['tokens_escuro'] ?? []),
            // Cores do mapa por papel (Etapa 3); vazio = estilo base.
            'mapa' => MapStyle::clean($raw['mapa'] ?? []),
        ];
    }

    /**
     * Tokens aceitos: nome "--[a-z0-9-]+" e valor sem caracteres que fechem a regra ou carreguem recurso
     * (; { } < > \ " ', abertura/fechamento de comentário CSS e url( / @import / expression). Inválidos são descartados.
     * @return array<string,string>
     */
    public static function cleanTokens(mixed $tokens): array
    {
        $out = [];
        if (!is_array($tokens)) {
            return $out;
        }
        foreach ($tokens as $k => $v) {
            if (!is_string($k) || preg_match('/^--[a-z0-9-]{1,40}$/', $k) !== 1 || !is_string($v)) {
                continue;
            }
            $v = trim($v);
            if ($v === '' || strlen($v) > 120 || preg_match('/[;{}<>\\\\"\'\r\n]/', $v) === 1
                || preg_match('/url\s*\(|@import|expression\s*\(|\/\*|\*\//i', $v) === 1) {
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /** CSS gerado de um brand validado. */
    public static function css(array $brand): string
    {
        $block = static function (array $tokens): string {
            $lines = '';
            foreach ($tokens as $k => $v) {
                $lines .= '    ' . $k . ': ' . $v . ";\n";
            }
            return $lines;
        };
        $css = "/* Gerado por scripts/build-assets.php a partir de themes/<nome>/brand.php. Não editar. */\n";
        if ($brand['tokens'] !== []) {
            $css .= ":root {\n" . $block($brand['tokens']) . "}\n";
        }
        if ($brand['tokens_escuro'] !== []) {
            $css .= "@media (prefers-color-scheme: dark) {\n    :root {\n"
                . preg_replace('/^/m', '    ', rtrim($block($brand['tokens_escuro']))) . "\n    }\n}\n";
        }
        return $css;
    }

    /** URL do favicon do tema (o padrão continua /favicon.ico). */
    public static function faviconUrl(array $brand, string $name): string
    {
        return $brand['favicon'] === null ? '/favicon.ico' : asset_url(self::assetPath($name . '/' . $brand['favicon']));
    }

    public static function logoUrl(array $brand, string $name): ?string
    {
        return $brand['logo'] === null ? null : asset_url(self::assetPath($name . '/' . $brand['logo']));
    }

    /**
     * Estilo do mapa do tema: o gerado (/assets/themes/<nome>/map-style.json) se estiver no manifesto; senão o
     * estilo base. Tema fora da lista vale como o padrão.
     */
    public static function mapStylePath(?string $name = null, ?array $manifest = null): string
    {
        $manifest ??= AssetManifest::load();
        $name ??= self::current();
        if (!self::isValidName($name) || !in_array($name, self::available($manifest), true)) {
            $name = self::DEFAULT;
        }
        $path = MapStyle::themePath($name);
        return isset($manifest[$path]) ? $path : MapStyle::BASE;
    }
}

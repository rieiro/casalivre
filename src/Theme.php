<?php

declare(strict_types=1);

require_once __DIR__ . '/AssetManifest.php';

/**
 * Temas da plataforma (Sprint 3, Etapa 2).
 *
 * Cada tema é uma pasta themes/<nome>/ com um brand.php (nome da marca, rótulo, logo, favicon e tokens CSS).
 * scripts/build-assets.php gera public/assets/themes/<nome>.css (+ logo/favicon copiados) e o manifesto de
 * assets. A lista de temas válidos é a do manifesto: só existe tema se "/assets/themes/<nome>.css" estiver lá
 * e o nome casar com NAME_RE (sem "/", "." ou "..": nunca vira caminho fora de themes/).
 * settings.theme fora da lista cai no tema padrão.
 */
final class Theme
{
    public const DEFAULT = 'padrao';
    public const NAME_RE = '/^[a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?$/';
    public const SETTING = 'theme';
    /** Arquivos de imagem que um tema pode declarar (logo, favicon). */
    public const IMAGE_EXT = ['svg', 'png', 'ico'];

    public static function sourceDir(): string
    {
        return dirname(__DIR__) . '/themes';
    }

    public static function cssPath(string $name): string
    {
        return '/assets/themes/' . $name . '.css';
    }

    /** Nomes válidos (manifesto de assets + pasta do tema), ordenados. @return list<string> */
    public static function available(?array $manifest = null): array
    {
        $manifest ??= AssetManifest::load();
        $out = [];
        foreach (array_keys($manifest) as $path) {
            if (preg_match('#^/assets/themes/([^/]+)\.css$#', (string) $path, $m) === 1 && self::isValidName($m[1])
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
        return self::isValidName($name) && in_array($name, self::available(), true);
    }

    /** Tema em uso: settings.theme se estiver na lista; senão o padrão. */
    public static function current(): string
    {
        $name = class_exists('Settings', false) ? trim(Settings::get(self::SETTING)) : '';
        return self::isAvailable($name) ? $name : self::DEFAULT;
    }

    /**
     * brand.php do tema, validado. Só lê temas da lista (ou o padrão), nunca um caminho vindo de fora.
     * @return array{nome:string,rotulo:string,logo:?string,favicon:?string,tokens:array<string,string>,tokens_escuro:array<string,string>}
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
            if (!is_string($v) || preg_match('/^[a-z0-9][a-z0-9_-]*\.(svg|png|ico)$/', $v) !== 1) {
                return null;
            }
            return $v;
        };
        return [
            'nome' => $str($raw['nome'] ?? null, 'CasaLivre'),
            'rotulo' => $str($raw['rotulo'] ?? null, basename(dirname($file))),
            'logo' => $asset($raw['logo'] ?? null),
            'favicon' => $asset($raw['favicon'] ?? null),
            'tokens' => self::cleanTokens($raw['tokens'] ?? []),
            'tokens_escuro' => self::cleanTokens($raw['tokens_escuro'] ?? []),
        ];
    }

    /**
     * Tokens aceitos: nome "--[a-z0-9-]+" e valor sem caracteres que fechem a regra ou carreguem recurso
     * (; { } < > \ " ' e url( / @import / expression). Inválidos são descartados.
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
                || preg_match('/url\s*\(|@import|expression\s*\(/i', $v) === 1) {
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

    /**
     * Gera public/assets/themes/ a partir de themes/: <nome>.css e <nome>/<logo|favicon>.
     * Remove gerados de temas que não existem mais (só dentro de public/assets/themes/).
     * @return list<string> temas gerados
     */
    public static function build(): array
    {
        $out = AssetManifest::publicDir() . '/assets/themes';
        if (!is_dir($out)) {
            mkdir($out, 0755, true);
        }
        $names = [];
        foreach (glob(self::sourceDir() . '/*/brand.php') ?: [] as $file) {
            $name = basename(dirname($file));
            if (!self::isValidName($name)) {
                fwrite(STDERR, "tema ignorado (nome inválido): $name\n");
                continue;
            }
            $brand = self::readBrand($file);
            self::writeIfChanged("$out/$name.css", self::css($brand));
            foreach (['logo', 'favicon'] as $key) {
                if ($brand[$key] !== null) {
                    $src = dirname($file) . '/' . $brand[$key];
                    if (!is_file($src)) {
                        throw new RuntimeException("tema $name: $key {$brand[$key]} não existe");
                    }
                    if (!is_dir("$out/$name")) {
                        mkdir("$out/$name", 0755, true);
                    }
                    self::writeIfChanged("$out/$name/{$brand[$key]}", (string) file_get_contents($src));
                }
            }
            $names[] = $name;
        }
        if (!in_array(self::DEFAULT, $names, true)) {
            throw new RuntimeException('tema padrão ausente: themes/' . self::DEFAULT . '/brand.php');
        }
        foreach (glob("$out/*.css") ?: [] as $css) {
            if (!in_array(basename($css, '.css'), $names, true)) {
                unlink($css);
            }
        }
        foreach (glob("$out/*", GLOB_ONLYDIR) ?: [] as $dir) {
            if (!in_array(basename($dir), $names, true)) {
                array_map('unlink', glob("$dir/*") ?: []);
                rmdir($dir);
            }
        }
        sort($names, SORT_STRING);
        return $names;
    }

    /**
     * Arquivos que o build geraria e que estão ausentes ou diferentes (para --check). @return list<string>
     */
    public static function outdated(): array
    {
        $out = AssetManifest::publicDir() . '/assets/themes';
        $bad = [];
        $names = [];
        foreach (glob(self::sourceDir() . '/*/brand.php') ?: [] as $file) {
            $name = basename(dirname($file));
            if (!self::isValidName($name)) {
                continue;
            }
            $names[] = $name;
            $brand = self::readBrand($file);
            $want = ["$out/$name.css" => self::css($brand)];
            foreach (['logo', 'favicon'] as $key) {
                if ($brand[$key] !== null) {
                    $want["$out/$name/{$brand[$key]}"] = (string) @file_get_contents(dirname($file) . '/' . $brand[$key]);
                }
            }
            foreach ($want as $f => $content) {
                if (!is_file($f) || file_get_contents($f) !== $content) {
                    $bad[] = $f;
                }
            }
        }
        foreach (glob("$out/*.css") ?: [] as $css) {
            if (!in_array(basename($css, '.css'), $names, true)) {
                $bad[] = $css;
            }
        }
        return $bad;
    }

    /** URL do favicon do tema (o padrão continua /favicon.ico). */
    public static function faviconUrl(array $brand, string $name): string
    {
        return $brand['favicon'] === null ? '/favicon.ico' : asset_url('/assets/themes/' . $name . '/' . $brand['favicon']);
    }

    public static function logoUrl(array $brand, string $name): ?string
    {
        return $brand['logo'] === null ? null : asset_url('/assets/themes/' . $name . '/' . $brand['logo']);
    }

    private static function writeIfChanged(string $file, string $content): void
    {
        if (!is_file($file) || file_get_contents($file) !== $content) {
            file_put_contents($file . '.tmp', $content);
            rename($file . '.tmp', $file);
        }
    }
}

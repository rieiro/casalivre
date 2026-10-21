<?php

declare(strict_types=1);

/**
 * Manifesto de assets com hash do conteúdo (Sprint 3, Etapa 0).
 * public/assets/manifest.json mapeia "/assets/<arquivo>" => primeiros 12 hex do sha256 do conteúdo; asset_url()
 * devolve "/assets/<arquivo>?v=<hash>". O nome do arquivo não muda (CSP 'self', rotas e nginx iguais), e o ?v=
 * muda sozinho quando o conteúdo muda. Gerado por scripts/build-assets.php (rodar no deploy; --check no CI/testes).
 */
require_once __DIR__ . '/Theme.php';

final class AssetManifest
{
    public const HASH_LEN = 12;
    /** Extensões versionadas: as que as páginas referenciam por URL (CSS, JS, estilo do mapa). */
    public const EXTENSIONS = ['css', 'js', 'json'];

    public static function publicDir(): string
    {
        return dirname(__DIR__) . '/public';
    }

    public static function file(): string
    {
        return self::publicDir() . '/assets/manifest.json';
    }

    public static function hashFile(string $absolute): string
    {
        return substr((string) hash_file('sha256', $absolute), 0, self::HASH_LEN);
    }

    /** @return array<string,string> "/assets/..." => hash, ordenado por caminho. */
    public static function build(): array
    {
        $base = self::publicDir();
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base . '/assets', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $info) {
            $abs = $info->getPathname();
            $ext = strtolower($info->getExtension());
            $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($abs, strlen($base)));
            // Logo/favicon dos temas (Etapa 2) também ganham ?v=; as demais imagens continuam fora do manifesto.
            $themeImage = str_starts_with($rel, Theme::assetPath()) && in_array($ext, Theme::IMAGE_EXT, true);
            if (!$info->isFile() || (!in_array($ext, self::EXTENSIONS, true) && !$themeImage)) {
                continue;
            }
            if ($abs === self::file()) {
                continue;
            }
            $out[$rel] = self::hashFile($abs);
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    public static function encode(array $manifest): string
    {
        return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /** @var null|array<string,string> per-process copy of manifest.json (the long-running queue worker clears it every tick) */
    private static ?array $cache = null;

    /** Drops the per-process copy: the next load() reads manifest.json again (e.g. after a rebuild). */
    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /** @return array<string,string> */
    public static function load(): array
    {
        if (self::$cache === null) {
            $raw = is_file(self::file()) ? (string) file_get_contents(self::file()) : '';
            $data = json_decode($raw, true);
            self::$cache = is_array($data) ? array_filter($data, 'is_string') : [];
        }
        return self::$cache;
    }
}

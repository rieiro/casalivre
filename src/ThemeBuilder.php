<?php

declare(strict_types=1);

require_once __DIR__ . '/Theme.php';

/**
 * Build dos temas (scripts/build-assets.php): themes/<nome>/brand.php -> public/assets/themes/<nome>.css,
 * <nome>/<logo|favicon> e <nome>/map-style.json (só se o tema declarar 'mapa'). expected() calcula uma vez os arquivos esperados; build() grava e remove órfãos,
 * outdated() (--check) compara com o disco sem gravar.
 */
final class ThemeBuilder
{
    public static function outDir(): string
    {
        return AssetManifest::publicDir() . Theme::assetPath();
    }

    /**
     * @return array{names:list<string>,files:array<string,string>} temas e arquivo absoluto => conteúdo
     */
    public static function expected(): array
    {
        $out = rtrim(self::outDir(), '/');
        $names = [];
        $files = [];
        foreach (glob(Theme::sourceDir() . '/*/brand.php') ?: [] as $file) {
            $name = basename(dirname($file));
            if (!Theme::isValidName($name)) {
                fwrite(STDERR, "tema ignorado (nome inválido): $name\n");
                continue;
            }
            $brand = Theme::readBrand($file);
            $files["$out/$name.css"] = Theme::css($brand);
            if ($brand['mapa'] !== []) {
                $files[AssetManifest::publicDir() . MapStyle::themePath($name)] = MapStyle::encode(MapStyle::generate(self::baseStyle(), $brand['mapa']));
            }
            foreach (['logo', 'favicon'] as $key) {
                if ($brand[$key] !== null) {
                    $src = dirname($file) . '/' . $brand[$key];
                    if (!is_file($src)) {
                        throw new RuntimeException("tema $name: $key {$brand[$key]} não existe");
                    }
                    $files["$out/$name/{$brand[$key]}"] = (string) file_get_contents($src);
                }
            }
            $names[] = $name;
        }
        if (!in_array(Theme::DEFAULT, $names, true)) {
            throw new RuntimeException('tema padrão ausente: themes/' . Theme::DEFAULT . '/brand.php');
        }
        sort($names, SORT_STRING);
        return ['names' => $names, 'files' => $files];
    }

    /** Estilo base do mapa (lido uma vez por build). */
    private static function baseStyle(): stdClass
    {
        static $base = null;
        if ($base === null) {
            $base = MapStyle::decode((string) file_get_contents(AssetManifest::publicDir() . MapStyle::BASE));
        }
        return $base;
    }

    /** Arquivos gerados no disco que não fazem parte do esperado (temas removidos). @return list<string> */
    private static function orphans(array $expected): array
    {
        $out = rtrim(self::outDir(), '/');
        $extra = [];
        foreach (array_merge(glob("$out/*.css") ?: [], glob("$out/*/*") ?: []) as $f) {
            if (is_file($f) && !isset($expected['files'][$f])) {
                $extra[] = $f;
            }
        }
        return $extra;
    }

    /** Grava o esperado e remove órfãos (só dentro de public/assets/themes/). @return list<string> temas */
    public static function build(): array
    {
        $expected = self::expected();
        foreach ($expected['files'] as $file => $content) {
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0755, true);
            }
            if (!is_file($file) || file_get_contents($file) !== $content) {
                file_put_contents($file . '.tmp', $content);
                rename($file . '.tmp', $file);
            }
        }
        foreach (self::orphans($expected) as $f) {
            unlink($f);
        }
        foreach (glob(rtrim(self::outDir(), '/') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if ((glob("$dir/*") ?: []) === []) {
                rmdir($dir);
            }
        }
        return $expected['names'];
    }

    /** Arquivos ausentes, diferentes ou órfãos (para --check). @return list<string> */
    public static function outdated(): array
    {
        $expected = self::expected();
        $bad = [];
        foreach ($expected['files'] as $file => $content) {
            if (!is_file($file) || file_get_contents($file) !== $content) {
                $bad[] = $file;
            }
        }
        return array_merge($bad, self::orphans($expected));
    }
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/Theme.php';
require_once __DIR__ . '/GroupRepository.php';
require_once __DIR__ . '/Acoes/GrupoValidacao.php';

/**
 * Resolvedor único de grupo/tema (Sprint 3, PR 7). Ordem, cada passo cai no seguinte:
 *  1. domínio da requisição (Host normalizado por GrupoValidacao::host, só domínio verificado, nunca o da plataforma);
 *  2. caminho /g/<slug>/;
 *  3. grupo do imóvel da página;
 *  4. tema da plataforma (settings.theme).
 * Só o Host da requisição (nunca X-Forwarded-Host). Host desconhecido -> plataforma, sem redirecionar.
 * Grupo achado com tema fora da lista de temas: o grupo vale, o tema cai no da plataforma.
 */
final class GrupoResolvedor
{
    public const DOMINIO = 'dominio';
    public const SLUG = 'slug';
    public const IMOVEL = 'imovel';
    public const PLATAFORMA = 'plataforma';

    /** @return array{origem:string,grupo:?array,tema:string} */
    public static function resolver(mixed $host, string $path, ?array $imovel = null): array
    {
        $h = GrupoValidacao::host($host);
        if ($h !== null && !GrupoValidacao::isPlatform($h) && ($g = GroupRepository::findByVerifiedDomain($h)) !== null) {
            return self::com(self::DOMINIO, $g);
        }
        if (preg_match('#^/g/([^/]+)(?:/|$)#', $path, $m) === 1 && ($slug = GrupoValidacao::slug($m[1])) !== null
            && ($g = GroupRepository::findBySlug($slug)) !== null) {
            return self::com(self::SLUG, $g);
        }
        $gid = (int) ($imovel['group_id'] ?? 0);
        if ($gid > 0 && ($imovel['status'] ?? 'active') === 'active' && ($g = GroupRepository::findById($gid)) !== null) {
            return self::com(self::IMOVEL, $g);
        }
        return ['origem' => self::PLATAFORMA, 'grupo' => null, 'tema' => Theme::current()];
    }

    /** Resolve para a requisição atual (Host e caminho do $_SERVER). @return array{origem:string,grupo:?array,tema:string} */
    public static function daRequisicao(?array $imovel = null): array
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        return self::resolver($_SERVER['HTTP_HOST'] ?? null, is_string($path) ? $path : '/', $imovel);
    }

    /** @return array{origem:string,grupo:array,tema:string} */
    private static function com(string $origem, array $g): array
    {
        $tema = (string) ($g['theme'] ?? '');
        return ['origem' => $origem, 'grupo' => $g, 'tema' => Theme::isAvailable($tema) ? $tema : Theme::current()];
    }
}

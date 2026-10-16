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
        if (($g = self::doDominio($host)) !== null) {
            return self::com(self::DOMINIO, $g);
        }
        if (preg_match('#^/g/([^/]+)(?:/|$)#', $path, $m) === 1 && ($g = GroupRepository::findBySlugValid($m[1])) !== null) {
            return self::com(self::SLUG, $g);
        }
        $gid = (int) ($imovel['group_id'] ?? 0);
        if ($gid > 0 && ($imovel['status'] ?? 'active') === 'active' && ($g = GroupRepository::findById($gid)) !== null) {
            return self::com(self::IMOVEL, $g);
        }
        return ['origem' => self::PLATAFORMA, 'grupo' => null, 'tema' => Theme::current()];
    }

    /**
     * Cache por requisição de doDominio: chave = Host JÁ normalizado por GrupoValidacao::host (nunca o Host bruto), valor =
     * grupo ou null (o "não achou" também fica guardado). Assim imovelNoHost e o resolver não repetem a mesma consulta
     * na página do imóvel vista pelo domínio de um grupo. Vive só no processo (static); testes limpam com limparCache().
     * @var array<string, ?array>
     */
    private static array $cacheDominio = [];

    /** Grupo do domínio verificado do Host (normalizado; nunca o da plataforma); senão null. */
    public static function doDominio(mixed $host): ?array
    {
        $h = GrupoValidacao::host($host);
        if ($h === null || GrupoValidacao::isPlatform($h)) {
            return null;
        }
        if (!array_key_exists($h, self::$cacheDominio)) {
            self::$cacheDominio[$h] = GroupRepository::findByVerifiedDomain($h);
        }
        return self::$cacheDominio[$h];
    }

    /** Esvazia o cache de doDominio (testes, entre casos que mudam grupo/domínio no mesmo processo). */
    public static function limparCache(): void
    {
        self::$cacheDominio = [];
    }

    /**
     * Grupo do slug (GroupRepository::findBySlugValid) visto pelo Host: null se o slug for inválido/inexistente ou se o
     * Host for domínio verificado de OUTRO grupo (no domínio de X, só X). Plataforma, Host desconhecido ou não
     * verificado: o grupo do slug.
     */
    public static function grupoDoSlugNoHost(mixed $slug, mixed $host): ?array
    {
        $g = GroupRepository::findBySlugValid($slug);
        if ($g === null) {
            return null;
        }
        $hostGroup = self::doDominio($host);
        return $hostGroup !== null && (int) $hostGroup['id'] !== (int) $g['id'] ? null : $g;
    }

    /**
     * Imóvel visto pelo Host (mesma regra do A1 do #67 na vitrine /g/<slug>/imoveis/<h>): no domínio verificado de um
     * grupo, só imóvel ativo DESSE grupo (outro grupo ou sem grupo -> null); fora dele (plataforma, Host desconhecido
     * ou não verificado), o imóvel ativo pelo código. Retorna [noDominioDeGrupo, imovel|null].
     * @return array{0: bool, 1: ?array}
     */
    public static function imovelNoHost(string $ref, mixed $host): array
    {
        $g = self::doDominio($host);
        return $g !== null
            ? [true, GroupRepository::activePropertyInGroup((int) $g['id'], $ref)]
            : [false, GroupRepository::activePropertyByRef($ref)];
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

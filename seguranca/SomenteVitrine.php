<?php

declare(strict_types=1);

/**
 * Domínio de grupo é só vitrine (divergência 7, Alta).
 *
 * Quem controla o DNS de um domínio verificado pode manter o TXT e apontar o A/AAAA para outro servidor, então nada que
 * envolva senha, sessão ou conta pode morar nesse domínio. Quando o Host é domínio verificado de grupo
 * (GrupoResolvedor::doDominio), vale uma lista do que é permitido (negar por padrão):
 *  - web, GET/HEAD: /, /busca, /busca/mapa, /imoveis/<h> (ver), /g/*, /ical/<32hex>, /tiles/brasil.pmtiles;
 *  - web, POST: /idioma e /imoveis/<h>/avaliar (públicos, sem credencial, com CSRF);
 *  - API, GET/HEAD: /api/busca, /api/cidades, /api/ceps, /api/imoveis/<h>, /api/ical/<32hex>(.ics).
 * O resto: GET/HEAD da web -> 302 para o MESMO caminho no APP_URL (Mailer::linkFromAppUrl; nunca o Host), com a query
 * se for segura; POST da web -> 404 (um 302 viraria GET e perderia o envio; um 307/308 reenviaria a senha, já digitada
 * no domínio do grupo, e acostumaria o usuário a logar ali); API -> 404 JSON.
 * Plataforma, Host desconhecido ou domínio não verificado: null (nada muda).
 * Roda antes da sessão: no domínio de grupo, rota recusada não abre sessão nem manda Set-Cookie.
 */
final class SomenteVitrine
{
    public const MAX_QUERY = 2048;

    private const WEB_GET = '#^/(?:busca(?:/mapa)?|imoveis/[a-f0-9]{16}|g(?:/.*)?|ical/[a-f0-9]{32}|tiles/brasil\.pmtiles)?$#';
    private const WEB_POST = '#^/(?:idioma|imoveis/[a-f0-9]{16}/avaliar)$#';
    private const API_GET = '#^/api/(?:busca|cidades|ceps|imoveis/[a-f0-9]{16}|ical/[a-f0-9]{32}(?:\.ics)?)$#';

    /**
     * Decisão pura (sem efeito): null = segue; ['redirect', url] ou ['not_found', null].
     * @return array{0: string, 1: ?string}|null
     */
    public static function decidir(mixed $host, string $path, string $method, string $query = ''): ?array
    {
        if (GrupoResolvedor::doDominio($host) === null) {
            return null;
        }
        $method = strtoupper($method);
        $leitura = $method === 'GET' || $method === 'HEAD';
        if (str_starts_with($path, '/api/') || $path === '/api') {
            return $leitura && preg_match(self::API_GET, $path) === 1 ? null : ['not_found', null];
        }
        if ($leitura && preg_match(self::WEB_GET, $path) === 1) {
            return null;
        }
        if ($method === 'POST' && preg_match(self::WEB_POST, $path) === 1) {
            return null;
        }
        if (!$leitura) {
            return ['not_found', null];
        }
        $url = self::urlNaPlataforma($path, $query);
        return $url === null ? ['not_found', null] : ['redirect', $url];
    }

    /** APP_URL + caminho (+ query segura). Sem APP_URL absoluto (dev) ou inválido: null (evita laço no mesmo Host). */
    public static function urlNaPlataforma(string $path, string $query): ?string
    {
        if (preg_match('/[\x00-\x20\x7f]/', $path) === 1) {
            return null;
        }
        $seguro = $query !== '' && strlen($query) <= self::MAX_QUERY && preg_match('/[\x00-\x20\x7f#]/', $query) !== 1;
        try {
            $url = Mailer::linkFromAppUrl($path . ($seguro ? '?' . $query : ''));
        } catch (RuntimeException) {
            return null;
        }
        return preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }

    /** Aplica a decisão: redireciona ou responde 404 e encerra. */
    public static function aplicar(mixed $host, string $path, string $method, string $query = ''): void
    {
        $d = self::decidir($host, $path, $method, $query);
        if ($d === null) {
            return;
        }
        header('Cache-Control: private, no-store');
        if ($d[0] === 'redirect') {
            header('Location: ' . $d[1], true, 302);
            exit;
        }
        http_response_code(404);
        if (str_starts_with($path, '/api')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Rota não encontrada.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Não encontrado.';
        exit;
    }
}

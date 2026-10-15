<?php

declare(strict_types=1);

/**
 * Prova de posse do domínio por DNS TXT (C1): registro _casalivre.<domínio> com o valor casalivre-verificacao=<token>.
 * Guarda o formato do registro, gera o token e consulta o DNS. A consulta é um callable fn(string $nome): list<string>
 * recebido no construtor (padrão: dns_get_record); os testes passam um falso e não tocam a rede.
 */
final class DomainVerification
{
    public const TXT_LABEL = '_casalivre.';
    public const TXT_VALUE = 'casalivre-verificacao=';

    /** Resultado de check(): TXT com o token, resposta sem o token (conta como falha), consulta falhou (não conta). */
    public const OK = 'ok';
    public const MISSING = 'ausente';
    public const LOOKUP_FAILED = 'falha_consulta';

    /** @var callable(string): (list<string>|false) */
    private $lookup;

    /**
     * Consulta: fn(string $nome): list<string>|false. Lista (vazia em NXDOMAIN/sem TXT) = resposta do DNS;
     * false ou exceção = consulta falhou (timeout, SERVFAIL). Padrão: dns_get_record, que devolve false nesses casos.
     * @param (callable(string): (list<string>|false))|null $lookup
     */
    public function __construct(?callable $lookup = null)
    {
        $this->lookup = $lookup ?? static function (string $name): array|false {
            $records = @dns_get_record($name, DNS_TXT);
            return is_array($records) ? array_column($records, 'txt') : false;
        };
    }

    public static function txtName(string $domain): string
    {
        return self::TXT_LABEL . $domain;
    }

    public static function txtValue(string $token): string
    {
        return self::TXT_VALUE . $token;
    }

    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** O TXT do domínio tem o valor do token? Token vazio: false sem consultar o DNS. */
    public function matches(string $domain, string $token): bool
    {
        return $this->check($domain, $token) === self::OK;
    }

    /** OK, MISSING ou LOOKUP_FAILED. Token vazio: MISSING sem consultar o DNS. */
    public function check(string $domain, string $token): string
    {
        if ($token === '') {
            return self::MISSING;
        }
        try {
            $out = ($this->lookup)(self::txtName($domain));
        } catch (Throwable) {
            return self::LOOKUP_FAILED;
        }
        if (!is_array($out)) {
            return self::LOOKUP_FAILED;
        }
        $expected = self::txtValue($token);
        foreach ($out as $txt) {
            if (is_string($txt) && hash_equals($expected, trim($txt))) {
                return self::OK;
            }
        }
        return self::MISSING;
    }
}

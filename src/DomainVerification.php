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

    /** @var callable(string): list<string> */
    private $lookup;

    /** @param (callable(string): list<string>)|null $lookup */
    public function __construct(?callable $lookup = null)
    {
        $this->lookup = $lookup ?? static function (string $name): array {
            $records = @dns_get_record($name, DNS_TXT);
            return is_array($records) ? array_column($records, 'txt') : [];
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
        if ($token === '') {
            return false;
        }
        $expected = self::txtValue($token);
        foreach ($this->records(self::txtName($domain)) as $txt) {
            if (hash_equals($expected, $txt)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private function records(string $name): array
    {
        $out = ($this->lookup)($name);
        return array_values(array_filter(array_map(static fn ($v) => is_string($v) ? trim($v) : '', is_array($out) ? $out : []), static fn ($v) => $v !== ''));
    }
}

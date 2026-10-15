<?php

declare(strict_types=1);

/** Validação das entradas de grupos (slug, nome, tema, domínio, papel, código do imóvel e id de membro). */
final class GrupoValidacao
{
    public const SLUG_RE = '/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/';
    public const ROLES = ['gestor', 'membro'];
    public const MAX_DOMAINS = 10;
    public const MAX_NAME = 80;

    public static function slug(mixed $slug): ?string
    {
        return is_string($slug) && preg_match(self::SLUG_RE, $slug) === 1 ? $slug : null;
    }

    public static function name(mixed $name): ?string
    {
        if (!is_string($name)) {
            return null;
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME || preg_match('/[\x00-\x1f\x7f]/u', $name) === 1 || !mb_check_encoding($name, 'UTF-8')) {
            return null;
        }
        return $name;
    }

    /** Domínio em minúsculas, sem esquema/porta/caminho, com TLD alfabético; nem o host da plataforma nem subdomínio dele. */
    public static function domain(mixed $domain): ?string
    {
        if (!is_string($domain)) {
            return null;
        }
        $d = strtolower(trim($domain));
        if ($d === '' || strlen($d) > 253 || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $d) !== 1) {
            return null;
        }
        $platform = strtolower((string) parse_url((string) (getenv('APP_URL') ?: ''), PHP_URL_HOST));
        if ($platform !== '' && ($d === $platform || str_ends_with($d, '.' . $platform))) {
            return null;
        }
        return $d;
    }

    public static function theme(mixed $theme): ?string
    {
        return is_string($theme) && in_array($theme, Theme::available(), true) ? $theme : null;
    }

    public static function role(mixed $role): ?string
    {
        return is_string($role) && in_array($role, self::ROLES, true) ? $role : null;
    }

    /** Código público do imóvel (16 hex). */
    public static function propertyRef(mixed $ref): ?string
    {
        return is_string($ref) && preg_match('/^[a-f0-9]{16}$/', $ref) === 1 ? $ref : null;
    }

    public static function userId(mixed $id): ?int
    {
        return is_string($id) && preg_match('/^[1-9][0-9]{0,9}$/', $id) === 1 ? (int) $id : null;
    }
}

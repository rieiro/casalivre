<?php

declare(strict_types=1);

/**
 * Leituras do modelo de grupos (Sprint 3, PR 6): groups, group_members, group_domains, group_property_invites e
 * properties.group_id. As ações (src/Acoes/Grupo*.php) gravam; rotas e ações leem por aqui.
 * Filtros de ativo centralizados: imóvel ativo (ACTIVE_PROPERTY) e conta ativa (ACTIVE_USER).
 */
final class GroupRepository
{
    /** Imóvel ativo (mesma regra de HouseRepository::findByUser), com alias opcional da tabela. */
    public static function activeProperty(string $alias = ''): string
    {
        $col = $alias === '' ? 'status' : $alias . '.status';
        return "COALESCE(NULLIF($col, ''), 'active') = 'active'";
    }

    /** Conta ativa, com alias da tabela users. */
    public static function activeUser(string $alias): string
    {
        return "$alias.status = 'active'";
    }

    public static function findBySlug(string $slug): ?array
    {
        $st = db()->prepare('SELECT * FROM groups WHERE slug = ?');
        $st->execute([$slug]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function slugById(int $id): string
    {
        $st = db()->prepare('SELECT slug FROM groups WHERE id = ?');
        $st->execute([$id]);
        return (string) $st->fetchColumn();
    }

    /** @return list<array> */
    public static function all(): array
    {
        return db()->query('SELECT * FROM groups ORDER BY slug')->fetchAll();
    }

    /** Papel gravado (sem filtrar conta), ou null. */
    public static function storedRole(int $groupId, int $userId): ?string
    {
        $st = db()->prepare('SELECT role FROM group_members WHERE group_id = ? AND user_id = ?');
        $st->execute([$groupId, $userId]);
        $role = $st->fetchColumn();
        return is_string($role) ? $role : null;
    }

    /** Papel de conta ativa no grupo, ou null. */
    public static function activeRole(int $groupId, int $userId): ?string
    {
        $st = db()->prepare('SELECT m.role FROM group_members m JOIN users u ON u.id = m.user_id WHERE m.group_id = ? AND m.user_id = ? AND ' . self::activeUser('u'));
        $st->execute([$groupId, $userId]);
        $role = $st->fetchColumn();
        return is_string($role) ? $role : null;
    }

    /** Grupos de que o usuário é membro (com papel). @return list<array> */
    public static function forUser(int $userId): array
    {
        $st = db()->prepare('SELECT g.*, m.role FROM group_members m JOIN groups g ON g.id = m.group_id WHERE m.user_id = ? ORDER BY g.slug');
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    /** @return list<array{user_id:int,name:string,email:string,role:string}> */
    public static function members(int $groupId): array
    {
        $st = db()->prepare('SELECT m.user_id, u.name, u.email, m.role FROM group_members m JOIN users u ON u.id = m.user_id WHERE m.group_id = ? ORDER BY u.name, m.user_id');
        $st->execute([$groupId]);
        return $st->fetchAll();
    }

    /** @return list<string> */
    public static function domains(int $groupId): array
    {
        $st = db()->prepare('SELECT domain FROM group_domains WHERE group_id = ? ORDER BY domain');
        $st->execute([$groupId]);
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Domínios com estado da verificação. @return list<array{domain:string,verify_token:string,verified_at:?string,last_check_at:?string,fail_count:int}> */
    public static function domainRows(int $groupId): array
    {
        $st = db()->prepare('SELECT domain, verify_token, verified_at, last_check_at, fail_count FROM group_domains WHERE group_id = ? ORDER BY domain');
        $st->execute([$groupId]);
        return $st->fetchAll();
    }

    /** @return array{domain:string,verify_token:string,verified_at:?string}|null */
    public static function domainRow(int $groupId, string $domain): ?array
    {
        $st = db()->prepare('SELECT domain, verify_token, verified_at FROM group_domains WHERE group_id = ? AND domain = ?');
        $st->execute([$groupId, $domain]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** Novo token e verificação zerada; true se gravou uma linha. */
    public static function resetDomainToken(int $groupId, string $domain, string $token): bool
    {
        $st = db()->prepare('UPDATE group_domains SET verify_token = ?, verified_at = NULL, last_check_at = NULL, fail_count = 0 WHERE group_id = ? AND domain = ?');
        $st->execute([$token, $groupId, $domain]);
        return $st->rowCount() === 1;
    }

    /** Pede checagem ao cron (last_check_at NULL), se o token ainda for o mesmo; true se gravou uma linha. */
    public static function requestDomainCheck(int $groupId, string $domain, string $token): bool
    {
        $st = db()->prepare('UPDATE group_domains SET last_check_at = NULL WHERE group_id = ? AND domain = ? AND verify_token = ?');
        $st->execute([$groupId, $domain, $token]);
        return $st->rowCount() === 1;
    }

    /** Domínios com token, para o cron de reverificação. @return list<array> */
    public static function domainsWithToken(): array
    {
        return db()->query("SELECT d.id, d.group_id, g.slug, d.domain, d.verify_token, d.verified_at, d.last_check_at, d.fail_count FROM group_domains d JOIN groups g ON g.id = d.group_id WHERE d.verify_token <> '' ORDER BY d.id")->fetchAll();
    }

    /** Resultado de uma checagem do cron, só se o token não mudou no meio; true se gravou uma linha. */
    public static function saveDomainCheck(int $id, string $token, ?string $verifiedAt, string $checkedAt, int $failCount): bool
    {
        $st = db()->prepare('UPDATE group_domains SET verified_at = ?, last_check_at = ?, fail_count = ? WHERE id = ? AND verify_token = ?');
        $st->execute([$verifiedAt, $checkedAt, $failCount, $id, $token]);
        return $st->rowCount() === 1;
    }

    /** Domínios sem verificação já conferidos pelo cron (para o aviso aos admins). @return list<array{slug:string,domain:string}> */
    public static function unverifiedCheckedDomains(): array
    {
        return db()->query("SELECT g.slug, d.domain FROM group_domains d JOIN groups g ON g.id = d.group_id WHERE d.verify_token <> '' AND d.verified_at IS NULL AND d.last_check_at IS NOT NULL ORDER BY g.slug, d.domain")->fetchAll();
    }

    /** E-mails dos admins ativos da plataforma (quem cuida dos domínios). @return list<string> */
    public static function adminEmails(): array
    {
        return array_map('strval', db()->query("SELECT email FROM users WHERE is_admin = 1 AND status = 'active' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Grupo dono do domínio, só se o domínio estiver verificado (C1). */
    public static function findByVerifiedDomain(string $domain): ?array
    {
        $st = db()->prepare('SELECT g.* FROM group_domains d JOIN groups g ON g.id = d.group_id WHERE d.domain = ? AND d.verified_at IS NOT NULL');
        $st->execute([$domain]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        $st = db()->prepare('SELECT * FROM groups WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** Imóveis ativos do grupo (só dados públicos). @return list<array> */
    public static function properties(int $groupId): array
    {
        $st = db()->prepare('SELECT id, public_hash, title, city FROM properties WHERE group_id = ? AND ' . self::activeProperty() . ' ORDER BY title, id');
        $st->execute([$groupId]);
        return $st->fetchAll();
    }

    /** Convites pendentes do grupo (imóveis ativos). @return list<array> */
    public static function invites(int $groupId): array
    {
        $st = db()->prepare('SELECT p.id, p.public_hash, p.title, p.city FROM group_property_invites i JOIN properties p ON p.id = i.property_id WHERE i.group_id = ? AND ' . self::activeProperty('p') . ' ORDER BY p.title, p.id');
        $st->execute([$groupId]);
        return $st->fetchAll();
    }

    public static function hasInvite(int $groupId, int $propertyId): bool
    {
        $st = db()->prepare('SELECT 1 FROM group_property_invites WHERE group_id = ? AND property_id = ?');
        $st->execute([$groupId, $propertyId]);
        return (bool) $st->fetchColumn();
    }

    /** Convites pendentes para imóveis ativos do dono. @return list<array> */
    public static function invitesForOwner(int $userId): array
    {
        $st = db()->prepare('SELECT p.id, p.public_hash, p.title, g.slug, g.name FROM group_property_invites i JOIN properties p ON p.id = i.property_id JOIN groups g ON g.id = i.group_id WHERE p.user_id = ? AND ' . self::activeProperty('p') . ' ORDER BY p.title, g.slug');
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    /** Imóveis ativos do dono que estão num grupo. @return list<array> */
    public static function ownerLinked(int $userId): array
    {
        $st = db()->prepare('SELECT p.id, p.public_hash, p.title, g.slug, g.name FROM properties p JOIN groups g ON g.id = p.group_id WHERE p.user_id = ? AND ' . self::activeProperty('p') . ' ORDER BY p.title');
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    /** Imóvel ativo pelo hash público, ou null. */
    public static function activePropertyByRef(string $ref): ?array
    {
        $row = HouseRepository::findByPublicHash($ref);
        return $row && ($row['status'] ?? 'active') === 'active' ? $row : null;
    }

    /** Violação de UNIQUE (SQLite 23000 + "UNIQUE", pgsql 23505). */
    public static function isUniqueViolation(PDOException $e): bool
    {
        $code = (string) (($e->errorInfo[0] ?? null) ?: $e->getCode());
        return $code === '23505' || ($code === '23000' && str_contains($e->getMessage(), 'UNIQUE'));
    }
}

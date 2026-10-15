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

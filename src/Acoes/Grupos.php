<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';

/**
 * Modelo de grupos (Sprint 3, PR 6): groups (slug, nome, tema), group_members (papéis), group_domains (lista fixa)
 * e properties.group_id, que só muda com o aceite do dono do imóvel (convite em group_property_invites).
 *
 * Permissões (checadas AQUI, além do adaptador web):
 * - admin (users.is_admin = 1): cria/edita grupo, domínios e membros;
 * - gestor do grupo: convida imóvel, cancela convite, tira imóvel do grupo;
 * - membro: só vê o grupo;
 * - dono do imóvel (ativo): aceita ou recusa convite, tira o imóvel do grupo.
 * Quem não tem permissão recebe 'nao_encontrado' (mesma resposta de grupo/imóvel inexistente: sem enumeração).
 * Toda mudança devolve um evento do activity_log; tentativas sem efeito não geram log.
 */
final class Grupos
{
    public const SLUG_RE = '/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/';
    public const ROLES = ['gestor', 'membro'];
    public const MAX_DOMAINS = 10;
    public const MAX_NAME = 80;
    /** Mensagem única do convite (imóvel inexistente, inativo ou já convidado: mesma resposta). */
    public const INVITE_SENT = 'Convite registrado. Se o imóvel existir e estiver ativo, o dono verá o convite.';

    // ---- leitura ----

    public static function bySlug(mixed $slug): ?array
    {
        if (!is_string($slug) || preg_match(self::SLUG_RE, $slug) !== 1) {
            return null;
        }
        $st = db()->prepare('SELECT * FROM groups WHERE slug = ?');
        $st->execute([$slug]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** @return list<array> */
    public static function all(): array
    {
        return db()->query('SELECT * FROM groups ORDER BY slug')->fetchAll();
    }

    /** Papel do usuário ativo no grupo, ou null. */
    public static function role(int $groupId, int $userId): ?string
    {
        $st = db()->prepare("SELECT m.role FROM group_members m JOIN users u ON u.id = m.user_id WHERE m.group_id = ? AND m.user_id = ? AND u.status = 'active'");
        $st->execute([$groupId, $userId]);
        $role = $st->fetchColumn();
        return is_string($role) && in_array($role, self::ROLES, true) ? $role : null;
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
        $st = db()->prepare("SELECT id, public_hash, title, city FROM properties WHERE group_id = ? AND COALESCE(NULLIF(status, ''), 'active') = 'active' ORDER BY title, id");
        $st->execute([$groupId]);
        return $st->fetchAll();
    }

    /** Convites pendentes do grupo (imóveis ativos). @return list<array> */
    public static function invites(int $groupId): array
    {
        $st = db()->prepare("SELECT p.id, p.public_hash, p.title, p.city FROM group_property_invites i JOIN properties p ON p.id = i.property_id WHERE i.group_id = ? AND COALESCE(NULLIF(p.status, ''), 'active') = 'active' ORDER BY p.title, p.id");
        $st->execute([$groupId]);
        return $st->fetchAll();
    }

    /** Convites pendentes para imóveis ativos do dono. @return list<array> */
    public static function invitesForOwner(int $userId): array
    {
        $st = db()->prepare("SELECT p.id, p.public_hash, p.title, g.slug, g.name FROM group_property_invites i JOIN properties p ON p.id = i.property_id JOIN groups g ON g.id = i.group_id WHERE p.user_id = ? AND COALESCE(NULLIF(p.status, ''), 'active') = 'active' ORDER BY p.title, g.slug");
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    /** Imóveis ativos do dono que estão num grupo. @return list<array> */
    public static function ownerLinked(int $userId): array
    {
        $st = db()->prepare("SELECT p.id, p.public_hash, p.title, g.slug, g.name FROM properties p JOIN groups g ON g.id = p.group_id WHERE p.user_id = ? AND COALESCE(NULLIF(p.status, ''), 'active') = 'active' ORDER BY p.title");
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    // ---- validação ----

    public static function cleanName(mixed $name): ?string
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

    /** Domínio em minúsculas, sem esquema/porta/caminho, com TLD alfabético; não pode ser o da plataforma. */
    public static function cleanDomain(mixed $domain): ?string
    {
        if (!is_string($domain)) {
            return null;
        }
        $d = strtolower(trim($domain));
        if ($d === '' || strlen($d) > 253 || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $d) !== 1) {
            return null;
        }
        $platform = strtolower((string) parse_url((string) (getenv('APP_URL') ?: ''), PHP_URL_HOST));
        if ($platform !== '' && $d === $platform) {
            return null;
        }
        return $d;
    }

    private static function themeOk(mixed $theme): bool
    {
        return is_string($theme) && in_array($theme, Theme::available(), true);
    }

    private static function isAdmin(array $user): bool
    {
        return (int) ($user['is_admin'] ?? 0) === 1 && ($user['status'] ?? '') === 'active';
    }

    private static function denied(): Resultado
    {
        return new Resultado('nao_encontrado', 'Não encontrado.');
    }

    /** Imóvel ativo pelo hash público, ou null. */
    private static function property(mixed $ref): ?array
    {
        if (!is_string($ref)) {
            return null;
        }
        $row = HouseRepository::findByPublicHash($ref);
        return $row && ($row['status'] ?? 'active') === 'active' ? $row : null;
    }

    private static function unique(PDOException $e): bool
    {
        $code = (string) (($e->errorInfo[0] ?? null) ?: $e->getCode());
        return $code === '23505' || ($code === '23000' && str_contains($e->getMessage(), 'UNIQUE'));
    }

    // ---- admin ----

    public static function create(array $admin, mixed $slug, mixed $name, mixed $theme): Resultado
    {
        if (!self::isAdmin($admin)) {
            return self::denied();
        }
        $name = self::cleanName($name);
        if (!is_string($slug) || preg_match(self::SLUG_RE, $slug) !== 1) {
            return new Resultado('invalido', 'Endereço inválido: use de 1 a 40 letras minúsculas, números e hífen (sem hífen no início ou no fim).');
        }
        if ($name === null) {
            return new Resultado('invalido', 'Nome inválido: de 1 a ' . self::MAX_NAME . ' caracteres.');
        }
        if (!self::themeOk($theme)) {
            return new Resultado('invalido', 'Tema inválido.');
        }
        try {
            db()->prepare('INSERT INTO groups (slug, name, theme) VALUES (?, ?, ?)')->execute([$slug, $name, $theme]);
        } catch (PDOException $e) {
            if (!self::unique($e)) {
                throw $e;
            }
            return new Resultado('conflito', 'Já existe um grupo com este endereço.');
        }
        return new Resultado('ok', null, ['slug' => $slug], [
            [ActivityLog::GROUP_CREATE, ['grupo' => $slug, 'tema' => $theme], null, ['grupo' => $slug, 'nome' => $name, 'tema' => $theme]],
        ]);
    }

    public static function update(array $admin, array $group, mixed $name, mixed $theme): Resultado
    {
        if (!self::isAdmin($admin)) {
            return self::denied();
        }
        $name = self::cleanName($name);
        if ($name === null) {
            return new Resultado('invalido', 'Nome inválido: de 1 a ' . self::MAX_NAME . ' caracteres.');
        }
        if (!self::themeOk($theme)) {
            return new Resultado('invalido', 'Tema inválido.');
        }
        if ($name === $group['name'] && $theme === $group['theme']) {
            return new Resultado('ok', null, ['mudou' => false]);
        }
        db()->prepare('UPDATE groups SET name = ?, theme = ? WHERE id = ?')->execute([$name, $theme, (int) $group['id']]);
        return new Resultado('ok', null, ['mudou' => true], [
            [ActivityLog::GROUP_UPDATE, ['grupo' => $group['slug']], ['nome' => $group['name'], 'tema' => $group['theme']], ['nome' => $name, 'tema' => $theme]],
        ]);
    }

    public static function addDomain(array $admin, array $group, mixed $domain): Resultado
    {
        if (!self::isAdmin($admin)) {
            return self::denied();
        }
        $d = self::cleanDomain($domain);
        if ($d === null) {
            return new Resultado('invalido', 'Domínio inválido: só o nome (ex.: imobiliaria.com.br), sem http://, porta ou caminho, e diferente do domínio da plataforma.');
        }
        if (count(self::domains((int) $group['id'])) >= self::MAX_DOMAINS) {
            return new Resultado('invalido', 'Limite de ' . self::MAX_DOMAINS . ' domínios por grupo.');
        }
        try {
            db()->prepare('INSERT INTO group_domains (group_id, domain) VALUES (?, ?)')->execute([(int) $group['id'], $d]);
        } catch (PDOException $e) {
            if (!self::unique($e)) {
                throw $e;
            }
            return new Resultado('conflito', 'Este domínio já está cadastrado.');
        }
        return new Resultado('ok', null, [], [[ActivityLog::GROUP_DOMAIN_ADD, ['grupo' => $group['slug'], 'dominio' => $d], null, ['dominio' => $d]]]);
    }

    public static function removeDomain(array $admin, array $group, mixed $domain): Resultado
    {
        if (!self::isAdmin($admin)) {
            return self::denied();
        }
        $d = self::cleanDomain($domain);
        if ($d === null) {
            return new Resultado('invalido', 'Domínio inválido.');
        }
        $st = db()->prepare('DELETE FROM group_domains WHERE group_id = ? AND domain = ?');
        $st->execute([(int) $group['id'], $d]);
        if ($st->rowCount() !== 1) {
            return new Resultado('ok', null, ['mudou' => false]);
        }
        return new Resultado('ok', null, ['mudou' => true], [[ActivityLog::GROUP_DOMAIN_REMOVE, ['grupo' => $group['slug'], 'dominio' => $d], ['dominio' => $d], null]]);
    }

    /** Inclui ou troca o papel. E-mail sem conta ativa: mensagem única (não diz se existe, está inativa ou apagada). */
    public static function setMember(array $admin, array $group, mixed $email, mixed $role): Resultado
    {
        if (!self::isAdmin($admin)) {
            return self::denied();
        }
        if (!is_string($role) || !in_array($role, self::ROLES, true)) {
            return new Resultado('invalido', 'Papel inválido.');
        }
        $user = is_string($email) && trim($email) !== '' ? UserRepository::findByEmail(Email::normalize($email)) : null;
        if (!$user || ($user['status'] ?? '') !== 'active') {
            return new Resultado('invalido', 'Não há conta ativa com este e-mail.');
        }
        $uid = (int) $user['id'];
        $old = self::role((int) $group['id'], $uid);
        if ($old === $role) {
            return new Resultado('ok', null, ['mudou' => false]);
        }
        if ($old === null) {
            db()->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?')->execute([(int) $group['id'], $uid]);
            db()->prepare('INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)')->execute([(int) $group['id'], $uid, $role]);
        } else {
            db()->prepare('UPDATE group_members SET role = ? WHERE group_id = ? AND user_id = ?')->execute([$role, (int) $group['id'], $uid]);
        }
        return new Resultado('ok', null, ['mudou' => true], [
            [ActivityLog::GROUP_MEMBER_SET, ['grupo' => $group['slug'], 'user_id' => $uid, 'papel' => $role], $old === null ? null : ['papel' => $old], ['papel' => $role]],
        ]);
    }

    public static function removeMember(array $admin, array $group, mixed $userId): Resultado
    {
        if (!self::isAdmin($admin)) {
            return self::denied();
        }
        if (!is_string($userId) || preg_match('/^[1-9][0-9]{0,9}$/', $userId) !== 1) {
            return new Resultado('invalido', 'Membro inválido.');
        }
        $st = db()->prepare('SELECT role FROM group_members WHERE group_id = ? AND user_id = ?');
        $st->execute([(int) $group['id'], (int) $userId]);
        $old = $st->fetchColumn();
        if ($old === false) {
            return new Resultado('ok', null, ['mudou' => false]);
        }
        db()->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?')->execute([(int) $group['id'], (int) $userId]);
        return new Resultado('ok', null, ['mudou' => true], [
            [ActivityLog::GROUP_MEMBER_REMOVE, ['grupo' => $group['slug'], 'user_id' => (int) $userId], ['papel' => (string) $old], null],
        ]);
    }

    // ---- gestor ----

    /** Convite: só gestor. Imóvel inexistente/inativo/já no grupo/já convidado: mesma resposta, sem gravar nem logar. */
    public static function invite(array $actor, array $group, mixed $ref): Resultado
    {
        if (self::role((int) $group['id'], (int) $actor['id']) !== 'gestor') {
            return self::denied();
        }
        if (!is_string($ref) || preg_match('/^[a-f0-9]{16}$/', $ref) !== 1) {
            return new Resultado('invalido', 'Código do imóvel inválido: são os 16 caracteres do endereço do anúncio (/imoveis/…).');
        }
        $p = self::property($ref);
        $sent = new Resultado('ok', self::INVITE_SENT, ['mudou' => false]);
        if ($p === null || (int) ($p['group_id'] ?? 0) === (int) $group['id']) {
            return $sent;
        }
        $has = db()->prepare('SELECT 1 FROM group_property_invites WHERE group_id = ? AND property_id = ?');
        $has->execute([(int) $group['id'], (int) $p['id']]);
        if ($has->fetchColumn()) {
            return $sent;
        }
        try {
            db()->prepare('INSERT INTO group_property_invites (group_id, property_id, invited_by) VALUES (?, ?, ?)')->execute([(int) $group['id'], (int) $p['id'], (int) $actor['id']]);
        } catch (PDOException $e) {
            if (!self::unique($e)) {
                throw $e;
            }
            return $sent;
        }
        return new Resultado('ok', self::INVITE_SENT, ['mudou' => true], [[ActivityLog::GROUP_INVITE, ['grupo' => $group['slug'], 'imovel_id' => (int) $p['id']]]]);
    }

    public static function cancelInvite(array $actor, array $group, mixed $ref): Resultado
    {
        if (self::role((int) $group['id'], (int) $actor['id']) !== 'gestor') {
            return self::denied();
        }
        $p = self::property($ref);
        if ($p === null) {
            return new Resultado('ok', null, ['mudou' => false]);
        }
        $st = db()->prepare('DELETE FROM group_property_invites WHERE group_id = ? AND property_id = ?');
        $st->execute([(int) $group['id'], (int) $p['id']]);
        if ($st->rowCount() !== 1) {
            return new Resultado('ok', null, ['mudou' => false]);
        }
        return new Resultado('ok', null, ['mudou' => true], [[ActivityLog::GROUP_INVITE_CANCEL, ['grupo' => $group['slug'], 'imovel_id' => (int) $p['id']]]]);
    }

    /** Gestor tira do grupo um imóvel que está nele (sem aceite do dono: sair só reduz o vínculo). */
    public static function removeProperty(array $actor, array $group, mixed $ref): Resultado
    {
        if (self::role((int) $group['id'], (int) $actor['id']) !== 'gestor') {
            return self::denied();
        }
        $p = self::property($ref);
        if ($p === null) {
            return new Resultado('ok', null, ['mudou' => false]);
        }
        $st = db()->prepare('UPDATE properties SET group_id = NULL WHERE id = ? AND group_id = ?');
        $st->execute([(int) $p['id'], (int) $group['id']]);
        if ($st->rowCount() !== 1) {
            return new Resultado('ok', null, ['mudou' => false]);
        }
        return new Resultado('ok', null, ['mudou' => true], [[ActivityLog::GROUP_PROPERTY_REMOVE, ['grupo' => $group['slug'], 'imovel_id' => (int) $p['id'], 'por' => 'gestor'], ['grupo' => $group['slug']], null]]);
    }

    // ---- dono do imóvel ----

    /** Imóvel ativo do dono (o adaptador já resolveu por findByUserOrFail; rechecado aqui). */
    private static function owned(array $owner, array $property): bool
    {
        return (int) $property['user_id'] === (int) $owner['id'] && ($property['status'] ?? 'active') === 'active' && ($owner['status'] ?? '') === 'active';
    }

    /** Aceite: precisa de convite do grupo; imóvel em outro grupo -> conflito (sair antes). */
    public static function accept(array $owner, array $property, mixed $slug): Resultado
    {
        if (!self::owned($owner, $property)) {
            return self::denied();
        }
        $group = self::bySlug($slug);
        $none = new Resultado('invalido', 'Convite não encontrado.');
        if ($group === null) {
            return $none;
        }
        return Db::transactional(db(), static function (PDO $pdo) use ($group, $property, $none): Resultado {
            $inv = $pdo->prepare('SELECT 1 FROM group_property_invites WHERE group_id = ? AND property_id = ?');
            $inv->execute([(int) $group['id'], (int) $property['id']]);
            if (!$inv->fetchColumn()) {
                return $none;
            }
            $upd = $pdo->prepare('UPDATE properties SET group_id = ? WHERE id = ? AND group_id IS NULL');
            $upd->execute([(int) $group['id'], (int) $property['id']]);
            if ($upd->rowCount() !== 1) {
                return new Resultado('conflito', 'O imóvel já está num grupo. Tire-o do grupo atual antes de aceitar outro.');
            }
            $pdo->prepare('DELETE FROM group_property_invites WHERE group_id = ? AND property_id = ?')->execute([(int) $group['id'], (int) $property['id']]);
            return new Resultado('ok', null, ['mudou' => true], [[ActivityLog::GROUP_INVITE_ACCEPT, ['grupo' => $group['slug'], 'imovel_id' => (int) $property['id']], null, ['grupo' => $group['slug']]]]);
        }, true);
    }

    public static function reject(array $owner, array $property, mixed $slug): Resultado
    {
        if (!self::owned($owner, $property)) {
            return self::denied();
        }
        $group = self::bySlug($slug);
        if ($group === null) {
            return new Resultado('ok', null, ['mudou' => false]);
        }
        $st = db()->prepare('DELETE FROM group_property_invites WHERE group_id = ? AND property_id = ?');
        $st->execute([(int) $group['id'], (int) $property['id']]);
        if ($st->rowCount() !== 1) {
            return new Resultado('ok', null, ['mudou' => false]);
        }
        return new Resultado('ok', null, ['mudou' => true], [[ActivityLog::GROUP_INVITE_REJECT, ['grupo' => $group['slug'], 'imovel_id' => (int) $property['id']]]]);
    }

    public static function leave(array $owner, array $property): Resultado
    {
        if (!self::owned($owner, $property)) {
            return self::denied();
        }
        $gid = (int) ($property['group_id'] ?? 0);
        if ($gid === 0) {
            return new Resultado('ok', null, ['mudou' => false]);
        }
        $sel = db()->prepare('SELECT slug FROM groups WHERE id = ?');
        $sel->execute([$gid]);
        $slug = (string) $sel->fetchColumn();
        $st = db()->prepare('UPDATE properties SET group_id = NULL WHERE id = ? AND group_id = ?');
        $st->execute([(int) $property['id'], $gid]);
        if ($st->rowCount() !== 1) {
            return new Resultado('ok', null, ['mudou' => false]);
        }
        return new Resultado('ok', null, ['mudou' => true], [[ActivityLog::GROUP_PROPERTY_REMOVE, ['grupo' => $slug, 'imovel_id' => (int) $property['id'], 'por' => 'dono'], ['grupo' => $slug], null]]);
    }
}

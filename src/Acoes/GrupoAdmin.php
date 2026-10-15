<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';
require_once __DIR__ . '/GrupoValidacao.php';
require_once dirname(__DIR__) . '/GroupRepository.php';
require_once dirname(__DIR__) . '/DomainVerification.php';
require_once dirname(__DIR__) . '/DomainReverification.php';

/**
 * Grupos pelo admin (users.is_admin = 1, conta ativa): cria, edita nome/tema, inclui/remove domínio e membro.
 * Sem permissão -> 'nao_encontrado'. Toda mudança devolve um evento do activity_log; sem efeito não loga.
 */
final class GrupoAdmin
{
    /** Guarda do ator: admin ativo, ou a resposta de recusa. */
    private static function guard(array $admin): ?Resultado
    {
        return (int) ($admin['is_admin'] ?? 0) === 1 && ($admin['status'] ?? '') === 'active'
            ? null
            : new Resultado('nao_encontrado', 'Não encontrado.');
    }

    public static function create(array $admin, mixed $slug, mixed $name, mixed $theme): Resultado
    {
        if ($deny = self::guard($admin)) {
            return $deny;
        }
        $name = GrupoValidacao::name($name);
        $slug = GrupoValidacao::slug($slug);
        if ($slug === null) {
            return new Resultado('invalido', 'Endereço inválido: use de 1 a 40 letras minúsculas, números e hífen (sem hífen no início ou no fim).');
        }
        if ($name === null) {
            return new Resultado('invalido', 'Nome inválido: de 1 a ' . GrupoValidacao::MAX_NAME . ' caracteres.');
        }
        $theme = GrupoValidacao::theme($theme);
        if ($theme === null) {
            return new Resultado('invalido', 'Tema inválido.');
        }
        try {
            db()->prepare('INSERT INTO groups (slug, name, theme) VALUES (?, ?, ?)')->execute([$slug, $name, $theme]);
        } catch (PDOException $e) {
            if (!GroupRepository::isUniqueViolation($e)) {
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
        if ($deny = self::guard($admin)) {
            return $deny;
        }
        $name = GrupoValidacao::name($name);
        if ($name === null) {
            return new Resultado('invalido', 'Nome inválido: de 1 a ' . GrupoValidacao::MAX_NAME . ' caracteres.');
        }
        $theme = GrupoValidacao::theme($theme);
        if ($theme === null) {
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
        if ($deny = self::guard($admin)) {
            return $deny;
        }
        $d = GrupoValidacao::domain($domain);
        if ($d === null) {
            return new Resultado('invalido', 'Domínio inválido: só o nome (ex.: imobiliaria.com.br), sem http://, porta ou caminho, e fora do domínio da plataforma e dos subdomínios dele.');
        }
        if (count(GroupRepository::domains((int) $group['id'])) >= GrupoValidacao::MAX_DOMAINS) {
            return new Resultado('invalido', 'Limite de ' . GrupoValidacao::MAX_DOMAINS . ' domínios por grupo.');
        }
        try {
            db()->prepare('INSERT INTO group_domains (group_id, domain, verify_token) VALUES (?, ?, ?)')->execute([(int) $group['id'], $d, DomainVerification::newToken()]);
        } catch (PDOException $e) {
            if (!GroupRepository::isUniqueViolation($e)) {
                throw $e;
            }
            return new Resultado('conflito', 'Este domínio já está cadastrado.');
        }
        return new Resultado('ok', null, [], [[ActivityLog::GROUP_DOMAIN_ADD, ['grupo' => $group['slug'], 'dominio' => $d], null, ['dominio' => $d]]]);
    }

    public static function removeDomain(array $admin, array $group, mixed $domain): Resultado
    {
        if ($deny = self::guard($admin)) {
            return $deny;
        }
        $d = GrupoValidacao::domain($domain);
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

    /** Novo token de verificação: o domínio volta a "não verificado" e deixa de resolver até a nova checagem. */
    public static function startDomainVerification(array $admin, array $group, mixed $domain): Resultado
    {
        if ($deny = self::guard($admin)) {
            return $deny;
        }
        $d = GrupoValidacao::domain($domain);
        $row = $d === null ? null : GroupRepository::domainRow((int) $group['id'], $d);
        if ($row === null) {
            return new Resultado('invalido', 'Domínio não cadastrado neste grupo.');
        }
        if (!GroupRepository::resetDomainToken((int) $group['id'], $d, DomainVerification::newToken())) {
            return new Resultado('ok', null, ['mudou' => false]);
        }
        return new Resultado('ok', 'Novo token gerado: publique o registro TXT e clique em Verificar.', ['mudou' => true], [
            [ActivityLog::GROUP_DOMAIN_VERIFY_START, ['grupo' => $group['slug'], 'dominio' => $d], ['verificado' => $row['verified_at'] !== null], ['verificado' => false]],
        ]);
    }

    /**
     * "Verificar agora" (pr64 C2): não consulta o DNS na requisição; só marca a checagem como pedida (last_check_at NULL)
     * e o cron (scripts/verify-domains.php, a cada hora) confere. Sem token: pede para gerar um. Sem log (o cron loga
     * a mudança de estado: 45 ao verificar, 46 ao perder a verificação).
     */
    public static function requestDomainCheck(array $admin, array $group, mixed $domain): Resultado
    {
        if ($deny = self::guard($admin)) {
            return $deny;
        }
        $d = GrupoValidacao::domain($domain);
        $row = $d === null ? null : GroupRepository::domainRow((int) $group['id'], $d);
        if ($row === null) {
            return new Resultado('invalido', 'Domínio não cadastrado neste grupo.');
        }
        $token = (string) $row['verify_token'];
        if ($token === '') {
            return new Resultado('invalido', 'Registro TXT não encontrado: este domínio ainda não tem token; clique em "Gerar novo token", publique o registro e verifique de novo.');
        }
        $mudou = GroupRepository::requestDomainCheck((int) $group['id'], $d, $token);
        return new Resultado('ok', 'Verificação agendada: o servidor confere o registro TXT ' . DomainVerification::txtName($d) . ' = '
            . DomainVerification::txtValue($token) . ' em até 1 hora (o DNS pode levar alguns minutos para propagar).', ['mudou' => $mudou]);
    }

    /** Inclui ou troca o papel. E-mail sem conta ativa: mensagem única (não diz se existe, está inativa ou apagada). */
    public static function setMember(array $admin, array $group, mixed $email, mixed $role): Resultado
    {
        if ($deny = self::guard($admin)) {
            return $deny;
        }
        $role = GrupoValidacao::role($role);
        if ($role === null) {
            return new Resultado('invalido', 'Papel inválido.');
        }
        $user = is_string($email) && trim($email) !== '' ? UserRepository::findByEmail(Email::normalize($email)) : null;
        if (!$user || ($user['status'] ?? '') !== 'active') {
            return new Resultado('invalido', 'Não há conta ativa com este e-mail.');
        }
        $uid = (int) $user['id'];
        $gid = (int) $group['id'];
        // Leitura do papel e gravação na mesma transação (BEGIN IMMEDIATE no SQLite): dois saves simultâneos não
        // deixam DELETE sem INSERT; corrida que ainda bata no UNIQUE (pgsql) vira 'conflito', não 500.
        try {
            $old = Db::transactional(db(), static function (PDO $pdo) use ($gid, $uid, $role): array {
                $old = GroupRepository::activeRole($gid, $uid);
                if ($old === $role) {
                    return [false, $old];
                }
                if ($old === null) {
                    $pdo->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?')->execute([$gid, $uid]);
                    $pdo->prepare('INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)')->execute([$gid, $uid, $role]);
                } else {
                    $pdo->prepare('UPDATE group_members SET role = ? WHERE group_id = ? AND user_id = ?')->execute([$role, $gid, $uid]);
                }
                return [true, $old];
            }, true);
        } catch (PDOException $e) {
            if (!GroupRepository::isUniqueViolation($e)) {
                throw $e;
            }
            return new Resultado('conflito', 'O membro foi alterado ao mesmo tempo. Tente de novo.');
        }
        [$mudou, $old] = $old;
        if (!$mudou) {
            return new Resultado('ok', null, ['mudou' => false]);
        }
        return new Resultado('ok', null, ['mudou' => true], [
            [ActivityLog::GROUP_MEMBER_SET, ['grupo' => $group['slug'], 'user_id' => $uid, 'papel' => $role], $old === null ? null : ['papel' => $old], ['papel' => $role]],
        ]);
    }

    public static function removeMember(array $admin, array $group, mixed $userId): Resultado
    {
        if ($deny = self::guard($admin)) {
            return $deny;
        }
        $uid = GrupoValidacao::userId($userId);
        if ($uid === null) {
            return new Resultado('invalido', 'Membro inválido.');
        }
        $old = GroupRepository::storedRole((int) $group['id'], $uid);
        if ($old === null) {
            return new Resultado('ok', null, ['mudou' => false]);
        }
        $st = db()->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?');
        $st->execute([(int) $group['id'], $uid]);
        // Corrida: outra remoção apagou entre a leitura e o DELETE -> sem efeito e sem log duplicado.
        if ($st->rowCount() !== 1) {
            return new Resultado('ok', null, ['mudou' => false]);
        }
        return new Resultado('ok', null, ['mudou' => true], [
            [ActivityLog::GROUP_MEMBER_REMOVE, ['grupo' => $group['slug'], 'user_id' => $uid], ['papel' => $old], null],
        ]);
    }
}

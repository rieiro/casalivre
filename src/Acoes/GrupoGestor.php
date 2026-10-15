<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';
require_once __DIR__ . '/GrupoValidacao.php';
require_once dirname(__DIR__) . '/GroupRepository.php';

/**
 * Ações do gestor do grupo (papel 'gestor', conta ativa): convida imóvel, cancela convite, tira imóvel do grupo.
 * Sem o papel (inclusive membro e gestor de outro grupo) -> 'nao_encontrado'.
 */
final class GrupoGestor
{
    /** Mensagem única do convite (imóvel inexistente, inativo ou já convidado: mesma resposta). */
    public const INVITE_SENT = 'Convite registrado. Se o imóvel existir e estiver ativo, o dono verá o convite.';

    /** Guarda do ator: gestor ativo do grupo, ou a resposta de recusa. */
    private static function guard(array $actor, array $group): ?Resultado
    {
        return GroupRepository::activeRole((int) $group['id'], (int) $actor['id']) === 'gestor'
            ? null
            : new Resultado('nao_encontrado', 'Não encontrado.');
    }

    /** Imóvel ativo pelo código, ou null (código mal formado também). */
    private static function property(mixed $ref): ?array
    {
        $ref = GrupoValidacao::propertyRef($ref);
        return $ref === null ? null : GroupRepository::activePropertyByRef($ref);
    }

    /** Convite. Imóvel inexistente/inativo/já no grupo/já convidado: mesma resposta, sem gravar nem logar. */
    public static function invite(array $actor, array $group, mixed $ref): Resultado
    {
        if ($deny = self::guard($actor, $group)) {
            return $deny;
        }
        if (GrupoValidacao::propertyRef($ref) === null) {
            return new Resultado('invalido', 'Código do imóvel inválido: são os 16 caracteres do endereço do anúncio (/imoveis/…).');
        }
        $p = self::property($ref);
        $sent = new Resultado('ok', self::INVITE_SENT, ['mudou' => false]);
        $gid = (int) $group['id'];
        if ($p === null || (int) ($p['group_id'] ?? 0) === $gid || GroupRepository::hasInvite($gid, (int) $p['id'])) {
            return $sent;
        }
        try {
            db()->prepare('INSERT INTO group_property_invites (group_id, property_id, invited_by) VALUES (?, ?, ?)')->execute([$gid, (int) $p['id'], (int) $actor['id']]);
        } catch (PDOException $e) {
            if (!GroupRepository::isUniqueViolation($e)) {
                throw $e;
            }
            return $sent;
        }
        return new Resultado('ok', self::INVITE_SENT, ['mudou' => true], [[ActivityLog::GROUP_INVITE, ['grupo' => $group['slug'], 'imovel_id' => (int) $p['id']]]]);
    }

    public static function cancelInvite(array $actor, array $group, mixed $ref): Resultado
    {
        if ($deny = self::guard($actor, $group)) {
            return $deny;
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

    /** Tira do grupo um imóvel que está nele (sem aceite do dono: sair só reduz o vínculo). */
    public static function removeProperty(array $actor, array $group, mixed $ref): Resultado
    {
        if ($deny = self::guard($actor, $group)) {
            return $deny;
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
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';
require_once __DIR__ . '/GrupoValidacao.php';
require_once dirname(__DIR__) . '/GroupRepository.php';

/**
 * Ações do dono do imóvel (conta ativa, imóvel ativo dele): aceita ou recusa convite, tira o imóvel do grupo.
 * O vínculo properties.group_id só nasce no aceite. Imóvel alheio -> 'nao_encontrado'.
 */
final class GrupoDono
{
    /** Guarda do ator: dono ativo do imóvel ativo (o adaptador já resolveu por findByUserOrFail; rechecado aqui). */
    private static function guard(array $owner, array $property): ?Resultado
    {
        return (int) $property['user_id'] === (int) $owner['id'] && ($property['status'] ?? 'active') === 'active' && ($owner['status'] ?? '') === 'active'
            ? null
            : new Resultado('nao_encontrado', 'Não encontrado.');
    }

    private static function group(mixed $slug): ?array
    {
        $slug = GrupoValidacao::slug($slug);
        return $slug === null ? null : GroupRepository::findBySlug($slug);
    }

    /** Aceite: precisa de convite do grupo; imóvel em outro grupo -> conflito (sair antes). */
    public static function accept(array $owner, array $property, mixed $slug): Resultado
    {
        if ($deny = self::guard($owner, $property)) {
            return $deny;
        }
        $group = self::group($slug);
        $none = new Resultado('invalido', 'Convite não encontrado.');
        if ($group === null) {
            return $none;
        }
        return Db::transactional(db(), static function (PDO $pdo) use ($group, $property, $none): Resultado {
            if (!GroupRepository::hasInvite((int) $group['id'], (int) $property['id'])) {
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
        if ($deny = self::guard($owner, $property)) {
            return $deny;
        }
        $group = self::group($slug);
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
        if ($deny = self::guard($owner, $property)) {
            return $deny;
        }
        $gid = (int) ($property['group_id'] ?? 0);
        if ($gid === 0) {
            return new Resultado('ok', null, ['mudou' => false]);
        }
        $slug = GroupRepository::slugById($gid);
        $st = db()->prepare('UPDATE properties SET group_id = NULL WHERE id = ? AND group_id = ?');
        $st->execute([(int) $property['id'], $gid]);
        if ($st->rowCount() !== 1) {
            return new Resultado('ok', null, ['mudou' => false]);
        }
        return new Resultado('ok', null, ['mudou' => true], [[ActivityLog::GROUP_PROPERTY_REMOVE, ['grupo' => $slug, 'imovel_id' => (int) $property['id'], 'por' => 'dono'], ['grupo' => $slug], null]]);
    }
}

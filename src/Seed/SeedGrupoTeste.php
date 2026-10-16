<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Db.php';
require_once dirname(__DIR__) . '/AppEnv.php';
require_once dirname(__DIR__) . '/GroupRepository.php';

/**
 * Seed opcional SÓ de desenvolvimento: grupo de exemplo `teste` (tema contraste) para a vitrine /g/teste/ funcionar
 * logo depois do setup.
 *  - Roda só com APP_ENV=dev ou local (AppEnv, .env incluído); qualquer outro valor (ou ausente) -> recusa, sem tocar
 *    no banco.
 *  - Não torna ninguém admin (isso continua só pelo scripts/set-admin.php). Se já existe admin ativo, o primeiro
 *    (menor id) vira gestor do grupo; sem admin, o grupo nasce sem gestor e sai um aviso.
 *  - 3 imóveis ativos dos donos de seed (@casalivre.test, conta ativa, scripts/seed.php) já aceitos no grupo
 *    (properties.group_id). Imóvel de outro grupo não é tocado.
 *  - Idempotente: rodar de novo não duplica grupo, gestor nem imóveis (completa até 3 se faltar).
 */
final class SeedGrupoTeste
{
    public const SLUG = 'teste';
    public const NOME = 'Grupo Teste';
    public const TEMA = 'contraste';
    public const IMOVEIS = 3;

    /** @return array{ok:bool, linhas:list<string>} */
    public static function run(): array
    {
        if (!AppEnv::isDevWithEnv()) {
            return ['ok' => false, 'linhas' => ['ERRO: seed do grupo teste só roda com APP_ENV=dev ou local. Nada foi feito.']];
        }
        $pdo = db();
        $linhas = [];
        $g = GroupRepository::findBySlug(self::SLUG);
        if ($g === null) {
            $pdo->prepare('INSERT INTO groups (slug, name, theme) VALUES (?, ?, ?)')->execute([self::SLUG, self::NOME, self::TEMA]);
            $g = GroupRepository::findBySlug(self::SLUG);
            $linhas[] = 'grupo /g/' . self::SLUG . '/ criado (tema ' . self::TEMA . ')';
        } else {
            $linhas[] = 'grupo /g/' . self::SLUG . '/ já existe';
        }
        $gid = (int) $g['id'];

        $admin = $pdo->query("SELECT id, email FROM users WHERE is_admin = 1 AND status = 'active' ORDER BY id LIMIT 1")->fetch();
        if (!$admin) {
            $linhas[] = 'AVISO: nenhum admin ativo; grupo sem gestor. Torne uma conta admin com php scripts/set-admin.php <email> e rode o seed de novo.';
        } else {
            $uid = (int) $admin['id'];
            $role = GroupRepository::storedRole($gid, $uid);
            if ($role === 'gestor') {
                $linhas[] = 'admin ' . $admin['email'] . ' já é gestor';
            } else {
                $pdo->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?')->execute([$gid, $uid]);
                $pdo->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'gestor')")->execute([$gid, $uid]);
                $linhas[] = 'admin ' . $admin['email'] . ' agora é gestor';
            }
        }

        $st = $pdo->prepare('SELECT COUNT(*) FROM properties WHERE group_id = ? AND ' . GroupRepository::activePropertyStrict());
        $st->execute([$gid]);
        $tem = (int) $st->fetchColumn();
        $falta = max(0, self::IMOVEIS - $tem);
        if ($falta > 0) {
            $st = $pdo->prepare('SELECT p.id FROM properties p JOIN users u ON u.id = p.user_id WHERE p.group_id IS NULL AND '
                . GroupRepository::activePropertyStrict('p') . ' AND ' . GroupRepository::activeUser('u')
                . " AND lower(u.email) LIKE '%@casalivre.test' ORDER BY p.id LIMIT " . $falta);
            $st->execute();
            $upd = $pdo->prepare('UPDATE properties SET group_id = ? WHERE id = ? AND group_id IS NULL');
            $del = $pdo->prepare('DELETE FROM group_property_invites WHERE group_id = ? AND property_id = ?');
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                $upd->execute([$gid, (int) $pid]);
                $del->execute([$gid, (int) $pid]);
                $tem += $upd->rowCount();
            }
        }
        $linhas[] = "imóveis ativos no grupo: $tem de " . self::IMOVEIS;
        if ($tem < self::IMOVEIS) {
            $linhas[] = 'AVISO: faltam imóveis de seed livres; rode bash scripts/seed-users.sh e o seed de novo.';
        }
        return ['ok' => true, 'linhas' => $linhas];
    }
}

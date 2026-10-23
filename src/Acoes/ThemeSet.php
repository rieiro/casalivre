<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';

/**
 * Troca do tema da plataforma pelo admin (web POST /admin/tema). O adaptador já checou admin e CSRF.
 * - tema fora de $permitidos (lista do manifesto) ou não-string -> 'invalido', nada gravado, sem log;
 * - tema igual ao atual -> 'ok' sem gravar e sem log;
 * - senão grava settings.theme (anterior lido na mesma transação), atualiza o cache e devolve THEME_CHANGE
 *   (params tema/tema_anterior e anterior/atual {tema}); sem linha ou troca concorrente -> 'conflito', sem log.
 * Só existe o canal web; por isso não recebe Canal (usado só onde web e API divergem).
 */
final class ThemeSet
{
    /** @param list<string> $permitidos */
    public static function run(mixed $tema, string $atual, array $permitidos): Resultado
    {
        if (!is_string($tema) || !in_array($tema, $permitidos, true)) {
            return new Resultado('invalido', 'Tema inválido.');
        }
        if ($tema === $atual) {
            return new Resultado('ok', null, ['tema' => $tema, 'mudou' => false]);
        }
        // Leitura, comparação e troca na mesma transação: SELECT ... FOR UPDATE e UPDATE condicionado ao valor lido.
        // Dentro da transação:
        // - sem a linha settings.theme ou rowCount !== 1 -> 'conflito' (409), sem log;
        // - valor gravado já igual ao pedido (outro save chegou antes) -> 'ok' sem mudança, sem log.
        // Banco ocupado (lock, serialização ou deadlock) também vira 'conflito', não 500.
        try {
            $estado = Db::transactional(db(), static function (PDO $pdo) use ($tema): array {
                $sel = $pdo->prepare('SELECT value FROM settings WHERE skey = ? FOR UPDATE');
                $sel->execute([Theme::SETTING]);
                $old = $sel->fetchColumn();
                if ($old === false) {
                    return ['falhou', null];
                }
                if ((string) $old === $tema) {
                    return ['igual', $tema];
                }
                $upd = $pdo->prepare('UPDATE settings SET value = ? WHERE skey = ? AND value = ?');
                $upd->execute([$tema, Theme::SETTING, (string) $old]);
                return $upd->rowCount() === 1 ? ['mudou', (string) $old] : ['falhou', null];
            });
        } catch (PDOException $e) {
            if (!self::ocupado($e)) {
                throw $e;
            }
            $estado = ['falhou', null];
        }
        [$situacao, $anterior] = $estado;
        if ($situacao === 'falhou') {
            return new Resultado('conflito', 'Não foi possível salvar o tema. Tente de novo.');
        }
        if ($situacao === 'igual') {
            return new Resultado('ok', null, ['tema' => $tema, 'mudou' => false]);
        }
        Settings::refresh();
        return new Resultado('ok', null, ['tema' => $tema, 'mudou' => true], [
            [ActivityLog::THEME_CHANGE, ['tema' => $tema, 'tema_anterior' => $anterior], ['tema' => $anterior], ['tema' => $tema]],
        ]);
    }

    /** Lock/ocupado: 40001 (serialização), 40P01 (deadlock), 55P03 (lock). */
    private static function ocupado(PDOException $e): bool
    {
        return in_array(Db::sqlState($e), ['40001', '40P01', '55P03'], true);
    }
}

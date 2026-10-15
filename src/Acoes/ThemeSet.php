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
        // Anterior lido e trocado na mesma transação: SELECT (FOR UPDATE no pgsql) + UPDATE condicionado ao valor
        // lido (compare-and-set, vale também no SQLite). Só há log com rowCount === 1: sem a linha settings.theme ou
        // com troca concorrente, nada é registrado e a ação devolve 'conflito'.
        $anterior = Db::transactional(db(), static function (PDO $pdo) use ($tema): ?string {
            $lock = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? ' FOR UPDATE' : '';
            $sel = $pdo->prepare('SELECT value FROM settings WHERE skey = ?' . $lock);
            $sel->execute([Theme::SETTING]);
            $old = $sel->fetchColumn();
            if ($old === false) {
                return null;
            }
            $upd = $pdo->prepare('UPDATE settings SET value = ? WHERE skey = ? AND value = ?');
            $upd->execute([$tema, Theme::SETTING, (string) $old]);
            return $upd->rowCount() === 1 ? (string) $old : null;
        });
        if ($anterior === null) {
            return new Resultado('conflito', 'Não foi possível salvar o tema. Tente de novo.');
        }
        Settings::refresh();
        return new Resultado('ok', null, ['tema' => $tema, 'mudou' => true], [
            [ActivityLog::THEME_CHANGE, ['tema' => $tema, 'tema_anterior' => $anterior], ['tema' => $anterior], ['tema' => $tema]],
        ]);
    }
}

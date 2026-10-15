<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';

/**
 * Troca do tema da plataforma pelo admin (web POST /admin/tema). O adaptador já checou admin e CSRF.
 * - tema fora de $permitidos (lista do manifesto) ou não-string -> 'invalido', nada gravado, sem log;
 * - tema igual ao atual -> 'ok' sem gravar e sem log;
 * - senão grava settings.theme, atualiza o cache de settings e devolve THEME_CHANGE (tema, tema_anterior).
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
        db()->prepare('UPDATE settings SET value = ? WHERE skey = ?')->execute([$tema, Theme::SETTING]);
        Settings::refresh();
        return new Resultado('ok', null, ['tema' => $tema, 'mudou' => true], [
            [ActivityLog::THEME_CHANGE, ['tema' => $tema, 'tema_anterior' => $atual]],
        ]);
    }
}

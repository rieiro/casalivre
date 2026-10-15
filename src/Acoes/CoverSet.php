<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';

/**
 * Definir a foto de capa (web POST /imoveis/{hash}/fotos/capa e /fotos/{id}/capa; API POST .../fotos/capa).
 * Valida aqui, igual nos dois canais: exatamente uma foto, e do próprio imóvel. Senão 'invalido' com
 * erros = ['foto_id' => mensagem] e nada gravado (web: aviso na tela de edição; API: 422).
 * PHOTO_COVER só quando makePrimary muda algo (a foto já era a capa: ok sem log).
 */
final class CoverSet
{
    public const ERRO = 'Marque exatamente uma foto deste imóvel para ser a capa.';

    /** @param list<int> $photoIds ids marcados (web) ou [foto_id] (API) */
    public static function run(int $propertyId, array $photoIds): Resultado
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $photoIds), static fn (int $id) => $id > 0)));
        $own = array_map('intval', array_column(PhotoRepository::list($propertyId), 'id'));
        if (count($ids) !== 1 || !in_array($ids[0], $own, true)) {
            return new Resultado('invalido', self::ERRO, ['erros' => ['foto_id' => self::ERRO]]);
        }
        $changed = PhotoRepository::makePrimary($propertyId, $ids[0]);
        $events = $changed !== []
            ? [[ActivityLog::PHOTO_COVER, ['imovel_id' => $propertyId], array_column($changed, 'old'), array_column($changed, 'new')]]
            : [];
        return new Resultado('ok', null, [], $events);
    }
}

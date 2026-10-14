<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';

/**
 * Definir a foto de capa (web POST /imoveis/{hash}/fotos/capa e /fotos/{id}/capa; API POST .../fotos/capa).
 * PHOTO_COVER só quando makePrimary muda algo (foto de outro imóvel: nada muda, nada é gravado).
 * A validação da entrada fica no adaptador e difere por canal (SNAPSHOT, ver char_capa_avaliacao_test.php):
 * a web ignora em silêncio 0 ou 2+ fotos marcadas (302); a API exige foto_id >= 1 (422).
 */
final class CoverSet
{
    public static function run(int $propertyId, int $photoId): Resultado
    {
        $changed = PhotoRepository::makePrimary($propertyId, $photoId);
        $events = $changed !== []
            ? [[ActivityLog::PHOTO_COVER, ['imovel_id' => $propertyId], array_column($changed, 'old'), array_column($changed, 'new')]]
            : [];
        return new Resultado('ok', null, [], $events);
    }
}

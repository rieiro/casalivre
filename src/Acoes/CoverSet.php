<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';
require_once __DIR__ . '/InteiroBruto.php';

/**
 * Definir a foto de capa (web POST /imoveis/{hash}/fotos/capa e /fotos/{id}/capa; API POST .../fotos/capa).
 * Valida aqui, igual nos dois canais: exatamente uma foto, e do próprio imóvel. Senão 'invalido' com
 * erros = ['foto_id' => mensagem] e nada gravado (web: aviso na tela de edição; API: 422).
 * PHOTO_COVER só quando makePrimary muda algo (a foto já era a capa: ok sem log).
 */
final class CoverSet
{
    /** Chave da mensagem de erro (pt-BR/en/es em database/messages.php). */
    public const ERRO = 'photo.cover_one';

    /**
     * @param array<mixed> $photoIds valores BRUTOS: ids marcados (web) ou [foto_id] (API). Cada um precisa ser int
     * ou string só com dígitos (InteiroBruto), validado antes de qualquer cast: 1.5, "5abc", "1e1", " 5", true ou
     * array recusam (nada gravado), em vez de virar um id truncado.
     */
    public static function run(int $propertyId, array $photoIds): Resultado
    {
        $ids = [];
        foreach ($photoIds as $raw) {
            $id = InteiroBruto::parse($raw);
            if ($id === null || $id < 1) {
                return self::invalido();
            }
            $ids[$id] = $id;
        }
        $ids = array_values($ids);
        if (count($ids) !== 1 || !PhotoRepository::belongsTo($propertyId, $ids[0])) {
            return self::invalido();
        }
        $changed = PhotoRepository::makePrimary($propertyId, $ids[0]);
        $events = $changed !== []
            ? [[ActivityLog::PHOTO_COVER, ['imovel_id' => $propertyId], array_column($changed, 'old'), array_column($changed, 'new')]]
            : [];
        return new Resultado('ok', null, [], $events);
    }

    private static function invalido(): Resultado
    {
        return new Resultado('invalido', t(self::ERRO), ['erros' => ['foto_id' => t(self::ERRO)]]);
    }
}

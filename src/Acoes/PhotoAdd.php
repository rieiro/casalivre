<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';

/**
 * Upload de fotos (web POST /imoveis/{hash}/fotos e API POST /api/casas/{hash}/fotos).
 * Conta as enviadas, grava pelo PhotoStorage::storeUploads e devolve PHOTO_ADD com enviadas e salvas, igual nos
 * dois canais. Desfechos (tipo): ok (todas salvas) | parcial (0 < salvas < enviadas) | vazio (nenhum arquivo
 * chegou) | nenhuma (chegaram, mas nenhuma foi salva). Em vazio/nenhuma, dados['erros'] = ['fotos' => mensagem]:
 * a web mostra o erro na tela de edição e a API responde 422 com os mesmos erros. Parcial é aviso (web) e 201 (API).
 */
final class PhotoAdd
{
    public static function run(int $userId, int $propertyId, array $files): Resultado
    {
        $created = [];
        $saved = PhotoStorage::storeUploads($userId, $propertyId, $files, $created);
        $sent = is_array($files['name'] ?? null) ? count(array_filter($files['name'], static fn ($name) => $name !== '')) : 0;
        $tipo = 'ok';
        $message = null;
        if ($sent === 0) {
            $tipo = 'vazio';
            $message = t('photo.none_arrived');
        } elseif ($saved < $sent) {
            $tipo = $saved === 0 ? 'nenhuma' : 'parcial';
            $limit = Settings::int('max_photos_per_property') ?: 40;
            $message = strtr(t('photo.partial'), ['{salvas}' => (string) $saved, '{enviadas}' => (string) $sent, '{limite}' => (string) $limit]);
        }
        $dados = ['enviadas' => $sent, 'salvas' => $saved];
        if ($saved === 0) {
            $dados['erros'] = ['fotos' => (string) $message];
        }
        return new Resultado($tipo, $message, $dados, [
            [ActivityLog::PHOTO_ADD, ['imovel_id' => $propertyId, 'enviadas' => $sent, 'salvas' => $saved], null, ['fotos' => $created]],
        ]);
    }
}

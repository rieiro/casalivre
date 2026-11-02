<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';
require_once __DIR__ . '/../PhotoStorage.php';

/**
 * Upload de fotos (web POST /imoveis/{hash}/fotos e API POST /api/casas/{hash}/fotos).
 * Conta as enviadas, grava pelo PhotoStorage::storeUploads e devolve PHOTO_ADD com enviadas e salvas, igual nos
 * dois canais. Desfechos (tipo): ok (todas salvas) | parcial (0 < salvas < enviadas) | vazio (nenhum arquivo
 * chegou) | nenhuma (chegaram, mas nenhuma foi salva). Em vazio/nenhuma, dados['erros'] = ['fotos' => mensagem]:
 * a web mostra o erro na tela de edição e a API responde 422 com os mesmos erros. Parcial é aviso (web) e 201 (API).
 * Cada arquivo recusado entra em dados['rejeitadas'] com o motivo (wrong_type, too_large, over_limit).
 */
final class PhotoAdd
{
    public static function run(int $userId, int $propertyId, array $files): Resultado
    {
        $created = [];
        $stored = PhotoStorage::storeUploads($userId, $propertyId, $files, $created);
        $saved = $stored['saved'];
        $rejected = $stored['rejected'];
        $sent = is_array($files['name'] ?? null) ? count(array_filter($files['name'], static fn ($name) => $name !== '')) : 0;
        $lines = [];
        foreach ($rejected as $row) {
            $key = match ($row['reason']) {
                PhotoFail::TOO_LARGE => 'photo.reject_too_large',
                PhotoFail::OVER_LIMIT => 'photo.reject_over_limit',
                PhotoFail::WRONG_TYPE => 'photo.reject_wrong_type',
            };
            $lines[] = strtr(t($key), ['{nome}' => $row['name']]);
        }
        $tipo = 'ok';
        $message = null;
        if ($sent === 0) {
            $tipo = 'vazio';
            $message = t('photo.none_arrived');
        } elseif ($saved === 0) {
            $tipo = 'nenhuma';
            $message = $lines === [] ? t('photo.none_arrived') : implode("\n", $lines);
        } elseif ($lines !== []) {
            $tipo = 'parcial';
            $limit = Settings::int('max_photos_per_property') ?: 40;
            $message = strtr(t('photo.partial'), ['{salvas}' => (string) $saved, '{enviadas}' => (string) $sent, '{limite}' => (string) $limit])
                . "\n" . implode("\n", $lines);
        }
        $dados = ['enviadas' => $sent, 'salvas' => $saved, 'rejeitadas' => $rejected];
        if ($saved === 0) {
            $dados['erros'] = ['fotos' => (string) $message];
        }
        return new Resultado($tipo, $message, $dados, [
            [ActivityLog::PHOTO_ADD, ['imovel_id' => $propertyId, 'enviadas' => $sent, 'salvas' => $saved], null, ['fotos' => $created]],
        ]);
    }
}

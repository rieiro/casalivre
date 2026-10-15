<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';

/**
 * Upload de fotos (web POST /imoveis/{hash}/fotos e API POST /api/casas/{hash}/fotos).
 * Conta as enviadas, grava pelo PhotoStorage::storeUploads e devolve PHOTO_ADD com enviadas e salvas, igual nos
 * dois canais. Desfechos (tipo): ok (todas salvas) | parcial (salvas < enviadas) | vazio (nenhum arquivo chegou).
 * A mensagem (parcial/vazio) é o aviso que a web mostra na tela de edição; a API devolve os números.
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
            $message = 'Nenhuma foto chegou. O envio é maior do que o limite do sistema.';
        } elseif ($saved < $sent) {
            $tipo = 'parcial';
            $limit = Settings::int('max_photos_per_property') ?: 40;
            $message = "Salvas $saved de $sent fotos. Cada imóvel pode ter até $limit fotos, e cada arquivo até 16 MB (jpg, png ou webp).";
        }
        return new Resultado($tipo, $message, ['enviadas' => $sent, 'salvas' => $saved], [
            [ActivityLog::PHOTO_ADD, ['imovel_id' => $propertyId, 'enviadas' => $sent, 'salvas' => $saved], null, ['fotos' => $created]],
        ]);
    }
}

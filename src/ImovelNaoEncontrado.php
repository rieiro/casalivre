<?php

declare(strict_types=1);

/**
 * Imóvel não encontrado na resolução das rotas web (HouseRepository::*OrFail). O repositório só lança;
 * quem renderiza o 404 é a camada web (public/index.php, em volta do dispatch).
 * - publico: hash público inexistente ou imóvel não ativo (página "Imóvel não encontrado");
 * - dono: o imóvel não é do usuário logado (texto puro "Imóvel não encontrado.").
 */
final class ImovelNaoEncontrado extends RuntimeException
{
    public const PUBLICO = 'publico';
    public const DONO = 'dono';

    private function __construct(public readonly string $motivo)
    {
        parent::__construct('Imóvel não encontrado.');
    }

    public static function publico(): self
    {
        return new self(self::PUBLICO);
    }

    public static function dono(): self
    {
        return new self(self::DONO);
    }
}

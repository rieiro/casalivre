<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';
require_once __DIR__ . '/InteiroBruto.php';

/**
 * Avaliação de imóvel (web POST /imoveis/{hash}/avaliar e API POST /api/imoveis/{hash}/avaliar).
 * Desfechos: ok (review gravada + REVIEW) | invalido (nota fora de 1..10; nada gravado).
 * Autor vazio vira 'Hóspede'. A existência/status do imóvel é checada no adaptador (404 em cada formato).
 * Nota inválida: dados['erros'] = ['score' => mensagem]; a web mostra o erro (422, tela de mensagem) e a API
 * responde 422 com os mesmos erros.
 * $score chega BRUTO (POST/JSON) e é validado antes de qualquer cast (InteiroBruto): só int ou string ^\d+$;
 * 1.5, 10.5, "5abc", "1e1", " 5", true ou array recusam, em vez de virar uma nota truncada.
 */
final class ReviewAdd
{
    public const ERRO = 'review.score_range';

    public static function run(int $propertyId, mixed $score, string $author, string $comment): Resultado
    {
        $score = InteiroBruto::parse($score);
        if ($score === null || $score < 1 || $score > 10) {
            return new Resultado('invalido', t(self::ERRO), ['erros' => ['score' => t(self::ERRO)]]);
        }
        $author = $author === '' ? 'Hóspede' : $author;
        HouseRepository::addReview($propertyId, $author, $score, $comment);
        return new Resultado('ok', null, [], [[ActivityLog::REVIEW, ['imovel_id' => $propertyId, 'nota' => $score, 'autor' => $author]]]);
    }
}

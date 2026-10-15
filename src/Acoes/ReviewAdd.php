<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';

/**
 * Avaliação de imóvel (web POST /imoveis/{hash}/avaliar e API POST /api/imoveis/{hash}/avaliar).
 * Desfechos: ok (review gravada + REVIEW) | invalido (nota fora de 1..10; nada gravado).
 * Autor vazio vira 'Hóspede'. A existência/status do imóvel é checada no adaptador (404 em cada formato).
 * Nota inválida: dados['erros'] = ['score' => mensagem]; a web mostra o erro (422, tela de mensagem) e a API
 * responde 422 com os mesmos erros.
 */
final class ReviewAdd
{
    public static function run(int $propertyId, int $score, string $author, string $comment): Resultado
    {
        if ($score < 1 || $score > 10) {
            return new Resultado('invalido', 'Nota deve ser entre 1 e 10.', ['erros' => ['score' => 'Nota deve ser entre 1 e 10.']]);
        }
        $author = $author === '' ? 'Hóspede' : $author;
        HouseRepository::addReview($propertyId, $author, $score, $comment);
        return new Resultado('ok', null, [], [[ActivityLog::REVIEW, ['imovel_id' => $propertyId, 'nota' => $score, 'autor' => $author]]]);
    }
}

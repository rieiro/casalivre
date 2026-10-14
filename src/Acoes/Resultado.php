<?php

declare(strict_types=1);

/**
 * Resultado neutro de uma ação compartilhada (src/Acoes/*): a regra de negócio fica na ação; web (index.php)
 * e API (Api.php) só traduzem para view/redirect ou JSON.
 *
 * - tipo: desfecho da ação ('ok', 'invalido', 'conflito', 'nao_encontrado', ...), definido por ação;
 * - mensagem: texto para o usuário (já traduzido), quando houver;
 * - dados: carga para o adaptador (usuário, feeds, etc.);
 * - eventos: entradas do activity_log, na ordem em que eram gravadas antes ([código, params, anterior, atual]).
 *
 * O adaptador chama registrar() no mesmo ponto em que o activity() era chamado antes (logo após a ação).
 */
final class Resultado
{
    /** @param list<array{0:int,1?:array,2?:mixed,3?:mixed}> $eventos */
    public function __construct(
        public readonly string $tipo,
        public readonly ?string $mensagem = null,
        public readonly array $dados = [],
        public readonly array $eventos = [],
    ) {
    }

    public function registrar(): void
    {
        foreach ($this->eventos as $e) {
            activity($e[0], $e[1] ?? [], $e[2] ?? null, $e[3] ?? null);
        }
    }
}

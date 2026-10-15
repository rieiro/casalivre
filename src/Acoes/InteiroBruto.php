<?php

declare(strict_types=1);

/**
 * Valida o valor BRUTO de um campo inteiro antes de qualquer cast (o (int) truncaria 1.5, "5abc", "1e1", true...).
 * Aceita só int >= 0 ou string só com dígitos (^\d+$, sem espaços, sinal, ponto ou expoente). Senão null.
 */
final class InteiroBruto
{
    public static function parse(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v >= 0 ? $v : null;
        }
        // Até 9 dígitos: cabe em int sem saturar (o (int) de um número enorme viraria PHP_INT_MAX).
        if (is_string($v) && preg_match('/^\d{1,9}$/D', $v) === 1) {
            return (int) $v;
        }
        return null;
    }
}

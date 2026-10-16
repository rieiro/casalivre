<?php

declare(strict_types=1);

/**
 * Valida o valor BRUTO de um campo de texto antes de qualquer (string): aceita só string (ou ausente/null).
 * Array, número ou bool -> inválido (o (string) daria "Array" e o aviso "Array to string conversion").
 * Par de InteiroBruto para campos de texto.
 */
final class TextoBruto
{
    public static function ok(mixed $v): bool
    {
        return $v === null || is_string($v);
    }
}

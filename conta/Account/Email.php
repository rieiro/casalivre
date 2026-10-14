<?php

declare(strict_types=1);

/** Forma canônica de um e-mail (comparação, fila, banco): sem espaços nas pontas e em minúsculas. */
final class Email
{
    public static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }
}

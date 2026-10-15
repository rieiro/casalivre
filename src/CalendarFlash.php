<?php

declare(strict_types=1);

/**
 * Aviso da tela do calendário para o próximo GET (flash na sessão, lido uma vez por take()).
 * Substitui o ?msg= na URL: o texto não fica no histórico nem nos logs de acesso, e não pode ser forjado por link.
 */
final class CalendarFlash
{
    private const KEY = 'calendar_flash';

    public static function set(string $message, string $tone = 'ok'): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && $message !== '') {
            $_SESSION[self::KEY] = ['message' => $message, 'tone' => $tone === 'error' ? 'error' : 'ok'];
        }
    }

    /** @return array{message: string, tone: string}|null o aviso pendente (e o apaga), ou null. */
    public static function take(): ?array
    {
        $flash = $_SESSION[self::KEY] ?? null;
        unset($_SESSION[self::KEY]);
        if (!is_array($flash) || (string) ($flash['message'] ?? '') === '') {
            return null;
        }
        return ['message' => (string) $flash['message'], 'tone' => ($flash['tone'] ?? '') === 'error' ? 'error' : 'ok'];
    }
}

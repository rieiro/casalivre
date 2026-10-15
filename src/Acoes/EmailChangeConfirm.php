<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';

/**
 * Confirmação da troca de e-mail pelo link enviado ao endereço novo (web POST /conta/email/confirmar,
 * API POST /api/auth/email/confirmar). Token de uso único (só o sha256 no banco), com validade; a troca e o
 * session_version+1 (encerra as outras sessões) saem num só UPDATE. Desfechos: invalido | ok.
 * Não exige login (o token prova o acesso ao endereço novo; a senha já foi conferida no pedido). A sessão
 * atual, se for da mesma conta, também cai e pede login de novo.
 */
final class EmailChangeConfirm
{
    public static function run(mixed $token): Resultado
    {
        if (!is_string($token) || $token === '') {
            return new Resultado('invalido', t('auth.verify_bad'));
        }
        $r = AccountFlow::confirmEmailChange($token);
        if (!$r['ok']) {
            return new Resultado('invalido', t('auth.verify_bad'));
        }
        return new Resultado('ok', t('email.confirm_ok'), ['id' => $r['id']], [
            [ActivityLog::EMAIL_CHANGE, ['email' => $r['new']], ['campos_alterados' => ['email']], null],
        ]);
    }
}

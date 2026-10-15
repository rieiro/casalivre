<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';

/**
 * Tentativa de login (web POST /entrar e API POST /api/sessao).
 *
 * Invariantes de segurança (inalterados; revisão da Segurança):
 * - CSRF é verificado pelo adaptador ANTES desta ação (csrf_check na web; handle_api na API).
 * - AccountFlow::checkLogin continua sendo a única checagem de credencial (lockout por conta, hash/tempo,
 *   anti-enumeração ficam lá, intocados).
 * - LoginRateLimiter: hit() em senha errada e em bloqueio recém-atingido (com 'failures'); 429 quando as
 *   falhas por IP chegam a maxAttempts(); clear() só após login ok.
 * - Sessão: AccountFlow::openSession (regenera o id e grava session_version) só no desfecho 'ok', antes de
 *   clear() e do LOGIN_OK, na mesma ordem de antes.
 *
 * Desfechos (tipo): locked | rate_limited | bad | deleted | inactive | pending | ok.
 *
 * Conta apagada/inativa: web e API gravam LOGIN_FAIL com motivo apagado/inactive (antes só a web; Segurança, #44).
 * Esse desfecho só existe com a senha certa, e AccountFlow::checkLogin já fez o mesmo password_verify de uma senha
 * errada (DUMMY_HASH para conta inexistente), então gravar o log não muda o tempo de resposta.
 *
 * Diferenças web/API preservadas de propósito (SNAPSHOT, ver tests/standalone/char_login_conta_test.php):
 * - $ensureActivation: a web chama AccountFlow::ensureActivation em conta pendente (dados['expires']);
 *   a API só responde 403.
 */
final class LoginAttempt
{
    public static function run(string $email, string $password, bool $ensureActivation): Resultado
    {
        $result = AccountFlow::checkLogin($email, $password, Settings::int('login_max_failures'));
        if ($result['status'] === 'locked') {
            if (isset($result['failures'])) {
                LoginRateLimiter::hit();
            }
            $params = ['email' => $email];
            if (isset($result['failures'])) {
                $params['tentativas'] = $result['failures'];
            }
            return new Resultado('locked', t('err.locked'), [], [[ActivityLog::LOGIN_LOCKED, $params]]);
        }
        if ($result['status'] === 'bad') {
            $ipFailures = LoginRateLimiter::hit();
            $events = [[ActivityLog::LOGIN_FAIL, ['email' => $email]]];
            if ($ipFailures >= LoginRateLimiter::maxAttempts()) {
                return new Resultado('rate_limited', LoginRateLimiter::message(), [], $events);
            }
            return new Resultado('bad', t('err.bad_login'), [], $events);
        }
        if ($result['status'] === 'deleted' || $result['status'] === 'inactive') {
            $events = [[ActivityLog::LOGIN_FAIL, ['email' => $email, 'motivo' => $result['status'] === 'deleted' ? 'apagado' : 'inactive']]];
            return new Resultado($result['status'], account_text($result['status']), [], $events);
        }
        if ($result['status'] === 'pending') {
            $data = [];
            if ($ensureActivation) {
                $pending = AccountFlow::ensureActivation($result['user'], Settings::int('email_verify_minutes'));
                $data['expires'] = $pending['expires'];
            }
            return new Resultado('pending', t('err.pending'), $data);
        }
        AccountFlow::openSession((int) $result['user']['id']);
        LoginRateLimiter::clear();
        return new Resultado('ok', null, ['user' => $result['user']], [[ActivityLog::LOGIN_OK, ['email' => $email]]]);
    }
}

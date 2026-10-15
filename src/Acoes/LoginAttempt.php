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
 * Diferenças web/API preservadas de propósito (SNAPSHOT, ver tests/standalone/char_login_conta_test.php):
 * - $logInactive: a web grava LOGIN_FAIL (motivo apagado/inactive) para conta apagada/inativa; a API não grava.
 * - Conta pendente (só com a senha certa; senha errada é 'bad' como qualquer conta): web e API chamam
 *   AccountFlow::ensureActivation (reenvio com limite por conta somado web+API); a web mostra verify_pending
 *   e a API responde 403 err.pending.
 */
final class LoginAttempt
{
    public static function run(string $email, string $password, bool $logInactive): Resultado
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
            $events = $logInactive
                ? [[ActivityLog::LOGIN_FAIL, ['email' => $email, 'motivo' => $result['status'] === 'deleted' ? 'apagado' : 'inactive']]]
                : [];
            return new Resultado($result['status'], account_text($result['status']), [], $events);
        }
        if ($result['status'] === 'pending') {
            $pending = AccountFlow::ensureActivation($result['user'], Settings::int('email_verify_minutes'));
            return new Resultado('pending', t('err.pending'), ['expires' => $pending['expires']]);
        }
        AccountFlow::openSession((int) $result['user']['id']);
        LoginRateLimiter::clear();
        return new Resultado('ok', null, ['user' => $result['user']], [[ActivityLog::LOGIN_OK, ['email' => $email]]]);
    }
}

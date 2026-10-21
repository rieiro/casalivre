<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';
require_once dirname(__DIR__) . '/IpRateLimiter.php';

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
 * Conta pendente (só com a senha certa; senha errada é 'bad' como qualquer conta): web e API reenviam a ativação
 * por AccountFlow::ensureActivation, com o limite por conta somado web+API aplicado aqui (RESEND_MAX em
 * RESEND_WINDOW s). Só conta o reenvio que entrou de fato na mail_queue (o cooldown de 5 min dela não gasta o
 * limite). dados['expires'] vai para a web (verify_pending) e para a API (403 err.pending). Não há mais opção por canal (ver tests/standalone/char_login_conta_test.php).
 */
final class LoginAttempt
{
    public const RESEND_MAX = 3;
    public const RESEND_WINDOW = 3600;
    private const RESEND_BUCKET = 'ativacao-reenvio';

    public static function run(string $email, string $password): Resultado
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
            // Limite por conta (não por IP), para ninguém usar o login para lotar a caixa da vítima.
            $key = 'conta:' . hash('sha256', Email::normalize((string) $result['user']['email']));
            // Conferir o limite, enfileirar e contar sem corrida (IpRateLimiter::reserve: vaga atômica por INCR no Valkey):
            // logins paralelos da mesma conta recebem vagas distintas, então no máximo RESEND_MAX e-mails por janela.
            // Só conta quando o e-mail entrou de fato na fila (cooldown de 5 min ou falha de envio devolvem a vaga).
            // Valkey fora do ar: balde fail-closed (IpRateLimiter::FAIL_CLOSED_BUCKETS), nenhum reenvio.
            $pending = null;
            IpRateLimiter::reserve(
                self::RESEND_BUCKET,
                self::RESEND_MAX,
                self::RESEND_WINDOW,
                $key,
                static function (bool $cabe) use (&$pending, $result): bool {
                    $pending = AccountFlow::ensureActivation(
                        $result['user'],
                        Settings::int('email_verify_minutes'),
                        static fn (): bool => $cabe
                    );
                    return $pending['enfileirado'];
                }
            );
            return new Resultado('pending', t('err.pending'), ['expires' => $pending['expires']]);
        }
        AccountFlow::openSession((int) $result['user']['id']);
        LoginRateLimiter::clear();
        return new Resultado('ok', null, ['user' => $result['user']], [[ActivityLog::LOGIN_OK, ['email' => $email]]]);
    }
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';

/**
 * Atualização dos dados da conta (web POST /conta e API PUT /api/users/eu).
 *
 * Desfechos: invalido (mensagem de validação) | limite (muitas trocas de e-mail; dados.retry em s) |
 * senha_incorreta | email_em_uso | ok.
 * ACCOUNT_UPDATE é gravado sempre que o UPDATE devolve a linha, inclusive sem campo alterado (SNAPSHOT do
 * comportamento atual; valor_atual sai null nesse caso).
 * Troca de e-mail (#47): exige a senha atual (current_password) e NÃO troca na hora. O e-mail novo fica
 * pendente; o link de uso único vai para o endereço novo e um aviso para o atual (AccountFlow::requestEmailChange).
 * A troca só acontece na confirmação (EmailChangeConfirm). Pedidos de troca são limitados por IP e por conta
 * (EMAIL_LIMIT em EMAIL_WINDOW s), contados antes de conferir a senha. A checagem "e-mail em uso" só roda
 * depois da senha certa (antes, qualquer sessão logada consultava).
 * A tradução difere por canal: web re-renderiza (200) ou redireciona; API 422/403/409/429/200.
 */
final class AccountUpdate
{
    public const EMAIL_LIMIT = 5;
    public const EMAIL_WINDOW = 900;

    public static function run(array $user, array $input): Resultado
    {
        $error = user_input_error($input, false);
        if ($error) {
            return new Resultado('invalido', $error);
        }
        $fields = user_fields($input, $user);
        $current = Email::normalize((string) $user['email']);
        $newEmail = $fields['email'];
        $changing = $newEmail !== $current;
        if ($changing) {
            $retry = max(
                IpRateLimiter::hit('/conta/email', self::EMAIL_LIMIT, self::EMAIL_WINDOW),
                IpRateLimiter::hit('/conta/email#conta', self::EMAIL_LIMIT, self::EMAIL_WINDOW, 'user:' . (int) $user['id'])
            );
            if ($retry > 0) {
                return new Resultado('limite', t('err.too_many'), ['retry' => $retry]);
            }
            $password = $input['current_password'] ?? '';
            if (!is_string($password) || $password === '' || !password_verify($password, (string) $user['password_hash'])) {
                return new Resultado('senha_incorreta', t('err.current_password'));
            }
            $other = UserRepository::findByEmail($newEmail);
            if ($other && (int) $other['id'] !== (int) $user['id']) {
                return new Resultado('email_em_uso', t('err.email_taken'));
            }
            // O e-mail da conta só muda na confirmação.
            $fields['email'] = $current;
        }
        $events = [];
        try {
            $changed = UserRepository::update((int) $user['id'], $fields);
            $pair = $changed[0] ?? null;
            if ($pair) {
                $events[] = [ActivityLog::ACCOUNT_UPDATE, ['email' => $fields['email']], $pair['old'], $pair['new']];
            }
        } catch (PDOException) {
            return new Resultado('email_em_uso', t('err.email_taken'));
        }
        if ($changing) {
            AccountFlow::requestEmailChange((int) $user['id'], $current, $newEmail);
            $events[] = [ActivityLog::EMAIL_CHANGE_ASK, ['email' => $current]];
            return new Resultado('ok', t('account.email_pending'), ['email_pendente' => true], $events);
        }
        return new Resultado('ok', null, [], $events);
    }
}

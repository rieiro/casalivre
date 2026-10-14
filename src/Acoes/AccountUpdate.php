<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';

/**
 * Atualização dos dados da conta (web POST /conta e API PUT /api/users/eu).
 *
 * Desfechos: invalido (mensagem de validação) | email_em_uso | ok.
 * ACCOUNT_UPDATE é gravado sempre que o UPDATE devolve a linha, inclusive sem campo alterado (SNAPSHOT do
 * comportamento atual; valor_atual sai null nesse caso).
 * A tradução difere por canal: web re-renderiza (200) ou redireciona para /painel; API 422/409/200.
 */
final class AccountUpdate
{
    public static function run(array $user, array $input): Resultado
    {
        $error = user_input_error($input, false);
        if ($error) {
            return new Resultado('invalido', $error);
        }
        $fields = user_fields($input, $user);
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
        return new Resultado('ok', null, [], $events);
    }
}

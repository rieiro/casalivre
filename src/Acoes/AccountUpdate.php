<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';

/**
 * Atualização dos dados de perfil da conta (web POST /conta e API PUT /api/users/eu).
 *
 * O e-mail não faz parte da conta editável (decisão de produto): não é lido nem gravado aqui. Um campo
 * email na entrada é só um campo desconhecido, ignorado como qualquer outro.
 * Desfechos: invalido (mensagem de validação) | ok.
 * ACCOUNT_UPDATE é gravado sempre que o UPDATE devolve a linha, inclusive sem campo alterado (SNAPSHOT do
 * comportamento atual; valor_atual sai null nesse caso).
 * A tradução difere por canal: web re-renderiza (200) ou redireciona para /painel; API 422/200.
 */
final class AccountUpdate
{
    public static function run(array $user, array $input): Resultado
    {
        // 'required' do perfil tem texto próprio (sem e-mail); err.required continua só no cadastro.
        $code = AccountFlow::profileProblem($input);
        $error = $code === 'required' ? t('err.profile_required') : account_text($code);
        if ($error) {
            return new Resultado('invalido', $error);
        }
        $events = [];
        $changed = UserRepository::update((int) $user['id'], AccountFlow::profileFields($input));
        $pair = $changed[0] ?? null;
        if ($pair) {
            $events[] = [ActivityLog::ACCOUNT_UPDATE, ['email' => $user['email']], $pair['old'], $pair['new']];
        }
        return new Resultado('ok', null, [], $events);
    }
}

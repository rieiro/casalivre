<?php
// Helpers dos testes de caracterização (char_*_test.php). Só leitura do activity_log e utilidades
// de formulário web; não alteram código de produção.

/**
 * O log de atividades está no PostgreSQL do banco temporário (audit.activity_log + audit.audit_log). Mantido por
 * compatibilidade dos testes: devolve um rótulo; c_mark/c_logs leem o DB_DSN atual (t_log_mark/t_log_rows).
 */
function c_activity_file(): string
{
    return 'pg';
}

/** Marca do log (created_at da última linha); c_logs($act, $mark) devolve só o que veio depois. */
function c_mark(string $file): string
{
    return t_log_mark();
}

/** Linhas dos logs após $since: [['code'=>int,'params'=>array,'anterior'=>mixed,'atual'=>mixed, ...], ...]. */
function c_logs(string $file, string $since): array
{
    return t_log_rows($since);
}

/** Só os códigos, na ordem. */
function c_codes(string $file, string $since): array
{
    return array_map(static fn (array $r): int => $r['code'], c_logs($file, $since));
}

/** Token CSRF da sessão do jar, lido de uma página com formulário. */
function c_csrf(string $base, string $jar, string $page = '/entrar'): string
{
    $r = t_http('GET', $base . $page, null, [], $jar);
    preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m);
    return $m[1] ?? '';
}

/** POST de formulário web (x-www-form-urlencoded). */
function c_form(string $base, string $path, array $fields, string $jar): array
{
    return t_http('POST', $base . $path, http_build_query($fields), ['Content-Type: application/x-www-form-urlencoded'], $jar);
}

/** Chamada JSON à API com X-CSRF-Token. */
function c_api(string $method, string $base, string $path, ?array $body, string $csrf, string $jar): array
{
    $r = t_http($method, $base . $path, $body === null ? null : json_encode($body), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
    $r['json'] = json_decode($r['body'], true);
    return $r;
}

/** Location do redirect (caminho), ou null. */
function c_location(array $r): ?string
{
    if (!preg_match('/^Location:\s*(\S+)/mi', $r['headers'], $m)) {
        return null;
    }
    $path = (string) (parse_url($m[1], PHP_URL_PATH) ?? '');
    $q = parse_url($m[1], PHP_URL_QUERY);
    return $path . ($q ? '?' . $q : '');
}

/** Usuário extra com status arbitrário (mesmo formato de t_seed_owner). */
function c_user(string $status, string $password = 'Casa#123456'): array
{
    $email = 'u' . bin2hex(random_bytes(3)) . '@casalivre.test';
    $id = UserRepository::insert([
        'name' => 'Usuário ' . $status, 'cpf' => '52998224725', 'address' => 'Rua A, 1', 'cep' => '88000000',
        'street' => 'Rua A', 'address_number' => '1', 'address_complement' => '', 'phone' => '48999999999',
        'email' => $email, 'password_hash' => password_make($password), 'status' => $status,
        'activated_at' => $status === 'active' ? date('Y-m-d H:i:s') : null,
    ]);
    return ['id' => $id, 'email' => $email, 'password' => $password];
}

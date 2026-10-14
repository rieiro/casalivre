<?php
// Helpers dos testes de caracterização (char_*_test.php). Só leitura do activity_log e utilidades
// de formulário web; não alteram código de produção.

/** Liga o activity_log num SQLite temporário (antes de t_server()) e devolve o caminho. */
function c_activity_file(): string
{
    $file = tempnam(sys_get_temp_dir(), 'act') . '.sqlite';
    putenv('ACTIVITY_DB_PATH=' . $file);
    return $file;
}

/** Maior id gravado no activity_log (0 se ainda não existe). */
function c_mark(string $file): int
{
    try {
        return (int) (new PDO('sqlite:' . $file))->query('SELECT COALESCE(MAX(id), 0) FROM activity_log')->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

/** Linhas do activity_log após $since: [['code'=>int,'params'=>array,'anterior'=>mixed,'atual'=>mixed], ...]. */
function c_logs(string $file, int $since): array
{
    try {
        $st = (new PDO('sqlite:' . $file))->prepare('SELECT action_code, params, valor_anterior, valor_atual FROM activity_log WHERE id > ? ORDER BY id');
        $st->execute([$since]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
    return array_map(static fn (array $r): array => [
        'code' => (int) $r['action_code'],
        'params' => json_decode((string) $r['params'], true) ?: [],
        'anterior' => $r['valor_anterior'] === null ? null : json_decode((string) $r['valor_anterior'], true),
        'atual' => $r['valor_atual'] === null ? null : json_decode((string) $r['valor_atual'], true),
    ], $rows);
}

/** Só os códigos, na ordem. */
function c_codes(string $file, int $since): array
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

<?php

declare(strict_types=1);

function json_out(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_body(): array
{
    $raw = raw_body();
    if ($raw !== '' && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
}

function api_public_user(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'cpf' => $user['cpf'],
        'address' => $user['address'],
        'phone' => $user['phone'],
        'email' => $user['email'],
        'criado_em' => br_date((string) ($user['created_at'] ?? '')),
    ];
}

function api_public_house(array $house): array
{
    return [
        'id' => property_ref($house),
        'user_id' => (int) $house['user_id'],
        'title' => $house['title'],
        'description' => $house['description'],
        'summary' => $house['summary'],
        'city' => $house['city'],
        'address' => $house['address'],
        'bedrooms' => (int) $house['bedrooms'],
        'beds' => (int) $house['beds'],
        'max_guests' => (int) $house['max_guests'],
        'nightly_price' => (int) $house['nightly_price'],
        'checkin_time' => $house['checkin_time'],
        'checkout_time' => $house['checkout_time'],
        'min_nights' => (int) $house['min_nights'],
        'pool' => (int) $house['pool'],
        'bbq' => (int) $house['bbq'],
        'garage' => (int) $house['garage'],
        'criado_em' => br_date((string) ($house['created_at'] ?? '')),
        'foto' => PhotoRepository::primaryPath((int) $house['id']),
    ];
}

function handle_api(string $path, string $method): void
{
    $body = api_body();
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        $sent = (string) ($body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!hash_equals($_SESSION['csrf'] ?? '', $sent)) {
            json_out(419, ['error' => 'Token de segurança inválido.']);
        }
    }

    if ($path === '/api/sessao' && $method === 'POST') {
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $result = AccountFlow::checkLogin($email, (string) ($body['password'] ?? ''), Settings::int('login_max_failures'));
        if ($result['status'] === 'locked') {
            if (isset($result['failures'])) {
                LoginRateLimiter::hit();
            }
            $params = ['email' => $email];
            if (isset($result['failures'])) {
                $params['tentativas'] = $result['failures'];
            }
            activity(ActivityLog::LOGIN_LOCKED, $params);
            json_out(423, ['error' => t('err.locked')]);
        }
        if ($result['status'] === 'bad') {
            $ipFailures = LoginRateLimiter::hit();
            activity(ActivityLog::LOGIN_FAIL, ['email' => $email]);
            if ($ipFailures >= LoginRateLimiter::maxAttempts()) {
                json_out(429, ['error' => LoginRateLimiter::message()]);
            }
            json_out(401, ['error' => t('err.bad_login')]);
        }
        if ($result['status'] === 'deleted' || $result['status'] === 'inactive') {
            json_out(403, ['error' => account_text($result['status'])]);
        }
        if ($result['status'] === 'pending') {
            json_out(403, ['error' => t('err.pending'), 'verification_link' => $result['user']['activation_link'] ?? null]);
        }
        AccountFlow::openSession((int) $result['user']['id']);
        LoginRateLimiter::clear();
        activity(ActivityLog::LOGIN_OK, ['email' => $email]);
        json_out(200, ['user' => api_public_user($result['user'])]);
    }

    if ($path === '/api/sessao' && $method === 'DELETE') {
        activity(ActivityLog::LOGOUT);
        unset($_SESSION['user_id']);
        json_out(200, ['ok' => true]);
    }

    if ($path === '/api/users' && $method === 'POST') {
        $result = AccountFlow::register($body, Settings::int('email_verify_minutes'), request_base() . '/verificar?token=');
        if ($result['error']) {
            $status = in_array($result['error'], ['deleted', 'inactive', 'email_taken'], true) ? 409 : 422;
            json_out($status, ['error' => account_text($result['error'])]);
        }
        activity(ActivityLog::REGISTER, ['email' => $result['email']]);
        json_out(201, ['id' => $result['id'], 'status' => 'pending', 'verification_link' => $result['link'], 'expires' => $result['expires']]);
    }

    $user = current_user();
    if (!$user && preg_match('#^/api/(users/eu|casas)#', $path)) {
        json_out(401, ['error' => 'Autenticação necessária.']);
    }

    if ($path === '/api/users/eu' && $method === 'GET') {
        json_out(200, ['user' => api_public_user($user)]);
    }

    if ($path === '/api/users/eu' && $method === 'PUT') {
        $error = user_input_error($body, false);
        if ($error) {
            json_out(422, ['error' => $error]);
        }
        $fields = user_fields($body, $user);
        try {
            $changed = UserRepository::update((int) $user['id'], $fields);
            $pair = $changed[0] ?? null;
            if ($pair) {
                activity(ActivityLog::ACCOUNT_UPDATE, ['email' => $fields['email']], $pair['old'], $pair['new']);
            }
        } catch (PDOException) {
            json_out(409, ['error' => t('err.email_taken')]);
        }
        json_out(200, ['user' => api_public_user(UserRepository::find((int) $user['id']))]);
    }

    if ($path === '/api/users/eu' && $method === 'DELETE') {
        $changed = remove_user((int) $user['id']);
        $pair = $changed[0] ?? null;
        if ($pair) {
            activity(ActivityLog::ACCOUNT_DELETE, ['email' => $user['email']], $pair['old'], $pair['new']);
        }
        unset($_SESSION['user_id']);
        json_out(200, ['ok' => true]);
    }

    if ($path === '/api/casas' && $method === 'GET') {
        $rows = array_map('api_public_house', HouseRepository::listByUser((int) $user['id']));
        json_out(200, ['casas' => $rows]);
    }

    if ($path === '/api/casas' && $method === 'POST') {
        $created = HouseService::create((int) $user['id'], $body);
        if (isset($created['error'])) {
            json_out(422, $created);
        }
        activity(ActivityLog::PROPERTY_CREATE, ['imovel_id' => (int) $created['id'], 'titulo' => (string) ($body['title'] ?? '')], null, $created['atual'] ?? null);
        json_out(201, ['casa' => api_public_house(HouseRepository::find((int) $created['id']))]);
    }

    if (preg_match('#^/api/casas/([a-f0-9]{16})$#', $path, $m)) {
        $house = HouseRepository::findByPublicHash($m[1]);
        $id = (int) ($house['id'] ?? 0);
        $house = $house ? HouseRepository::findByUser((int) $user['id'], $id) : null;
        if (!$house) {
            json_out(404, ['error' => 'Imóvel não encontrado.']);
        }
        if ($method === 'GET') {
            $payload = api_public_house($house);
            $payload['fotos'] = PhotoRepository::list($id);
            activity(ActivityLog::PROPERTY_VIEW, ['imovel_id' => $id, 'cidade' => $house['city']]);
            json_out(200, ['casa' => $payload]);
        }
        if ($method === 'PUT') {
            $updated = HouseService::update((int) $user['id'], $id, $body);
            if (isset($updated['error'])) {
                json_out(422, $updated);
            }
            activity(ActivityLog::PROPERTY_UPDATE, ['imovel_id' => $id, 'titulo' => (string) ($body['title'] ?? ''), 'cidade' => (string) ($body['city'] ?? '')] + HouseService::amenityChange($updated['anterior'] ?? [], $updated['atual'] ?? []), $updated['anterior'] ?? null, $updated['atual'] ?? null);
            json_out(200, ['casa' => api_public_house(HouseRepository::find($id))]);
        }
        if ($method === 'DELETE') {
            HouseService::remove((int) $user['id'], $id);
            json_out(200, ['ok' => true, 'estado' => 'inativo']);
        }
    }

    if (preg_match('#^/api/casas/([a-f0-9]{16})/fotos$#', $path, $m) && $method === 'POST') {
        $found = HouseRepository::findByPublicHash($m[1]);
        $house = $found ? HouseRepository::findByUser((int) $user['id'], (int) $found['id']) : null;
        if (!$house) {
            json_out(404, ['error' => 'Imóvel não encontrado.']);
        }
        $createdPhotos = [];
        $saved = PhotoStorage::storeUploads((int) $user['id'], (int) $house['id'], $_FILES['fotos'] ?? [], $createdPhotos);
        activity(ActivityLog::PHOTO_ADD, ['imovel_id' => (int) $house['id'], 'salvas' => $saved], null, ['fotos' => $createdPhotos]);
        json_out(201, ['salvas' => $saved, 'fotos' => PhotoRepository::list((int) $house['id'])]);
    }

    if (preg_match('#^/api/casas/([a-f0-9]{16})/fotos/(\d+)$#', $path, $m) && $method === 'DELETE') {
        $found = HouseRepository::findByPublicHash($m[1]);
        $house = $found ? HouseRepository::findByUser((int) $user['id'], (int) $found['id']) : null;
        if (!$house) {
            json_out(404, ['error' => 'Imóvel não encontrado.']);
        }
        $removed = PhotoRepository::delete((int) $house['id'], (int) $m[2]);
        if (!$removed['old']) {
            json_out(404, ['error' => 'Foto não encontrada.']);
        }
        PhotoStorage::deletePublicFile((string) $removed['old']['path']);
        activity(ActivityLog::PHOTO_DELETE, ['imovel_id' => (int) $house['id'], 'foto_id' => (int) $removed['old']['id']], $removed['old'], ['estado' => 'removida']);
        if ($removed['capa'] !== []) {
            activity(ActivityLog::PHOTO_COVER, ['imovel_id' => (int) $house['id']], array_column($removed['capa'], 'old'), array_column($removed['capa'], 'new'));
        }
        json_out(200, ['ok' => true]);
    }

    json_out(404, ['error' => 'Rota não encontrada.']);
}

function account_text(?string $code): ?string
{
    return match ($code) {
        null, '' => null,
        'required' => t('err.required'),
        'cep' => t('err.cep'),
        'phone' => t('err.phone'),
        'cpf' => t('err.cpf'),
        'short' => password_phrase('err.password_short'),
        'mismatch' => t('err.password_mismatch'),
        'weak' => password_phrase('err.password_weak'),
        'deleted' => t('err.deleted'),
        'inactive' => t('err.inactive'),
        'email_taken' => t('err.email_taken'),
        'invalid' => t('auth.verify_bad'),
        default => t('err.required'),
    };
}

function user_fields(array $input, ?array $current = null): array
{
    return AccountFlow::fields($input, $current);
}

function user_input_error(array $input, bool $requirePassword = true): ?string
{
    return account_text(AccountFlow::problem($input, $requirePassword));
}

function remove_user(int $userId): array
{
    return UserRepository::deactivate($userId, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
}

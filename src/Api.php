<?php

declare(strict_types=1);

require_once __DIR__ . '/IpRateLimiter.php';
require_once __DIR__ . '/RequestLog.php';

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
        return is_array($decoded) ? api_expand_bracket_keys($decoded) : [];
    }
    return $_POST;
}

/**
 * Defesa para clientes que mandam JSON com chaves planas no formato de formulário
 * ("rooms[0][beds][0][type]", "amenity[pool]"): converte em arrays aninhados com parse_str,
 * como o PHP já faz para form-urlencoded (issue #20). Só chaves de topo com valor escalar;
 * se a chave base já veio aninhada no JSON, ela prevalece. Limite de 1000 chaves.
 */
function api_expand_bracket_keys(array $data): array
{
    $flat = [];
    foreach ($data as $key => $value) {
        if (!is_string($key) || preg_match('/^[A-Za-z0-9_]{1,40}\[/', $key) !== 1) {
            continue;
        }
        unset($data[$key]);
        if ((is_scalar($value) || $value === null) && count($flat) < 1000) {
            $flat[] = rawurlencode($key) . '=' . rawurlencode((string) ($value === false ? '' : $value));
        }
    }
    if ($flat === []) {
        return $data;
    }
    parse_str(implode('&', $flat), $nested);
    foreach ($nested as $base => $value) {
        if (!array_key_exists($base, $data)) {
            $data[$base] = $value;
        }
    }
    return $data;
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
            $pending = ['error' => t('err.pending')];
            if (expose_account_links()) {
                $pending['verification_link'] = $result['user']['activation_link'] ?? null;
            }
            json_out(403, $pending);
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
        $created = ['id' => $result['id'], 'status' => 'pending', 'expires' => $result['expires']];
        if (expose_account_links()) {
            $created['verification_link'] = $result['link'];
        }
        json_out(201, $created);
    }

    $user = current_user();

    // Geo: autocomplete de cidades e consulta de CEP (públicos, somente leitura)
    // Limite por IP nos endpoints públicos de geo: 60 requisições/minuto por endpoint.
    if (($path === '/api/cidades' || $path === '/api/ceps') && $method === 'GET') {
        $wait = IpRateLimiter::hit($path, 60, 60);
        if ($wait > 0) {
            header('Retry-After: ' . $wait);
            json_out(429, ['error' => 'Muitas requisições. Tente novamente em instantes.']);
        }
    }

    if ($path === '/api/cidades' && $method === 'GET') {
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
        json_out(200, CityRepository::suggest($q));
    }

    if ($path === '/api/ceps' && $method === 'GET') {
        $digits = preg_replace('/\D+/', '', (string) ($_GET['q'] ?? '')) ?? '';
        if (strlen($digits) !== 8) {
            json_out(422, ['error' => 'CEP deve ter 8 dígitos.']);
        }
        $row = CepRepository::find($digits);
        if (!$row || trim((string) $row['street']) === '') {
            json_out(404, ['error' => 'CEP não encontrado ou sem rua cadastrada.']);
        }
        $known = CityRepository::match($row['city'] . ', ' . $row['uf']);
        json_out(200, [
            'cep' => $row['cep'],
            'street' => $row['street'],
            'neighborhood' => $row['neighborhood'],
            'city' => $row['city'],
            'uf' => $row['uf'],
            'city_label' => $known['label'] ?? ($row['city'] . ', ' . $row['uf']),
        ]);
    }
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

    // --- Novos endpoints (contratos em prompts/api-contratos.md) ---

    if ($path === '/api/busca' && $method === 'GET') {
        [$filters, $results, $searched, $facets] = run_search(true);
        $items = array_map(static function (array $item): array {
            return [
                'id' => property_ref($item),
                'title' => $item['title'],
                'city' => $item['city'],
                'nightly_price' => (int) $item['nightly_price'],
                'photo' => $item['photo'] ?? null,
                'score' => $item['score'] ?? null,
                'quote' => $item['quote'] ?? null,
                'distance_km' => $item['distance_km'] ?? null,
            ];
        }, $results);
        json_out(200, [
            'items' => $items,
            'facets' => $facets,
            'searched' => $searched,
            'filters' => $filters,
        ]);
    }

    if (preg_match('#^/api/imoveis/([a-f0-9]{16})$#', $path, $m) && $method === 'GET') {
        $house = HouseRepository::findByPublicHash($m[1]);
        if (!$house || ($house['status'] ?? 'active') !== 'active') {
            json_out(404, ['error' => 'Imóvel não encontrado.']);
        }
        $id = (int) $house['id'];
        $checkin = (string) ($_GET['checkin'] ?? '');
        $checkout = (string) ($_GET['checkout'] ?? '');
        $nights = nights_between($checkin, $checkout);
        $quote = $nights ? stay_quote($house, $nights) : null;
        $photos = PhotoRepository::list($id);
        $selected = Amenities::selected($house);
        $amenities = array_keys(array_filter($selected));
        activity(ActivityLog::PROPERTY_VIEW, [
            'imovel_id' => $id,
            'cidade' => $house['city'],
            'checkin' => $checkin,
            'checkout' => $checkout,
        ]);
        json_out(200, [
            'id' => property_ref($house),
            'title' => $house['title'],
            'description' => $house['description'],
            'summary' => $house['summary'],
            'city' => $house['city'],
            'address' => $house['address'],
            'photos' => $photos,
            'score' => review_score($id),
            'quote' => $quote,
            'amenities' => $amenities,
            'bedrooms' => (int) $house['bedrooms'],
            'beds' => (int) $house['beds'],
            'max_guests' => (int) $house['max_guests'],
            'nightly_price' => (int) $house['nightly_price'],
            'min_nights' => (int) $house['min_nights'],
            'checkin_time' => $house['checkin_time'],
            'checkout_time' => $house['checkout_time'],
        ]);
    }

    if ($path === '/api/painel' && $method === 'GET') {
        if (!$user) {
            json_out(401, ['error' => 'Autenticação necessária.']);
        }
        $properties = HouseRepository::listByUser((int) $user['id']);
        $items = [];
        foreach ($properties as $item) {
            $items[] = [
                'id' => property_ref($item),
                'title' => $item['title'],
                'city' => $item['city'],
                'photo' => PhotoRepository::primaryPath((int) $item['id']),
                'status' => $item['status'] ?? 'active',
                'nightly_price' => (int) $item['nightly_price'],
            ];
        }
        json_out(200, ['items' => $items]);
    }

    if (preg_match('#^/api/imoveis/([a-f0-9]{16})/avaliar$#', $path, $m) && $method === 'POST') {
        $house = HouseRepository::findByPublicHash($m[1]);
        if (!$house || ($house['status'] ?? 'active') !== 'active') {
            json_out(404, ['error' => 'Imóvel não encontrado.']);
        }
        $score = (int) ($body['score'] ?? 0);
        $author = trim((string) ($body['author'] ?? 'Hóspede'));
        $comment = trim((string) ($body['comment'] ?? ''));
        if ($score < 1 || $score > 10) {
            json_out(422, ['error' => 'Nota deve ser entre 1 e 10.']);
        }
        HouseRepository::addReview((int) $house['id'], $author === '' ? 'Hóspede' : $author, $score, $comment);
        activity(ActivityLog::REVIEW, [
            'imovel_id' => (int) $house['id'],
            'nota' => $score,
            'autor' => $author === '' ? 'Hóspede' : $author,
        ]);
        json_out(201, ['ok' => true]);
    }

    // Calendário
    if (preg_match('#^/api/imoveis/([a-f0-9]{16})/calendario$#', $path, $m) && $method === 'GET') {
        if (!$user) {
            json_out(401, ['error' => 'Autenticação necessária.']);
        }
        $found = HouseRepository::findByPublicHash($m[1]);
        $property = $found ? HouseRepository::findByUser((int) $user['id'], (int) $found['id']) : null;
        if (!$property) {
            json_out(404, ['error' => 'Imóvel não encontrado.']);
        }
        $id = (int) $property['id'];
        // Somente leitura: noites além do horizonte são filtradas, não apagadas.
        json_out(200, [
            'id' => property_ref($property),
            'days' => HouseRepository::calendarDaysUntil($id, max_calendar_day($property)),
            'feeds' => HouseRepository::feeds($id),
        ]);
    }

    if (preg_match('#^/api/imoveis/([a-f0-9]{16})/calendario$#', $path, $m) && $method === 'POST') {
        if (!$user) {
            json_out(401, ['error' => 'Autenticação necessária.']);
        }
        $found = HouseRepository::findByPublicHash($m[1]);
        $property = $found ? HouseRepository::findByUser((int) $user['id'], (int) $found['id']) : null;
        if (!$property) {
            json_out(404, ['error' => 'Imóvel não encontrado.']);
        }
        $saved = save_calendar($property, $body);
        $params = [
            'imovel_id' => (int) $property['id'],
            'inicio' => (string) ($body['inicio'] ?? ''),
            'fim' => (string) ($body['fim'] ?? ''),
            'resultado' => $saved['message'],
        ];
        foreach ($saved['logs'] as $log) {
            activity($log['code'], $params + $log['extra'], $log['anterior'], $log['atual']);
        }
        json_out(200, [
            'ok' => true,
            'message' => $saved['message'],
            'days' => HouseRepository::calendarDays((int) $property['id']),
        ]);
    }

    // Definir foto de capa
    if (preg_match('#^/api/imoveis/([a-f0-9]{16})/fotos/capa$#', $path, $m) && $method === 'POST') {
        if (!$user) {
            json_out(401, ['error' => 'Autenticação necessária.']);
        }
        $found = HouseRepository::findByPublicHash($m[1]);
        $property = $found ? HouseRepository::findByUser((int) $user['id'], (int) $found['id']) : null;
        if (!$property) {
            json_out(404, ['error' => 'Imóvel não encontrado.']);
        }
        $fotoId = (int) ($body['foto_id'] ?? 0);
        if ($fotoId < 1) {
            json_out(422, ['error' => 'foto_id obrigatório.']);
        }
        $changed = PhotoRepository::makePrimary((int) $property['id'], $fotoId);
        if ($changed !== []) {
            activity(ActivityLog::PHOTO_COVER, ['imovel_id' => (int) $property['id']], array_column($changed, 'old'), array_column($changed, 'new'));
        }
        json_out(200, ['ok' => true, 'fotos' => PhotoRepository::list((int) $property['id'])]);
    }

    // Auth extras
    if ($path === '/api/auth/verificar' && $method === 'POST') {
        $result = AccountFlow::activate((string) ($body['token'] ?? ''));
        if ($result['ok']) {
            activity(ActivityLog::EMAIL_VERIFY, ['email' => $result['email'], 'resultado' => 'ok']);
            json_out(200, ['ok' => true, 'email' => $result['email']]);
        }
        activity(ActivityLog::EMAIL_VERIFY, ['resultado' => 'invalido']);
        json_out(400, ['error' => 'Token inválido ou expirado.']);
    }

    if ($path === '/api/auth/senha/esqueci' && $method === 'POST') {
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $sent = AccountFlow::askReset($email, Settings::int('password_reset_minutes'), request_base() . '/senha/redefinir?token=');
        if ($sent) {
            activity(ActivityLog::PASSWORD_RESET_ASK, ['email' => $email]);
        }
        json_out(200, ['ok' => true]);
    }

    if ($path === '/api/auth/senha/redefinir' && $method === 'POST') {
        $result = AccountFlow::reset(
            (string) ($body['token'] ?? ''),
            (string) ($body['password'] ?? ''),
            (string) ($body['password_confirm'] ?? '')
        );
        if ($result['error']) {
            json_out(422, ['error' => account_text($result['error'])]);
        }
        activity(ActivityLog::PASSWORD_RESET, ['email' => $result['email']]);
        json_out(200, ['ok' => true]);
    }

    if ($path === '/api/idioma' && $method === 'POST') {
        $locale = (string) ($body['locale'] ?? '');
        if (!isset(I18n::LOCALES[$locale])) {
            json_out(422, ['error' => 'Idioma inválido.']);
        }
        $_SESSION['locale'] = $locale;
        activity(ActivityLog::LOCALE, ['idioma' => $locale]);
        json_out(200, ['ok' => true, 'locale' => $locale]);
    }

    // Dados para formulário de edição
    if (preg_match('#^/api/imoveis/([a-f0-9]{16})/editar$#', $path, $m) && $method === 'GET') {
        if (!$user) {
            json_out(401, ['error' => 'Autenticação necessária.']);
        }
        $found = HouseRepository::findByPublicHash($m[1]);
        $property = $found ? HouseRepository::findByUser((int) $user['id'], (int) $found['id']) : null;
        if (!$property) {
            json_out(404, ['error' => 'Imóvel não encontrado.']);
        }
        $id = (int) $property['id'];
        json_out(200, [
            'casa' => api_public_house($property),
            'fotos' => PhotoRepository::list($id),
            'amenities' => Amenities::selected($property),
            'cities' => cities(),
        ]);
    }

    // Importar feed iCal
    if (preg_match('#^/api/imoveis/([a-f0-9]{16})/ical/importar$#', $path, $m) && $method === 'POST') {
        if (!$user) {
            json_out(401, ['error' => 'Autenticação necessária.']);
        }
        $found = HouseRepository::findByPublicHash($m[1]);
        $property = $found ? HouseRepository::findByUser((int) $user['id'], (int) $found['id']) : null;
        if (!$property) {
            json_out(404, ['error' => 'Imóvel não encontrado.']);
        }
        $url = trim((string) ($body['url'] ?? $body['external_ical_url'] ?? ''));
        $feedTitle = trim((string) ($body['title'] ?? $body['feed_title'] ?? ''));
        if ($url === '') {
            json_out(422, ['error' => 'URL obrigatória.']);
        }
        $error = HouseRepository::addFeed((int) $property['id'], (int) $user['id'], $url, $feedTitle);
        if ($error === 'limit') {
            json_out(422, ['error' => 'Limite de feeds atingido.']);
        }
        if ($error === 'duplicate') {
            json_out(409, ['error' => 'Feed já cadastrado.']);
        }
        if ($error === 'title') {
            json_out(422, ['error' => 'Título obrigatório.']);
        }
        // Nunca aceitar corpo .ics enviado pelo cliente: só o que o servidor baixar.
        $icsBody = exported_calendar_body($url) ?? CalendarSync::fetch($url);
        $count = 0;
        $status = is_string($icsBody) && $icsBody !== '' ? 'OK' : 'NOK';
        if ($status === 'OK') {
            $count = import_ics((int) $property['id'], $icsBody);
        }
        foreach (HouseRepository::feedsWithUrl((int) $property['id']) as $feed) {
            if ($feed['url'] === $url) {
                HouseRepository::markFeed((int) $feed['id'], $status, date('Y-m-d H:i:s'));
                break;
            }
        }
        activity(ActivityLog::CALENDAR_SYNC, [
            'imovel_id' => (int) $property['id'],
            'url' => CalendarSync::logRef($url),
            'novas' => $count,
            'resultado' => strtolower($status),
        ]);
        if ($status === 'NOK') {
            json_out(200, [
                'ok' => true,
                'status' => 'NOK',
                'importadas' => 0,
                'message' => t('cal.import_fail'),
                'feeds' => HouseRepository::feeds((int) $property['id']),
            ]);
        }
        json_out(200, [
            'ok' => true,
            'importadas' => $count,
            'feeds' => HouseRepository::feeds((int) $property['id']),
        ]);
    }

    // Excluir feed iCal
    if (preg_match('#^/api/imoveis/([a-f0-9]{16})/ical/(\d+)$#', $path, $m) && $method === 'DELETE') {
        if (!$user) {
            json_out(401, ['error' => 'Autenticação necessária.']);
        }
        $found = HouseRepository::findByPublicHash($m[1]);
        $property = $found ? HouseRepository::findByUser((int) $user['id'], (int) $found['id']) : null;
        if (!$property) {
            json_out(404, ['error' => 'Imóvel não encontrado.']);
        }
        $removed = HouseRepository::removeFeed((int) $property['id'], (int) $user['id'], (int) $m[2]);
        if (empty($removed['feed'])) {
            json_out(404, ['error' => 'Feed não encontrado.']);
        }
        $released = HouseRepository::nightAudit(HouseRepository::releaseImported((int) $property['id']));
        activity(ActivityLog::CALENDAR_FEED_REMOVE, ['imovel_id' => (int) $property['id'], 'feed_id' => (int) $m[2]], [
            'feed' => CalendarSync::logFeed($removed['feed']),
            'link' => CalendarSync::logRef($removed['link'][0]['old']['external_ical_url'] ?? null),
            'noites' => $released['anterior'],
        ], [
            'link' => CalendarSync::logRef($removed['link'][0]['new']['external_ical_url'] ?? null),
            'noites' => $released['atual'],
        ]);
        $resync = CalendarSync::resyncProperty((int) $property['id']);
        json_out(200, ['ok' => true, 'ressincronizados' => $resync, 'feeds' => HouseRepository::feeds((int) $property['id'])]);
    }

    // Exportar ICS: somente pelo ical_hash secreto (32 hex). O hash público não exporta mais.
    if (preg_match('#^/api/ical/([a-f0-9]{32})(\.ics)?$#', $path, $m) && $method === 'GET') {
        $house = HouseRepository::findByIcalHash($m[1]);
        if (!$house || ($house['status'] ?? 'active') !== 'active') {
            json_out(404, ['error' => 'Não encontrado.']);
        }
        activity(ActivityLog::CALENDAR_EXPORT, ['imovel_id' => (int) $house['id']]);
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="casalivre-' . property_ref($house) . '.ics"');
        echo export_ics($house);
        exit;
    }

    if (preg_match('#^/api/imoveis/([a-f0-9]{16})/calendario\.ics$#', $path) && $method === 'GET') {
        json_out(404, ['error' => 'Não encontrado.']);
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

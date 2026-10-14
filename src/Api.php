<?php

declare(strict_types=1);

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

/**
 * Fotos para respostas públicas: só caminho, capa e ordem (sem id, property_id nem audit_hold).
 *
 * @param list<array<string, mixed>> $photos
 * @return list<array{path: string, is_primary: int, sort_order: int}>
 */
function api_public_photos(array $photos): array
{
    return array_map(static fn (array $p): array => [
        'path' => (string) $p['path'],
        'is_primary' => (int) ($p['is_primary'] ?? 0),
        'sort_order' => (int) ($p['sort_order'] ?? 0),
    ], $photos);
}

/** Bairro do imóvel (pelo CEP), para mostrar sem o endereço. */
function house_neighborhood(array $house): ?string
{
    $cep = preg_replace('/\D/', '', (string) ($house['cep'] ?? '')) ?? '';
    if (strlen($cep) !== 8) {
        return null;
    }
    $row = CepRepository::find($cep);
    $n = trim((string) ($row['neighborhood'] ?? ''));
    return $n === '' ? null : $n;
}

/** Dono (painel e endpoints /api/casas): inclui o endereço. */
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
        // API: não grava LOGIN_FAIL para conta apagada/inativa e não reenvia ativação (SNAPSHOT, ver LoginAttempt).
        $res = LoginAttempt::run($email, (string) ($body['password'] ?? ''), false, false);
        $res->registrar();
        $status = match ($res->tipo) {
            'ok' => 200,
            'locked' => 423,
            'rate_limited' => 429,
            'bad' => 401,
            default => 403, // deleted | inactive | pending
        };
        if ($status === 200) {
            json_out(200, ['user' => api_public_user($res->dados['user'])]);
        }
        json_out($status, ['error' => $res->mensagem]);
    }

    if ($path === '/api/sessao' && $method === 'DELETE') {
        activity(ActivityLog::LOGOUT);
        unset($_SESSION['user_id']);
        json_out(200, ['ok' => true]);
    }

    if ($path === '/api/users' && $method === 'POST') {
        $result = AccountFlow::register($body, Settings::int('email_verify_minutes'));
        if ($result['error']) {
            json_out(422, ['error' => account_text($result['error'])]);
        }
        // Mesma resposta para e-mail novo ou já cadastrado (anti-enumeração).
        json_out(201, ['status' => 'pending', 'message' => t('auth.pending_email')]);
    }

    $user = current_user();

    // Geo: autocomplete de cidades e consulta de CEP (públicos, somente leitura).
    // Limite por IP (60/min por endpoint) na camada ApiRateLimit do pipeline (public/index.php).

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
        $res = AccountUpdate::run($user, $body);
        $res->registrar();
        if ($res->tipo === 'invalido') {
            json_out(422, ['error' => $res->mensagem]);
        }
        if ($res->tipo === 'email_em_uso') {
            json_out(409, ['error' => $res->mensagem]);
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
        PhotoStorage::deletePhotoFiles($removed['old']);
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
            // Coordenada pública (#36: deslocamento fixo de 300 a 500 m), nunca a exata; usada pelos pins do mapa.
            $pc = isset($item['lat'], $item['lng']) && $item['lat'] !== null && $item['lng'] !== null
                ? public_coord((int) $item['id'], (float) $item['lat'], (float) $item['lng'])
                : null;
            return [
                'id' => property_ref($item),
                'title' => $item['title'],
                'city' => $item['city'],
                'nightly_price' => (int) $item['nightly_price'],
                'photo' => $item['photo'] ?? null,
                'score' => $item['score'] ?? null,
                'quote' => $item['quote'] ?? null,
                'distance_km' => isset($item['distance_km']) ? (int) $item['distance_km'] : null,
                'lat' => $pc['lat'] ?? null,
                'lng' => $pc['lng'] ?? null,
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
        // Público: só fotos prontas (#39) e só path/is_primary/sort_order (#36; sem uuid/status/original).
        // O srcset sai do path (photo_srcset_attr / safe-dom.js).
        $photos = api_public_photos(PhotoRepository::listReady($id));
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
            'neighborhood' => house_neighborhood($house),
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
        $res = CalendarSave::run($property, $body);
        $res->registrar();
        json_out(200, [
            'ok' => true,
            'message' => $res->mensagem,
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
        $sent = AccountFlow::askReset($email, Settings::int('password_reset_minutes'));
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
        // API: sem CALENDAR_FEED_ADD; CALENDAR_SYNC também na falha (ver ICalImport).
        $res = ICalImport::run($property, (int) $user['id'], $url, $feedTitle, false);
        $res->registrar();
        if ($res->tipo === 'limit') {
            json_out(422, ['error' => 'Limite de feeds atingido.']);
        }
        if ($res->tipo === 'duplicate') {
            json_out(409, ['error' => 'Feed já cadastrado.']);
        }
        if ($res->tipo === 'title') {
            json_out(422, ['error' => 'Título obrigatório.']);
        }
        if ($res->tipo === 'nok') {
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
            'importadas' => $res->dados['importadas'],
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
        // API: feed inexistente -> 404 sem liberar noites nem ressincronizar (ver ICalFeedRemove).
        $res = ICalFeedRemove::run((int) $property['id'], (int) $user['id'], (int) $m[2], false);
        $res->registrar();
        if ($res->tipo === 'nao_encontrado') {
            json_out(404, ['error' => $res->mensagem]);
        }
        $resync = $res->dados['ressincronizados'];
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

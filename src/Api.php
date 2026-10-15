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

/** Resposta de erro da API: {"error": mensagem} com o status dado, e encerra. */
function api_error(int $status, string $message, ?array $erros = null): never
{
    json_out($status, $erros ? ['error' => $message, 'erros' => $erros] : ['error' => $message]);
}

/** Exige sessão: devolve o usuário já carregado ou responde 401 'Autenticação necessária.'. */
function api_require_user(?array $user): array
{
    if (!$user) {
        api_error(401, 'Autenticação necessária.');
    }
    return $user;
}

/** Imóvel do hash público que pertence ao usuário (e está ativo), ou 404 'Imóvel não encontrado.'. */
function api_owned_property(array $user, string $hash): array
{
    $found = HouseRepository::findByPublicHash($hash);
    $property = $found ? HouseRepository::findByUser((int) $user['id'], (int) $found['id']) : null;
    if (!$property) {
        api_error(404, 'Imóvel não encontrado.');
    }
    return $property;
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
            api_error(419, 'Token de segurança inválido.');
        }
    }

    if ($path === '/api/sessao' && $method === 'POST') {
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        // API: conta apagada/inativa grava LOGIN_FAIL com o motivo, como a web, e responde 403 com a mensagem
        // específica; pendente reenvia a ativação como a web (limite por conta) e responde 403 err.pending. Esses 403
        // só existem com a senha certa (senha errada: 401 para qualquer conta).
        $res = LoginAttempt::run($email, (string) ($body['password'] ?? ''));
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
        if ($res->tipo === 'pending') {
            // Como a web (verify_pending): até quando vale o link de ativação.
            json_out(403, ['error' => $res->mensagem, 'expires' => $res->dados['expires']]);
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
            api_error(422, account_text($result['error']));
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
            api_error(422, 'CEP deve ter 8 dígitos.');
        }
        $row = CepRepository::find($digits);
        if (!$row || trim((string) $row['street']) === '') {
            api_error(404, 'CEP não encontrado ou sem rua cadastrada.');
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
        api_error(401, 'Autenticação necessária.');
    }

    if ($path === '/api/users/eu' && $method === 'GET') {
        json_out(200, ['user' => api_public_user($user)]);
    }

    if ($path === '/api/users/eu' && $method === 'PUT') {
        $res = AccountUpdate::run($user, $body);
        $res->registrar();
        if ($res->tipo === 'invalido') {
            api_error(422, $res->mensagem);
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
        $house = api_owned_property($user, $m[1]);
        $id = (int) $house['id'];
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
        $house = api_owned_property($user, $m[1]);
        $createdPhotos = [];
        $saved = PhotoStorage::storeUploads((int) $user['id'], (int) $house['id'], $_FILES['fotos'] ?? [], $createdPhotos);
        activity(ActivityLog::PHOTO_ADD, ['imovel_id' => (int) $house['id'], 'salvas' => $saved], null, ['fotos' => $createdPhotos]);
        json_out(201, ['salvas' => $saved, 'fotos' => PhotoRepository::list((int) $house['id'])]);
    }

    if (preg_match('#^/api/casas/([a-f0-9]{16})/fotos/(\d+)$#', $path, $m) && $method === 'DELETE') {
        $house = api_owned_property($user, $m[1]);
        $removed = PhotoRepository::delete((int) $house['id'], (int) $m[2]);
        if (!$removed['old']) {
            api_error(404, 'Foto não encontrada.');
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
        [$filters, $results, $searched, $facets] = SearchService::run(true, $_SERVER['HTTP_HOST'] ?? null);
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
        // No domínio de um grupo, só imóvel dele (mesma regra da página, GrupoResolvedor::imovelNoHost).
        [$noGrupo, $noHost] = GrupoResolvedor::imovelNoHost($m[1], $_SERVER['HTTP_HOST'] ?? null);
        if (!$house || ($house['status'] ?? 'active') !== 'active' || ($noGrupo && $noHost === null)) {
            api_error(404, 'Imóvel não encontrado.');
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
        $user = api_require_user($user);
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
        // No domínio de um grupo, só imóvel dele (mesma regra da página, GrupoResolvedor::imovelNoHost).
        [$noGrupo, $noHost] = GrupoResolvedor::imovelNoHost($m[1], $_SERVER['HTTP_HOST'] ?? null);
        if (!$house || ($house['status'] ?? 'active') !== 'active' || ($noGrupo && $noHost === null)) {
            api_error(404, 'Imóvel não encontrado.');
        }
        $res = ReviewAdd::run(
            (int) $house['id'],
            (int) ($body['score'] ?? 0),
            trim((string) ($body['author'] ?? 'Hóspede')),
            trim((string) ($body['comment'] ?? ''))
        );
        $res->registrar();
        if ($res->tipo === 'invalido') {
            api_error(422, $res->mensagem);
        }
        json_out(201, ['ok' => true]);
    }

    // Calendário
    if (preg_match('#^/api/imoveis/([a-f0-9]{16})/calendario$#', $path, $m) && $method === 'GET') {
        $user = api_require_user($user);
        $property = api_owned_property($user, $m[1]);
        $id = (int) $property['id'];
        // Somente leitura: noites além do horizonte são filtradas, não apagadas.
        json_out(200, [
            'id' => property_ref($property),
            'days' => HouseRepository::calendarDaysUntil($id, max_calendar_day($property)),
            'feeds' => HouseRepository::feeds($id),
        ]);
    }

    if (preg_match('#^/api/imoveis/([a-f0-9]{16})/calendario$#', $path, $m) && $method === 'POST') {
        $user = api_require_user($user);
        $property = api_owned_property($user, $m[1]);
        $res = CalendarSave::run($property, $body);
        $res->registrar();
        if ($res->tipo !== 'ok') {
            api_error($res->tipo === 'conflito' ? 409 : 422, (string) $res->mensagem, $res->dados['erros']);
        }
        json_out(200, [
            'ok' => true,
            'message' => $res->mensagem,
            'days' => HouseRepository::calendarDays((int) $property['id']),
        ]);
    }

    // Definir foto de capa
    if (preg_match('#^/api/imoveis/([a-f0-9]{16})/fotos/capa$#', $path, $m) && $method === 'POST') {
        $user = api_require_user($user);
        $property = api_owned_property($user, $m[1]);
        $fotoId = (int) ($body['foto_id'] ?? 0);
        if ($fotoId < 1) {
            api_error(422, 'foto_id obrigatório.');
        }
        CoverSet::run((int) $property['id'], $fotoId)->registrar();
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
        api_error(400, 'Token inválido ou expirado.');
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
            api_error(422, account_text($result['error']));
        }
        activity(ActivityLog::PASSWORD_RESET, ['email' => $result['email']]);
        json_out(200, ['ok' => true]);
    }

    if ($path === '/api/idioma' && $method === 'POST') {
        $locale = (string) ($body['locale'] ?? '');
        if (!isset(I18n::LOCALES[$locale])) {
            api_error(422, 'Idioma inválido.');
        }
        $_SESSION['locale'] = $locale;
        activity(ActivityLog::LOCALE, ['idioma' => $locale]);
        json_out(200, ['ok' => true, 'locale' => $locale]);
    }

    // Dados para formulário de edição
    if (preg_match('#^/api/imoveis/([a-f0-9]{16})/editar$#', $path, $m) && $method === 'GET') {
        $user = api_require_user($user);
        $property = api_owned_property($user, $m[1]);
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
        $user = api_require_user($user);
        $property = api_owned_property($user, $m[1]);
        // Só os nomes canônicos (os mesmos do formulário web).
        $url = trim((string) ($body['external_ical_url'] ?? ''));
        $feedTitle = trim((string) ($body['feed_title'] ?? ''));
        // Regra, mensagens e logs em ICalImport (iguais aos da web); aqui só o status HTTP.
        $res = ICalImport::run($property, (int) $user['id'], $url, $feedTitle);
        $res->registrar();
        if (isset($res->dados['erros'])) {
            api_error($res->tipo === 'duplicate' ? 409 : 422, (string) $res->mensagem, $res->dados['erros']);
        }
        if ($res->tipo === 'nok') {
            // Feed salvo, mas o download falhou: erro do servidor de origem (502), com o estado atual dos feeds.
            json_out(502, [
                'error' => $res->mensagem,
                'status' => 'NOK',
                'importadas' => 0,
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
        $user = api_require_user($user);
        $property = api_owned_property($user, $m[1]);
        // Feed inexistente -> 404 sem liberar noites nem ressincronizar (ver ICalFeedRemove).
        $res = ICalFeedRemove::remover((int) $property['id'], (int) $user['id'], (int) $m[2]);
        $res->registrar();
        if ($res->tipo === 'nao_encontrado') {
            api_error(404, $res->mensagem);
        }
        $resync = CalendarSync::resyncProperty((int) $property['id']);
        json_out(200, ['ok' => true, 'ressincronizados' => $resync, 'feeds' => HouseRepository::feeds((int) $property['id'])]);
    }

    // Exportar ICS: somente pelo ical_hash secreto (32 hex). O hash público não exporta mais.
    if (preg_match('#^/api/ical/([a-f0-9]{32})(\.ics)?$#', $path, $m) && $method === 'GET') {
        $house = HouseRepository::findByIcalHash($m[1]);
        // No domínio de um grupo, só o calendário de imóvel dele (GrupoResolvedor::imovelNoHost).
        $noHost = $house ? GrupoResolvedor::imovelNoHost(property_ref($house), $_SERVER['HTTP_HOST'] ?? null) : [false, null];
        if (!$house || ($house['status'] ?? 'active') !== 'active' || ($noHost[0] && $noHost[1] === null)) {
            api_error(404, 'Não encontrado.');
        }
        activity(ActivityLog::CALENDAR_EXPORT, ['imovel_id' => (int) $house['id']]);
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="casalivre-' . property_ref($house) . '.ics"');
        echo export_ics($house);
        exit;
    }

    if (preg_match('#^/api/imoveis/([a-f0-9]{16})/calendario\.ics$#', $path) && $method === 'GET') {
        api_error(404, 'Não encontrado.');
    }

    api_error(404, 'Rota não encontrada.');
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
        'invalid' => t('auth.verify_bad'),
        default => t('err.required'),
    };
}

function remove_user(int $userId): array
{
    return UserRepository::deactivate($userId, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
}

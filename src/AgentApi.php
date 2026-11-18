<?php

declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/HouseRepository.php';
require_once __DIR__ . '/SearchQuery.php';
require_once __DIR__ . '/SearchService.php';
require_once __DIR__ . '/QuoteRepository.php';
require_once __DIR__ . '/PublicListing.php';
require_once __DIR__ . '/BotTelemetry.php';
require_once __DIR__ . '/GrupoResolvedor.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/IpRateLimiter.php';

/**
 * Agent REST API and Streamable HTTP MCP server handler (Architecture §4).
 * Completely bypasses handle_api() and session CSRF checks.
 */
final class AgentApi
{
    public static function handle(string $path, string $method): void
    {
        // 1. Discovery & Spec routes
        if ($path === '/llms-full.txt' && $method === 'GET') {
            self::handleLlmsFull();
            return;
        }

        if ($path === '/openapi.json' && $method === 'GET') {
            self::handleOpenApi();
            return;
        }

        // 2. Payment intent landing page
        if (preg_match('#^/pagamento/intencao/([a-f0-9]{32})$#', $path, $m) && $method === 'GET') {
            self::handlePaymentIntent($m[1]);
            return;
        }

        // 3. MCP Streamable HTTP transport
        if ($path === '/mcp') {
            if ($method === 'GET' || $method === 'POST') {
                self::handleMcp($method);
                return;
            }
            http_response_code(405);
            header('Allow: GET, POST');
            exit;
        }

        // 4. REST Agent endpoints
        if ($path === '/api/v1/agent/search' && $method === 'GET') {
            self::handleSearch();
            return;
        }

        if (preg_match('#^/api/v1/agent/properties/([a-f0-9]{16})$#', $path, $m) && $method === 'GET') {
            self::handleProperty($m[1]);
            return;
        }

        if (preg_match('#^/api/v1/agent/properties/([a-f0-9]{16})/quote$#', $path, $m) && $method === 'GET') {
            self::handleQuote($m[1]);
            return;
        }

        if (preg_match('#^/api/v1/agent/properties/([a-f0-9]{16})/intent$#', $path, $m) && $method === 'POST') {
            self::handleIntent($m[1]);
            return;
        }

        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private static function handleSearch(): void
    {
        BotTelemetry::setContext('rest', 'search_properties', null, $_GET);

        try {
            $query = SearchQuery::fromRequest($_GET);
        } catch (Throwable $e) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Invalid search query: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $host = $_SERVER['HTTP_HOST'] ?? null;
        $after = null;
        if (!empty($_GET['cursor'])) {
            $decoded = SearchCursorCodec::decode((string) $_GET['cursor']);
            if ($decoded === null) {
                http_response_code(400);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Invalid cursor'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $after = $decoded;
        }

        try {
            $result = SearchService::search($query, $host, false, $after);
        } catch (Throwable $e) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Search timeout'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $items = [];
        foreach ($result['items'] as $item) {
            $items[] = [
                'id' => property_ref($item),
                'title' => (string) ($item['title'] ?? ''),
                'city' => (string) ($item['city'] ?? ''),
                'nightly_price' => (int) ($item['nightly_price'] ?? 0),
                'max_guests' => (int) ($item['max_guests'] ?? 0),
                'bedrooms' => (int) ($item['bedrooms'] ?? 0),
                'photo' => $item['photo'] ?? null,
            ];
        }

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'items' => $items,
            'facets' => $result['facets'] ?? [],
            'next_cursor' => $result['next_cursor'] ?? null,
            'total_aprox' => $result['total_aprox'] ?? null,
            'page' => $result['page'] ?? 1,
            'per_page' => $result['per_page'] ?? SearchService::PAGE_SIZE,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private static function handleProperty(string $hash): void
    {
        $house = HouseRepository::findByPublicHash($hash);
        [$noGrupo, $noHost] = GrupoResolvedor::imovelNoHost($hash, $_SERVER['HTTP_HOST'] ?? null);

        if (!$house || ($house['status'] ?? 'active') !== 'active' || ($noGrupo && $noHost === null)) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Imóvel não encontrado'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $id = (int) $house['id'];
        BotTelemetry::setContext('rest', 'get_property_details', $id);

        $checkin = (string) ($_GET['checkin'] ?? '');
        $checkout = (string) ($_GET['checkout'] ?? '');
        $listing = public_listing($house, $checkin, $checkout);

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($listing, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private static function handleQuote(string $hash): void
    {
        $house = HouseRepository::findByPublicHash($hash);
        [$noGrupo, $noHost] = GrupoResolvedor::imovelNoHost($hash, $_SERVER['HTTP_HOST'] ?? null);

        if (!$house || ($house['status'] ?? 'active') !== 'active' || ($noGrupo && $noHost === null)) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Imóvel não encontrado'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $id = (int) $house['id'];
        BotTelemetry::setContext('rest', 'check_availability_and_quote', $id, $_GET);

        $checkin = trim((string) ($_GET['checkin'] ?? ''));
        $checkout = trim((string) ($_GET['checkout'] ?? ''));
        $hospedes = (int) ($_GET['hospedes'] ?? 1);

        $res = self::calculateQuote($house, $checkin, $checkout, $hospedes);
        if ($res['status'] !== 200) {
            http_response_code($res['status']);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($res['body'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($res['body'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private static function handleIntent(string $hash): void
    {
        $raw = (string) file_get_contents('php://input');
        $body = json_decode($raw, true);

        if (!is_array($body)) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'invalid_json'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Strict allowed fields check (§4.4)
        $allowed = ['checkin', 'checkout', 'hospedes', 'plan'];
        foreach (array_keys($body) as $key) {
            if (!in_array($key, $allowed, true)) {
                http_response_code(422);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'unexpected_field', 'field' => $key], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        $plan = (string) ($body['plan'] ?? '');
        if (!in_array($plan, ['pix_a_vista', 'sinal_50', 'cartao_12x'], true)) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'invalid_plan'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $house = HouseRepository::findByPublicHash($hash);
        [$noGrupo, $noHost] = GrupoResolvedor::imovelNoHost($hash, $_SERVER['HTTP_HOST'] ?? null);

        if (!$house || ($house['status'] ?? 'active') !== 'active' || ($noGrupo && $noHost === null)) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Imóvel não encontrado'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $id = (int) $house['id'];
        BotTelemetry::setContext('rest', 'create_booking_intent', $id, $body);

        $checkin = trim((string) ($body['checkin'] ?? ''));
        $checkout = trim((string) ($body['checkout'] ?? ''));
        $hospedes = (int) ($body['hospedes'] ?? 1);

        $quoteRes = self::calculateQuote($house, $checkin, $checkout, $hospedes);
        if ($quoteRes['status'] !== 200) {
            http_response_code($quoteRes['status']);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($quoteRes['body'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $quoteData = $quoteRes['body'];

        // Guard: max 3 overlapping active holds (§4.4)
        $stmt = db()->prepare(
            "SELECT count(*) FROM booking_intents
              WHERE property_id = :pid
                AND status = 'held'
                AND expires_at > now()
                AND checkin < :checkout
                AND checkout > :checkin"
        );
        $stmt->execute([
            ':pid' => $id,
            ':checkout' => $checkout,
            ':checkin' => $checkin,
        ]);
        $overlaps = (int) $stmt->fetchColumn();

        if ($overlaps >= 3) {
            http_response_code(429);
            header('Retry-After: 60');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Too many open holds on this property'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Determine plan values
        $total = (int) $quoteData['total'];
        $amountDueNow = $total;
        $balanceDue = 0;
        $installments = 1;

        if ($plan === 'sinal_50') {
            $amountDueNow = intdiv($total, 2);
            $balanceDue = $total - $amountDueNow;
            $installments = 1;
        } elseif ($plan === 'cartao_12x') {
            $amountDueNow = $total;
            $balanceDue = 0;
            $installments = 12;
        }

        $token = bin2hex(random_bytes(16)); // 32 hex chars
        $expiresAt = date('c', time() + 900); // 15 minutes out

        $insertStmt = db()->prepare(
            "INSERT INTO booking_intents (
                token, property_id, checkin, checkout, guests, nights,
                nightly_price, cleaning_fee, total_price, plan, amount_due_now,
                balance_due, installments, status, expires_at
             ) VALUES (
                :token, :property_id, :checkin, :checkout, :guests, :nights,
                :nightly_price, :cleaning_fee, :total_price, :plan, :amount_due_now,
                :balance_due, :installments, 'held', :expires_at
             )"
        );

        $insertStmt->execute([
            ':token' => $token,
            ':property_id' => $id,
            ':checkin' => $checkin,
            ':checkout' => $checkout,
            ':guests' => $hospedes,
            ':nights' => (int) $quoteData['nights'],
            ':nightly_price' => (int) $quoteData['nightly_price'],
            ':cleaning_fee' => (int) $quoteData['cleaning_fee'],
            ':total_price' => $total,
            ':plan' => $plan,
            ':amount_due_now' => $amountDueNow,
            ':balance_due' => $balanceDue,
            ':installments' => $installments,
            ':expires_at' => $expiresAt,
        ]);

        $host = $_SERVER['HTTP_HOST'] ?? 'casalivre.com.br';
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'https';
        $checkoutUrl = $scheme . '://' . $host . '/pagamento/intencao/' . $token . '?plano=' . urlencode($plan);

        http_response_code(201);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'intent_token' => $token,
            'expires_at' => $expiresAt,
            'plan' => $plan,
            'total' => $total,
            'amount_due_now' => $amountDueNow,
            'balance_due' => $balanceDue,
            'installments' => $installments,
            'checkout_url' => $checkoutUrl,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Common availability and quote calculation.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function calculateQuote(array $house, string $checkin, string $checkout, int $hospedes): array
    {
        if (!preg_match('#^\d{4}-\d{2}-\d{2}$#', $checkin) || !preg_match('#^\d{4}-\d{2}-\d{2}$#', $checkout) || $checkin >= $checkout) {
            return [
                'status' => 422,
                'body' => ['error' => 'Datas de check-in e check-out inválidas.'],
            ];
        }

        $id = (int) $house['id'];
        $nightDates = nights_between($checkin, $checkout);
        $nights = count($nightDates);
        $minNights = max(1, (int) ($house['min_nights'] ?? 1));

        if ($nights < $minNights) {
            return [
                'status' => 422,
                'body' => ['error' => "Estadia mínima de {$minNights} noites."],
            ];
        }

        $avail = QuoteRepository::checkAvailability($id, $checkin, $checkout);
        if (!$avail['available']) {
            return [
                'status' => 409,
                'body' => [
                    'available' => false,
                    'conflict_date' => $avail['conflict_date'],
                ],
            ];
        }

        $nightly = (int) ($house['nightly_price'] ?? 0);
        $cleaning = (int) ($house['cleaning_fee'] ?? 0);
        $total = ($nightly * $nights) + $cleaning;

        $instAmount = intdiv($total, 12);
        $instRemainder = $total - ($instAmount * 11);

        $plans = [
            [
                'id' => 'pix_a_vista',
                'amount_due_now' => $total,
                'balance_due' => 0,
                'installments' => 1,
            ],
            [
                'id' => 'sinal_50',
                'amount_due_now' => intdiv($total, 2),
                'balance_due' => $total - intdiv($total, 2),
                'installments' => 1,
            ],
            [
                'id' => 'cartao_12x',
                'amount_due_now' => $total,
                'balance_due' => 0,
                'installments' => 12,
                'installment_amount' => $instAmount,
                'installment_remainder' => $instRemainder,
            ],
        ];

        return [
            'status' => 200,
            'body' => [
                'available' => true,
                'checkin' => $checkin,
                'checkout' => $checkout,
                'nights' => $nights,
                'nightly_price' => $nightly,
                'cleaning_fee' => $cleaning,
                'total' => $total,
                'currency' => 'BRL',
                'plans' => $plans,
            ],
        ];
    }

    private static function handleMcp(string $method): void
    {
        BotTelemetry::setContext('mcp', 'mcp');

        if ($method === 'GET') {
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'name' => 'casalivre',
                'version' => '1.0.0',
                'status' => 'ok',
                'tools' => [
                    'search_properties',
                    'get_property_details',
                    'check_availability_and_quote',
                    'create_booking_intent',
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $raw = (string) file_get_contents('php://input');
        $rpc = json_decode($raw, true);

        if (!is_array($rpc) || !isset($rpc['jsonrpc']) || $rpc['jsonrpc'] !== '2.0') {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32700, 'message' => 'Parse error'],
            ]);
            exit;
        }

        $id = $rpc['id'] ?? null;
        $mcpMethod = (string) ($rpc['method'] ?? '');

        if ($mcpMethod === 'initialize') {
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => ['tools' => new stdClass()],
                    'serverInfo' => ['name' => 'casalivre', 'version' => '1.0.0'],
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($mcpMethod === 'notifications/initialized') {
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['jsonrpc' => '2.0', 'result' => new stdClass()]);
            exit;
        }

        if ($mcpMethod === 'tools/list') {
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'tools' => self::mcpToolDefinitions(),
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($mcpMethod === 'tools/call') {
            $params = $rpc['params'] ?? [];
            $toolName = (string) ($params['name'] ?? '');
            $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

            BotTelemetry::setContext('mcp', $toolName);

            $callRes = self::callMcpTool($toolName, $args);
            if (isset($callRes['error'])) {
                http_response_code(200);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'error' => $callRes['error'],
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => json_encode($callRes['result'], JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => -32601, 'message' => 'Method not found'],
        ]);
        exit;
    }

    /**
     * Calls MCP tool by executing internal logic without HTTP loopback.
     */
    private static function callMcpTool(string $toolName, array $args): array
    {
        if ($toolName === 'search_properties') {
            $query = SearchQuery::fromRequest($args);
            $host = $_SERVER['HTTP_HOST'] ?? null;
            $after = null;
            if (!empty($args['cursor'])) {
                $after = SearchCursorCodec::decode((string) $args['cursor']);
            }
            $result = SearchService::search($query, $host, false, $after);
            $items = [];
            foreach ($result['items'] as $item) {
                $items[] = [
                    'id' => property_ref($item),
                    'title' => (string) ($item['title'] ?? ''),
                    'city' => (string) ($item['city'] ?? ''),
                    'nightly_price' => (int) ($item['nightly_price'] ?? 0),
                    'max_guests' => (int) ($item['max_guests'] ?? 0),
                    'bedrooms' => (int) ($item['bedrooms'] ?? 0),
                    'photo' => $item['photo'] ?? null,
                ];
            }
            return ['result' => [
                'items' => $items,
                'facets' => $result['facets'] ?? [],
                'next_cursor' => $result['next_cursor'] ?? null,
                'total_aprox' => $result['total_aprox'] ?? null,
                'page' => $result['page'] ?? 1,
                'per_page' => $result['per_page'] ?? SearchService::PAGE_SIZE,
            ]];
        }

        if ($toolName === 'get_property_details') {
            $hash = (string) ($args['public_hash'] ?? '');
            $house = HouseRepository::findByPublicHash($hash);
            [$noGrupo, $noHost] = GrupoResolvedor::imovelNoHost($hash, $_SERVER['HTTP_HOST'] ?? null);
            if (!$house || ($house['status'] ?? 'active') !== 'active' || ($noGrupo && $noHost === null)) {
                return ['error' => ['code' => -32004, 'message' => 'Imóvel não encontrado']];
            }
            $checkin = (string) ($args['checkin'] ?? '');
            $checkout = (string) ($args['checkout'] ?? '');
            return ['result' => public_listing($house, $checkin, $checkout)];
        }

        if ($toolName === 'check_availability_and_quote') {
            $hash = (string) ($args['public_hash'] ?? '');
            $house = HouseRepository::findByPublicHash($hash);
            [$noGrupo, $noHost] = GrupoResolvedor::imovelNoHost($hash, $_SERVER['HTTP_HOST'] ?? null);
            if (!$house || ($house['status'] ?? 'active') !== 'active' || ($noGrupo && $noHost === null)) {
                return ['error' => ['code' => -32004, 'message' => 'Imóvel não encontrado']];
            }
            $checkin = trim((string) ($args['checkin'] ?? ''));
            $checkout = trim((string) ($args['checkout'] ?? ''));
            $hospedes = (int) ($args['hospedes'] ?? 1);
            $res = self::calculateQuote($house, $checkin, $checkout, $hospedes);
            return ['result' => $res['body']];
        }

        if ($toolName === 'create_booking_intent') {
            // Check fail-closed intent bucket (§4.6)
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            $allowed = false;
            IpRateLimiter::reserve('/api/v1/agent/intent', 10, 600, $ip, static function (bool $ok) use (&$allowed): bool {
                $allowed = $ok;
                return $ok;
            });
            if (!$allowed) {
                return ['error' => ['code' => -32029, 'message' => 'Rate limit exceeded for booking intents']];
            }

            $hash = (string) ($args['public_hash'] ?? '');
            $house = HouseRepository::findByPublicHash($hash);
            [$noGrupo, $noHost] = GrupoResolvedor::imovelNoHost($hash, $_SERVER['HTTP_HOST'] ?? null);
            if (!$house || ($house['status'] ?? 'active') !== 'active' || ($noGrupo && $noHost === null)) {
                return ['error' => ['code' => -32004, 'message' => 'Imóvel não encontrado']];
            }

            $checkin = trim((string) ($args['checkin'] ?? ''));
            $checkout = trim((string) ($args['checkout'] ?? ''));
            $hospedes = (int) ($args['hospedes'] ?? 1);
            $plan = (string) ($args['plan'] ?? '');

            if (!in_array($plan, ['pix_a_vista', 'sinal_50', 'cartao_12x'], true)) {
                return ['error' => ['code' => -32602, 'message' => 'Invalid plan']];
            }

            $quoteRes = self::calculateQuote($house, $checkin, $checkout, $hospedes);
            if ($quoteRes['status'] !== 200) {
                return ['result' => $quoteRes['body']];
            }

            $id = (int) $house['id'];
            $stmt = db()->prepare(
                "SELECT count(*) FROM booking_intents
                  WHERE property_id = :pid
                    AND status = 'held'
                    AND expires_at > now()
                    AND checkin < :checkout
                    AND checkout > :checkin"
            );
            $stmt->execute([':pid' => $id, ':checkout' => $checkout, ':checkin' => $checkin]);
            if ((int) $stmt->fetchColumn() >= 3) {
                return ['error' => ['code' => -32029, 'message' => 'Too many open holds on this property']];
            }

            $quoteData = $quoteRes['body'];
            $total = (int) $quoteData['total'];
            $amountDueNow = $total;
            $balanceDue = 0;
            $installments = 1;

            if ($plan === 'sinal_50') {
                $amountDueNow = intdiv($total, 2);
                $balanceDue = $total - $amountDueNow;
                $installments = 1;
            } elseif ($plan === 'cartao_12x') {
                $amountDueNow = $total;
                $balanceDue = 0;
                $installments = 12;
            }

            $token = bin2hex(random_bytes(16));
            $expiresAt = date('c', time() + 900);

            $insertStmt = db()->prepare(
                "INSERT INTO booking_intents (
                    token, property_id, checkin, checkout, guests, nights,
                    nightly_price, cleaning_fee, total_price, plan, amount_due_now,
                    balance_due, installments, status, expires_at
                 ) VALUES (
                    :token, :property_id, :checkin, :checkout, :guests, :nights,
                    :nightly_price, :cleaning_fee, :total_price, :plan, :amount_due_now,
                    :balance_due, :installments, 'held', :expires_at
                 )"
            );
            $insertStmt->execute([
                ':token' => $token,
                ':property_id' => $id,
                ':checkin' => $checkin,
                ':checkout' => $checkout,
                ':guests' => $hospedes,
                ':nights' => (int) $quoteData['nights'],
                ':nightly_price' => (int) $quoteData['nightly_price'],
                ':cleaning_fee' => (int) $quoteData['cleaning_fee'],
                ':total_price' => $total,
                ':plan' => $plan,
                ':amount_due_now' => $amountDueNow,
                ':balance_due' => $balanceDue,
                ':installments' => $installments,
                ':expires_at' => $expiresAt,
            ]);

            $host = $_SERVER['HTTP_HOST'] ?? 'casalivre.com.br';
            $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'https';
            $checkoutUrl = $scheme . '://' . $host . '/pagamento/intencao/' . $token . '?plano=' . urlencode($plan);

            return ['result' => [
                'intent_token' => $token,
                'expires_at' => $expiresAt,
                'plan' => $plan,
                'total' => $total,
                'amount_due_now' => $amountDueNow,
                'balance_due' => $balanceDue,
                'installments' => $installments,
                'checkout_url' => $checkoutUrl,
            ]];
        }

        return ['error' => ['code' => -32602, 'message' => "Unknown tool: {$toolName}"]];
    }

    private static function mcpToolDefinitions(): array
    {
        return [
            [
                'name' => 'search_properties',
                'description' => 'Search available vacation rentals in Brazil by locality, dates, guests and filters',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'localidade' => ['type' => 'string', 'description' => 'City or destination name'],
                        'checkin' => ['type' => 'string', 'format' => 'date'],
                        'checkout' => ['type' => 'string', 'format' => 'date'],
                        'hospedes' => ['type' => 'integer', 'minimum' => 1],
                        'preco_min' => ['type' => 'integer'],
                        'preco_max' => ['type' => 'integer'],
                        'sort' => ['type' => 'string', 'enum' => ['relevance', 'rating']],
                    ],
                ],
            ],
            [
                'name' => 'get_property_details',
                'description' => 'Retrieve detailed information and photos for a specific rental property',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['public_hash'],
                    'properties' => [
                        'public_hash' => ['type' => 'string', 'pattern' => '^[a-f0-9]{16}$'],
                        'checkin' => ['type' => 'string', 'format' => 'date'],
                        'checkout' => ['type' => 'string', 'format' => 'date'],
                    ],
                ],
            ],
            [
                'name' => 'check_availability_and_quote',
                'description' => 'Check calendar availability and compute price quote with available payment plans',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['public_hash', 'checkin', 'checkout', 'hospedes'],
                    'properties' => [
                        'public_hash' => ['type' => 'string', 'pattern' => '^[a-f0-9]{16}$'],
                        'checkin' => ['type' => 'string', 'format' => 'date'],
                        'checkout' => ['type' => 'string', 'format' => 'date'],
                        'hospedes' => ['type' => 'integer', 'minimum' => 1],
                    ],
                ],
            ],
            [
                'name' => 'create_booking_intent',
                'description' => 'Create a 15-minute soft hold on a property and generate a guest checkout URL',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['public_hash', 'checkin', 'checkout', 'hospedes', 'plan'],
                    'properties' => [
                        'public_hash' => ['type' => 'string', 'pattern' => '^[a-f0-9]{16}$'],
                        'checkin' => ['type' => 'string', 'format' => 'date'],
                        'checkout' => ['type' => 'string', 'format' => 'date'],
                        'hospedes' => ['type' => 'integer', 'minimum' => 1],
                        'plan' => ['type' => 'string', 'enum' => ['pix_a_vista', 'sinal_50', 'cartao_12x']],
                    ],
                ],
            ],
        ];
    }

    private static function handleLlmsFull(): void
    {
        BotTelemetry::setContext('rest', 'discover');

        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=300');

        $rows = db()->query(
            "SELECT p.id, p.hash, p.title, p.city, p.bedrooms, p.beds, p.max_guests,
                    p.nightly_price, p.cleaning_fee, p.min_nights, p.checkin_time, p.checkout_time,
                    p.summary, p.cep
               FROM properties p
              WHERE p.status = 'active'
              ORDER BY p.id ASC
              LIMIT 500"
        )->fetchAll(PDO::FETCH_ASSOC);

        $blocks = [];
        foreach ($rows as $row) {
            $neighborhood = house_neighborhood($row) ?? '-';
            $selected = Amenities::selected($row);
            $amenities = [];
            foreach (Amenities::keys() as $key) {
                if (!empty($selected[$key])) {
                    $amenities[] = $key;
                }
            }

            $summary = str_replace(["
", "
"], ' ', (string) ($row['summary'] ?? ''));

            $blocks[] = implode("
", [
                '## ' . $row['title'],
                'url: /imoveis/' . $row['hash'],
                'city: ' . $row['city'],
                'neighborhood: ' . $neighborhood,
                'guests: ' . (int) $row['max_guests'],
                'bedrooms: ' . (int) $row['bedrooms'],
                'beds: ' . (int) $row['beds'],
                'nightly_brl: ' . (int) $row['nightly_price'],
                'cleaning_brl: ' . (int) ($row['cleaning_fee'] ?? 0),
                'min_nights: ' . (int) ($row['min_nights'] ?? 1),
                'checkin: ' . ($row['checkin_time'] ?? '14:00'),
                'checkout: ' . ($row['checkout_time'] ?? '11:00'),
                'amenities: ' . (!empty($amenities) ? implode(', ', $amenities) : '-'),
                'summary: ' . $summary,
            ]);
        }

        echo implode("

", $blocks);
        if (count($rows) >= 500) {
            echo "

truncated: true
";
        }
        exit;
    }

    private static function handleOpenApi(): void
    {
        BotTelemetry::setContext('rest', 'discover');

        $path = dirname(__DIR__) . '/openapi.json';
        if (!is_file($path)) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'openapi.json not found']);
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        readfile($path);
        exit;
    }

    private static function handlePaymentIntent(string $token): void
    {
        $stmt = db()->prepare("SELECT * FROM booking_intents WHERE token = :token");
        $stmt->execute([':token' => $token]);
        $intent = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$intent) {
            http_response_code(404);
            view('message', ['title' => 'Intenção não encontrada', 'message' => 'Esta intenção de reserva não existe.']);
            exit;
        }

        if ($intent['status'] !== 'held' || strtotime((string) $intent['expires_at']) <= time()) {
            http_response_code(410);
            view('message', ['title' => 'Intenção expirada', 'message' => 'Esta intenção de reserva expirou. Faça uma nova cotação.']);
            exit;
        }

        $property = HouseRepository::find((int) $intent['property_id']);
        if (!$property || ($property['status'] ?? 'active') !== 'active') {
            http_response_code(404);
            view('message', ['title' => 'Imóvel não encontrado', 'message' => 'O imóvel desta reserva não está mais disponível.']);
            exit;
        }

        $labels = [
            'pix_a_vista' => 'Pague o valor integral via Pix no fechamento.',
            'sinal_50' => 'Pague 50% agora. A segunda metade deve ser quitada antes do check-in.',
            'cartao_12x' => 'Pague o valor total no cartão em até 12 parcelas.',
        ];

        view('payment_intent', [
            'intent' => $intent,
            'house' => [
                'title' => $property['title'],
                'city' => $property['city'],
            ],
            'planLabel' => $labels[$intent['plan']] ?? 'Pagamento padrão',
        ]);
        exit;
    }
}

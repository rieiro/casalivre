<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Db.php';
require_once dirname(__DIR__, 2) . '/src/AgentApi.php';
require_once dirname(__DIR__, 2) . '/src/HouseRepository.php';

final class AgentApiTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        putenv('APP_ENV=testing');
        Db::loadEnv();
        $this->pdo = db();
        $this->pdo->beginTransaction();
        IpRateLimiter::$namespace = 'test_' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testQuoteCalculatesFigureAndInsertsNothing(): void
    {
        $house = $this->pdo->query("SELECT * FROM properties WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($house);

        $beforeQuotes = (int) $this->pdo->query("SELECT count(*) FROM quotes")->fetchColumn();
        $beforeIntents = (int) $this->pdo->query("SELECT count(*) FROM booking_intents")->fetchColumn();
        $beforeCalendar = (int) $this->pdo->query("SELECT count(*) FROM calendar_days WHERE property_id = " . (int) $house['id'])->fetchColumn();

        $refMethod = new ReflectionMethod(AgentApi::class, 'calculateQuote');
        $res = $refMethod->invoke(null, $house, '2028-09-01', '2028-09-05', 2);

        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['body']['available']);
        $this->assertSame(4, $res['body']['nights']);
        $this->assertSame('BRL', $res['body']['currency']);

        $plans = $res['body']['plans'];
        $this->assertCount(3, $plans);
        $this->assertSame('pix_a_vista', $plans[0]['id']);
        $this->assertSame('sinal_50', $plans[1]['id']);
        $this->assertSame('cartao_12x', $plans[2]['id']);

        // Assert database state is completely untouched
        $afterQuotes = (int) $this->pdo->query("SELECT count(*) FROM quotes")->fetchColumn();
        $afterIntents = (int) $this->pdo->query("SELECT count(*) FROM booking_intents")->fetchColumn();
        $afterCalendar = (int) $this->pdo->query("SELECT count(*) FROM calendar_days WHERE property_id = " . (int) $house['id'])->fetchColumn();

        $this->assertSame($beforeQuotes, $afterQuotes);
        $this->assertSame($beforeIntents, $afterIntents);
        $this->assertSame($beforeCalendar, $afterCalendar);
    }

    public function testMcpToolExecutionCreatesIntentAndEnforcesThreeHoldCap(): void
    {
        $house = $this->pdo->query("SELECT * FROM properties WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $hash = $house['public_hash'];

        $refCall = new ReflectionMethod(AgentApi::class, 'callMcpTool');

        // Test 1: Call search_properties
        $searchRes = $refCall->invoke(null, 'search_properties', ['localidade' => 'Florianópolis']);
        $this->assertArrayHasKey('result', $searchRes);
        $this->assertArrayHasKey('items', $searchRes['result']);

        // Test 2: Call get_property_details
        $propRes = $refCall->invoke(null, 'get_property_details', ['public_hash' => $hash]);
        $this->assertArrayHasKey('result', $propRes);
        $this->assertSame($hash, $propRes['result']['id']);

        // Test 3: Call check_availability_and_quote
        $quoteRes = $refCall->invoke(null, 'check_availability_and_quote', [
            'public_hash' => $hash,
            'checkin' => '2028-10-01',
            'checkout' => '2028-10-05',
            'hospedes' => 2,
        ]);
        $this->assertArrayHasKey('result', $quoteRes);
        $this->assertTrue($quoteRes['result']['available']);

        // Test 4: Create holds up to 3 cap
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $intentArgs = [
            'public_hash' => $hash,
            'checkin' => '2028-10-01',
            'checkout' => '2028-10-05',
            'hospedes' => 2,
            'plan' => 'pix_a_vista',
        ];

        // 1st hold -> success
        $h1 = $refCall->invoke(null, 'create_booking_intent', $intentArgs);
        $this->assertArrayHasKey('result', $h1);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $h1['result']['intent_token']);
        $this->assertStringContainsString('/pagamento/intencao/', $h1['result']['checkout_url']);

        // 2nd hold -> success
        $h2 = $refCall->invoke(null, 'create_booking_intent', $intentArgs);
        $this->assertArrayHasKey('result', $h2);

        // 3rd hold -> success
        $h3 = $refCall->invoke(null, 'create_booking_intent', $intentArgs);
        $this->assertArrayHasKey('result', $h3);

        // 4th overlapping hold -> blocked with 3-hold cap error code -32029
        $h4 = $refCall->invoke(null, 'create_booking_intent', $intentArgs);
        $this->assertArrayHasKey('error', $h4);
        $this->assertSame(-32029, $h4['error']['code']);
        $this->assertStringContainsString('Too many open holds', $h4['error']['message']);

        // Calendar days must remain untouched
        $calendarCount = (int) $this->pdo->query("SELECT count(*) FROM calendar_days WHERE property_id = " . (int) $house['id'] . " AND day >= '2028-10-01' AND day < '2028-10-05'")->fetchColumn();
        $this->assertSame(0, $calendarCount);
    }

    public function testBookingIntentShowsInCalendarDaysUntilAsPreReservation(): void
    {
        $house = $this->pdo->query("SELECT * FROM properties WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $propId = (int) $house['id'];

        $token = bin2hex(random_bytes(16));
        $this->pdo->prepare("
            INSERT INTO booking_intents (
                token, property_id, checkin, checkout, guests, nights,
                nightly_price, cleaning_fee, total_price, plan, amount_due_now,
                balance_due, installments, status, expires_at
            ) VALUES (
                :token, :pid, '2027-01-29', '2027-01-31', 2, 2,
                245, 150, 640, 'pix_a_vista', 640,
                0, 1, 'held', CURRENT_TIMESTAMP + INTERVAL '15 minutes'
            )
        ")->execute([':token' => $token, ':pid' => $propId]);

        $days = HouseRepository::calendarDaysUntil($propId, '2027-02-05');
        $dayMap = array_column($days, null, 'day');

        $this->assertArrayHasKey('2027-01-29', $dayMap);
        $this->assertSame('pre-reserva', $dayMap['2027-01-29']['origin']);
        $this->assertSame(1, (int) $dayMap['2027-01-29']['blocked']);

        $this->assertArrayHasKey('2027-01-30', $dayMap);
        $this->assertSame('pre-reserva', $dayMap['2027-01-30']['origin']);
        $this->assertSame(1, (int) $dayMap['2027-01-30']['blocked']);

        // Check-out day itself is not a blocked night
        $this->assertTrue(!isset($dayMap['2027-01-31']) || $dayMap['2027-01-31']['origin'] !== 'pre-reserva');

        // When expired, it must no longer show as pre-reserva
        $this->pdo->prepare("UPDATE booking_intents SET status = 'expired' WHERE token = :token")
            ->execute([':token' => $token]);

        $daysAfter = HouseRepository::calendarDaysUntil($propId, '2027-02-05');
        $dayMapAfter = array_column($daysAfter, null, 'day');
        $this->assertTrue(!isset($dayMapAfter['2027-01-29']) || $dayMapAfter['2027-01-29']['origin'] !== 'pre-reserva');
    }
}

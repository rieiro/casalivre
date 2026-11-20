<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Db.php';
require_once dirname(__DIR__, 2) . '/src/I18n.php';
require_once dirname(__DIR__, 2) . '/src/helpers.php';
require_once dirname(__DIR__, 2) . '/src/GooglePlacesService.php';
require_once dirname(__DIR__, 2) . '/src/HouseService.php';
require_once dirname(__DIR__, 2) . '/src/PublicListing.php';
require_once dirname(__DIR__, 2) . '/src/Acoes/GooglePlaceVerify.php';

final class GooglePlacesServiceTest extends TestCase
{
    private const PLACE = 'ChIJN1t_tDeuEmsRUsoyG83frY4';
    private const PLACE_NEW = 'ChIJN1t_tDeuEmsRUsoyG83frY5';

    private PDO $pdo;
    private ?Closure $savedTransport = null;
    private ?Closure $savedClock = null;
    private string|false $savedKey = false;
    private string|false $savedTtl = false;
    private string|false $savedTimeout = false;

    protected function setUp(): void
    {
        putenv('APP_ENV=testing');
        Db::loadEnv();
        $this->pdo = db();
        $this->pdo->beginTransaction();
        $this->savedTransport = GooglePlacesService::$httpTransport;
        $this->savedClock = GooglePlacesService::$clock;
        $this->savedKey = getenv('GOOGLE_PLACES_API_KEY');
        $this->savedTtl = getenv('GOOGLE_PLACES_CACHE_TTL');
        $this->savedTimeout = getenv('GOOGLE_PLACES_TIMEOUT');
        putenv('GOOGLE_PLACES_API_KEY=test-key');
        putenv('GOOGLE_PLACES_CACHE_TTL=86400');
        putenv('GOOGLE_PLACES_TIMEOUT=3');
        GooglePlacesService::$clock = static fn (): int => 1700000000;
    }

    protected function tearDown(): void
    {
        GooglePlacesService::$httpTransport = $this->savedTransport;
        GooglePlacesService::$clock = $this->savedClock;
        foreach ([
            'GOOGLE_PLACES_API_KEY' => $this->savedKey,
            'GOOGLE_PLACES_CACHE_TTL' => $this->savedTtl,
            'GOOGLE_PLACES_TIMEOUT' => $this->savedTimeout,
        ] as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'rating' => 4.8,
            'userRatingCount' => 124,
            'googleMapsUri' => 'https://maps.google.com/?cid=10281119596374313554',
            'reviews' => [
                [
                    'authorAttribution' => ['displayName' => 'Maria S.', 'photoUri' => 'https://lh3.googleusercontent.com/x'],
                    'rating' => 5,
                    'text' => ['text' => 'Excelente estadia, vista incrível.'],
                    'relativePublishTimeDescription' => 'há 2 semanas',
                ],
                [
                    'authorAttribution' => ['displayName' => 'João P.'],
                    'rating' => 4,
                    'text' => ['text' => str_repeat('a', 400)],
                    'relativePublishTimeDescription' => 'há 1 mês',
                ],
                [
                    'authorAttribution' => ['displayName' => 'Ana L.'],
                    'rating' => 5,
                    'originalText' => ['text' => 'Voltarei.'],
                    'relativePublishTimeDescription' => 'há 2 meses',
                ],
                [
                    'authorAttribution' => ['displayName' => 'Quarto review'],
                    'rating' => 5,
                    'text' => ['text' => 'não deve aparecer'],
                ],
            ],
        ];
    }

    public function testInvalidPlaceIdIsRejectedWithoutHttpCall(): void
    {
        $calls = 0;
        GooglePlacesService::$httpTransport = static function () use (&$calls): array {
            $calls++;
            return ['status' => 200, 'body' => '{}'];
        };

        foreach (['../admin', 'ChIJ123?key=evil', 'http://169.254.169.254', 'short', "ChIJ123\nabc", 'ChIJ123/../x'] as $bad) {
            $check = GooglePlacesService::validatePlaceId($bad);
            $this->assertFalse($check['ok'], 'should reject: ' . json_encode($bad));
            $this->assertStringContainsString('Place ID', (string) $check['error']);
        }
        $this->assertFalse(GooglePlacesService::validatePlaceId(['array'])['ok']);
        $this->assertFalse(GooglePlacesService::validatePlaceId(123456789012)['ok']);
        $this->assertFalse(GooglePlacesService::validatePlaceId(true)['ok']);

        $this->assertSame(['ok' => true, 'value' => null], GooglePlacesService::validatePlaceId(''));
        $this->assertSame(['ok' => true, 'value' => null], GooglePlacesService::validatePlaceId('   '));
        $this->assertSame(['ok' => true, 'value' => null], GooglePlacesService::validatePlaceId(null));
        $this->assertSame(['ok' => true, 'value' => self::PLACE], GooglePlacesService::validatePlaceId(' ' . self::PLACE . ' '));

        $rejected = HouseService::fromInput(1, ['google_place_id' => '../admin']);
        $this->assertArrayHasKey('error', $rejected);
        $this->assertStringContainsString('Place ID', (string) $rejected['error']);
        $this->assertSame(0, $calls, 'invalid input must never reach the Google API');
    }

    public function testValidPlaceIdUsesExactUrlAndFieldMask(): void
    {
        $seen = [];
        GooglePlacesService::$httpTransport = static function (string $url, array $headers, int $timeout) use (&$seen): array {
            $seen[] = ['url' => $url, 'headers' => $headers, 'timeout' => $timeout];
            return ['status' => 200, 'body' => json_encode([
                'rating' => 4.8,
                'userRatingCount' => 124,
                'googleMapsUri' => 'https://maps.google.com/?cid=10281119596374313554',
                'reviews' => [],
            ])];
        };

        $rep = GooglePlacesService::getReputation(self::PLACE, 'pt-BR');

        $this->assertNotNull($rep);
        $this->assertCount(1, $seen);
        $this->assertSame(
            'https://places.googleapis.com/v1/places/' . self::PLACE . '?languageCode=pt-BR',
            $seen[0]['url']
        );
        $this->assertSame('test-key', $seen[0]['headers']['X-Goog-Api-Key']);
        $this->assertSame('rating,userRatingCount,googleMapsUri,reviews', $seen[0]['headers']['X-Goog-FieldMask']);
        $this->assertSame(3, $seen[0]['timeout']);
        $this->assertSame(self::PLACE, $rep['place_id']);
        $this->assertSame(4.8, $rep['rating']);
        $this->assertSame(124, $rep['user_rating_count']);
        $this->assertSame('Powered by Google', $rep['attribution']);
        $this->assertFalse($rep['cached']);
    }

    public function testCacheHitAndExpiryAfter24Hours(): void
    {
        $now = 1700000000;
        $calls = 0;
        GooglePlacesService::$clock = static function () use (&$now): int {
            return $now;
        };
        GooglePlacesService::$httpTransport = static function () use (&$calls): array {
            $calls++;
            return ['status' => 200, 'body' => json_encode([
                'rating' => 4.8,
                'userRatingCount' => 124,
                'googleMapsUri' => 'https://maps.google.com/?cid=123',
                'reviews' => [],
            ])];
        };

        $first = GooglePlacesService::getReputation(self::PLACE, 'pt-BR');
        $this->assertNotNull($first);
        $this->assertFalse($first['cached']);
        $this->assertSame(1, $calls);

        $stored = $this->pdo->prepare('SELECT count(*) FROM google_places_cache WHERE place_id = ?');
        $stored->execute([self::PLACE]);
        $this->assertSame(1, (int) $stored->fetchColumn());

        $now += 3600;
        $second = GooglePlacesService::getReputation(self::PLACE, 'pt-BR');
        $this->assertNotNull($second);
        $this->assertTrue($second['cached']);
        $this->assertSame(4.8, $second['rating']);
        $this->assertSame(1, $calls, 'a fresh cache row must not call the upstream API');

        $now = 1700000000 + 86401;
        $third = GooglePlacesService::getReputation(self::PLACE, 'pt-BR');
        $this->assertNotNull($third);
        $this->assertFalse($third['cached']);
        $this->assertSame(2, $calls, 'an expired cache row must be refreshed upstream');
    }

    public function testPropertyWithZeroReviewsReturnsEmptyReputation(): void
    {
        GooglePlacesService::$httpTransport = static fn (): array => [
            'status' => 200,
            'body' => json_encode([
                'googleMapsUri' => 'https://maps.google.com/?cid=123',
                'userRatingCount' => 0,
            ]),
        ];

        $rep = GooglePlacesService::getReputation(self::PLACE, 'pt-BR');

        $this->assertNotNull($rep);
        $this->assertNull($rep['rating']);
        $this->assertSame(0, $rep['user_rating_count']);
        $this->assertSame([], $rep['reviews']);
        $this->assertSame('https://maps.google.com/?cid=123', $rep['google_maps_uri']);
        $this->assertSame('Powered by Google', $rep['attribution']);
    }

    public function testUpstreamTimeoutQuotaAndServerErrorsDegradeGracefully(): void
    {
        $responses = [
            ['status' => 0, 'body' => '', 'error' => 'timeout'],
            ['status' => 429, 'body' => '{}'],
            ['status' => 500, 'body' => '{}'],
        ];
        foreach ($responses as $response) {
            GooglePlacesService::$httpTransport = static fn (): array => $response;
            $this->assertNull(GooglePlacesService::getReputation(self::PLACE, 'pt-BR'));
        }

        $prop = $this->pdo->query("SELECT * FROM properties WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($prop);
        $this->pdo->prepare('UPDATE properties SET google_place_id = ? WHERE id = ?')->execute([self::PLACE, $prop['id']]);
        $prop['google_place_id'] = self::PLACE;

        GooglePlacesService::$httpTransport = static fn (): array => ['status' => 500, 'body' => '{}'];
        $listing = public_listing($prop);
        $this->assertArrayHasKey('google_reputation', $listing);
        $this->assertNull($listing['google_reputation']);
    }

    public function testMissingApiKeyMakesNoHttpCall(): void
    {
        putenv('GOOGLE_PLACES_API_KEY=');
        $calls = 0;
        GooglePlacesService::$httpTransport = static function () use (&$calls): array {
            $calls++;
            return ['status' => 200, 'body' => '{}'];
        };

        $this->assertNull(GooglePlacesService::getReputation(self::PLACE, 'pt-BR'));
        $this->assertSame(0, $calls);
    }

    public function testVerifyPlaceIdReportsErrorCodes(): void
    {
        GooglePlacesService::$httpTransport = static fn (): array => ['status' => 404, 'body' => '{}'];
        $notFound = GooglePlacesService::verifyPlaceId(self::PLACE);
        $this->assertFalse($notFound['ok']);
        $this->assertSame('not_found', $notFound['error_code']);

        GooglePlacesService::$httpTransport = static fn (): array => ['status' => 429, 'body' => '{}'];
        $quota = GooglePlacesService::verifyPlaceId(self::PLACE);
        $this->assertFalse($quota['ok']);
        $this->assertSame('quota_exceeded', $quota['error_code']);

        putenv('GOOGLE_PLACES_API_KEY=');
        $missing = GooglePlacesService::verifyPlaceId(self::PLACE);
        $this->assertFalse($missing['ok']);
        $this->assertSame('missing_api_key', $missing['error_code']);

        $invalid = GooglePlacesService::verifyPlaceId('../admin');
        $this->assertFalse($invalid['ok']);
        $this->assertSame('invalid_place_id', $invalid['error_code']);
    }

    public function testPlaceIdChangeIsAuditedAndInvalidatesCache(): void
    {
        $calls = 0;
        GooglePlacesService::$httpTransport = static function () use (&$calls): array {
            $calls++;
            return ['status' => 200, 'body' => json_encode([
                'rating' => 4.5,
                'userRatingCount' => 10,
                'googleMapsUri' => 'https://maps.google.com/?cid=999',
                'reviews' => [],
            ])];
        };
        $this->assertNotNull(GooglePlacesService::getReputation(self::PLACE, 'pt-BR'));
        $this->assertSame(1, $calls);

        $row = $this->pdo->query(
            "SELECT p.* FROM properties p JOIN ceps c ON c.cep = p.cep
              WHERE p.status = 'active' AND c.street <> '' AND p.address_number <> ''
              ORDER BY p.id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($row, 'seed database needs one active property with a known CEP');

        $propertyId = (int) $row['id'];
        $this->pdo->prepare('UPDATE properties SET google_place_id = ? WHERE id = ?')
            ->execute([self::PLACE, $propertyId]);

        $input = [
            'title' => (string) $row['title'],
            'description' => (string) $row['description'],
            'cep' => (string) $row['cep'],
            'address_number' => (string) $row['address_number'],
            'max_guests' => (string) $row['max_guests'],
            'nightly_price' => (string) $row['nightly_price'],
            'bedrooms' => (string) $row['bedrooms'],
            'beds' => (string) $row['beds'],
            'google_place_id' => self::PLACE_NEW,
        ];

        $res = HouseService::update((int) $row['user_id'], $propertyId, $input);
        $this->assertArrayNotHasKey('error', $res);
        $this->assertSame(self::PLACE, $res['anterior']['google_place_id']);
        $this->assertSame(self::PLACE_NEW, $res['atual']['google_place_id']);

        $cached = $this->pdo->prepare('SELECT count(*) FROM google_places_cache WHERE place_id = ?');
        $cached->execute([self::PLACE]);
        $this->assertSame(0, (int) $cached->fetchColumn(), 'changing the Place ID must evict the old cache row');

        $stored = $this->pdo->prepare('SELECT google_place_id FROM properties WHERE id = ?');
        $stored->execute([$propertyId]);
        $this->assertSame(self::PLACE_NEW, (string) $stored->fetchColumn());
    }

    public function testVerifyActionRejectsAndMapsErrors(): void
    {
        $invalid = GooglePlaceVerify::run(1, '../admin', 'pt-BR');
        $this->assertSame('invalido', $invalid->tipo);
        $this->assertNotSame('', (string) $invalid->mensagem);

        GooglePlacesService::$httpTransport = static fn (): array => ['status' => 200, 'body' => json_encode([
            'rating' => 4.9,
            'userRatingCount' => 30,
            'googleMapsUri' => 'https://maps.google.com/?cid=777',
            'reviews' => [],
        ])];
        $ok = GooglePlaceVerify::run(1, self::PLACE, 'pt-BR');
        $this->assertSame('ok', $ok->tipo);
        $this->assertSame(4.9, $ok->dados['google_reputation']['rating']);

        GooglePlacesService::$httpTransport = static fn (): array => ['status' => 500, 'body' => '{}'];
        $down = GooglePlaceVerify::run(1, self::PLACE_NEW, 'pt-BR');
        $this->assertSame('indisponivel', $down->tipo);
    }
}

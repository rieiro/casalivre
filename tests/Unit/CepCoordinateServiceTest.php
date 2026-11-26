<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Db.php';
require_once dirname(__DIR__, 2) . '/src/CepCoordinateService.php';
require_once dirname(__DIR__, 2) . '/src/CepRepository.php';
require_once dirname(__DIR__, 2) . '/src/CityRepository.php';

final class CepCoordinateServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        putenv('APP_ENV=testing');
        Db::loadEnv();
        $this->pdo = db();
        $this->pdo->beginTransaction();
        CepRepository::ensureTable();
        CepCoordinateService::$httpTransport = null;
    }

    protected function tearDown(): void
    {
        CepCoordinateService::$httpTransport = null;
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testResolveUsesCachedCoordinatesDirectlyWithoutHttp(): void
    {
        $httpCalled = false;
        CepCoordinateService::$httpTransport = function () use (&$httpCalled): array {
            $httpCalled = true;
            return ['status' => 500, 'body' => ''];
        };

        $result = CepCoordinateService::resolve(
            '88010000',
            'Florianópolis',
            'SC',
            -27.59418,
            -48.55587
        );

        self::assertFalse($httpCalled, 'Should not make HTTP requests when coordinates are already cached');
        self::assertSame('cep', $result['source']);
        self::assertEqualsWithDelta(-27.59418, $result['lat'], 0.00001);
        self::assertEqualsWithDelta(-48.55587, $result['lng'], 0.00001);
    }

    public function testResolveUsesAwesomeApiAndPersistsToDatabase(): void
    {
        $requestedUrls = [];
        CepCoordinateService::$httpTransport = function (string $url) use (&$requestedUrls): array {
            $requestedUrls[] = $url;
            return [
                'status' => 200,
                'body' => json_encode([
                    'cep' => '89010025',
                    'address' => 'Rua Doutor Luiz de Freitas Melro',
                    'lat' => '-26.9249002',
                    'lng' => '-49.0611365',
                ], JSON_THROW_ON_ERROR),
            ];
        };

        // Insert dummy row in ceps table to test persistence
        $this->pdo->prepare('INSERT INTO ceps (cep, street, neighborhood, city, uf) VALUES (?, ?, ?, ?, ?) ON CONFLICT (cep) DO UPDATE SET lat = NULL, lng = NULL')
            ->execute(['89010025', 'Rua Doutor Luiz de Freitas Melro', 'Centro', 'Blumenau', 'SC']);

        $result = CepCoordinateService::resolve('89010025', 'Blumenau', 'SC');

        self::assertSame('cep', $result['source']);
        self::assertEqualsWithDelta(-26.9249002, $result['lat'], 0.00001);
        self::assertEqualsWithDelta(-49.0611365, $result['lng'], 0.00001);
        self::assertNotEmpty($requestedUrls);
        self::assertStringContainsString('awesomeapi', $requestedUrls[0]);

        // Verify DB row was updated with coordinates
        $row = CepRepository::find('89010025');
        self::assertNotNull($row);
        self::assertNotNull($row['lat']);
        self::assertEqualsWithDelta(-26.9249002, (float) $row['lat'], 0.00001);
        self::assertEqualsWithDelta(-49.0611365, (float) $row['lng'], 0.00001);
    }

    public function testResolveFallsBackToBrasilApiWhenAwesomeApiFails(): void
    {
        CepCoordinateService::$httpTransport = function (string $url): array {
            if (str_contains($url, 'awesomeapi')) {
                return ['status' => 404, 'body' => '{"error":"not_found"}'];
            }
            if (str_contains($url, 'brasilapi')) {
                return [
                    'status' => 200,
                    'body' => json_encode([
                        'cep' => '88010000',
                        'location' => [
                            'type' => 'Point',
                            'coordinates' => [
                                'latitude' => '-27.59667',
                                'longitude' => '-48.54917',
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ];
            }
            return ['status' => 500, 'body' => ''];
        };

        $result = CepCoordinateService::resolve('88010000', 'Florianópolis', 'SC');

        self::assertSame('cep', $result['source']);
        self::assertEqualsWithDelta(-27.59667, $result['lat'], 0.00001);
        self::assertEqualsWithDelta(-48.54917, $result['lng'], 0.00001);
    }

    public function testResolveRejectsOutOfBoundsCoordinatesAndFallsBackToCity(): void
    {
        CepCoordinateService::$httpTransport = function (): array {
            return [
                'status' => 200,
                'body' => json_encode([
                    'lat' => '52.5200', // Berlin, outside Brazil
                    'lng' => '13.4050',
                ], JSON_THROW_ON_ERROR),
            ];
        };

        $result = CepCoordinateService::resolve('01001000', 'São Paulo', 'SP');

        self::assertSame('city', $result['source']);
        // São Paulo city centroid
        self::assertEqualsWithDelta(-23.5505, $result['lat'], 0.1);
        self::assertEqualsWithDelta(-46.6333, $result['lng'], 0.1);
    }

    public function testResolveFallsBackToCityCentroidWhenAllProvidersFail(): void
    {
        CepCoordinateService::$httpTransport = function (): array {
            return ['status' => 500, 'body' => 'Network error'];
        };

        $result = CepCoordinateService::resolve('88010000', 'Florianópolis', 'SC');

        self::assertSame('city', $result['source']);
        self::assertNotNull($result['lat']);
        self::assertNotNull($result['lng']);
        self::assertEqualsWithDelta(-27.5954, $result['lat'], 0.05);
        self::assertEqualsWithDelta(-48.5480, $result['lng'], 0.05);
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Db.php';
require_once dirname(__DIR__, 2) . '/conta/Geo/CityRepository.php';
require_once dirname(__DIR__, 2) . '/src/SearchQuery.php';
require_once dirname(__DIR__, 2) . '/src/SearchSql.php';
require_once dirname(__DIR__, 2) . '/src/SearchService.php';
require_once dirname(__DIR__, 2) . '/src/HouseRepository.php';
require_once dirname(__DIR__, 2) . '/src/helpers.php';

final class SearchMapPositionTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        putenv('APP_ENV=testing');
        Db::loadEnv();
        $this->pdo = db();
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testSearchMapItemsUseExactLatLongCoordinates(): void
    {
        $realLat = -27.596085;
        $realLng = -48.552866;

        $rawItem = [
            'id' => 99999,
            'public_hash' => '0123456789abcdef',
            'title' => 'Apartamento Centro',
            'city' => 'Florianópolis, SC',
            'nightly_price' => 250,
            'photo' => '/uploads/test.jpg',
            'score' => 9.2,
            'quote' => null,
            'lat' => $realLat,
            'lng' => $realLng,
        ];

        $items = SearchService::formatMapItems([$rawItem]);

        $this->assertCount(1, $items);
        $mapped = $items[0];

        // Must match exact property lat and long (not displaced by public_coord offset)
        $this->assertSame($realLat, $mapped['lat']);
        $this->assertSame($realLng, $mapped['lng']);
        $this->assertSame($realLng, $mapped['long']);

        // Verify distance from real coordinates is 0 (not >= 300m like public_coord)
        $offsetMeters = haversine_km($realLat, $realLng, (float) $mapped['lat'], (float) $mapped['lng']) * 1000;
        $this->assertEqualsWithDelta(0.0, $offsetMeters, 0.001);
    }

    public function testSearchMapDataToGeoJsonPositionsPinsAtLongLat(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('Node is not installed.');
        }

        $items = [
            [
                'id' => '0123456789abcdef',
                'title' => 'Casa Beira Mar',
                'lat' => -27.596085,
                'lng' => -48.552866,
                'long' => -48.552866,
                'nightly_price' => 300,
            ],
            [
                'id' => 'fedcba9876543210',
                'title' => 'Loft Design',
                'lat' => -23.55052,
                'long' => -46.633308,
                'nightly_price' => 450,
            ],
        ];

        $mapDataPath = dirname(__DIR__, 2) . '/public/assets/pages/map-data.js';
        $script = 'const m = require(' . json_encode($mapDataPath) . ');'
            . 'const fc = m.toGeoJSON(' . json_encode($items) . ', "localidade=Florian%C3%B3polis");'
            . 'process.stdout.write(JSON.stringify(fc));';

        $output = (string) shell_exec(escapeshellarg($node) . ' -e ' . escapeshellarg($script));
        $fc = json_decode($output, true);

        $this->assertIsArray($fc);
        $this->assertSame('FeatureCollection', $fc['type'] ?? null);
        $features = $fc['features'] ?? [];
        $this->assertCount(2, $features);

        // Feature 0: coordinates must be [long, lat]
        $this->assertEqualsWithDelta(-48.552866, $features[0]['geometry']['coordinates'][0], 0.000001);
        $this->assertEqualsWithDelta(-27.596085, $features[0]['geometry']['coordinates'][1], 0.000001);

        // Feature 1: supports item.long when lng is absent
        $this->assertEqualsWithDelta(-46.633308, $features[1]['geometry']['coordinates'][0], 0.000001);
        $this->assertEqualsWithDelta(-23.55052, $features[1]['geometry']['coordinates'][1], 0.000001);
    }
}

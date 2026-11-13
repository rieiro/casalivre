<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Db.php';
require_once dirname(__DIR__, 2) . '/src/I18n.php';
require_once dirname(__DIR__, 2) . '/src/Settings.php';
require_once dirname(__DIR__, 2) . '/src/Rooms.php';
require_once dirname(__DIR__, 2) . '/src/Amenities.php';
require_once dirname(__DIR__, 2) . '/src/CityRepository.php';
require_once dirname(__DIR__, 2) . '/src/CepRepository.php';
require_once dirname(__DIR__, 2) . '/src/HouseRepository.php';
require_once dirname(__DIR__, 2) . '/src/ImageWorker.php';
require_once dirname(__DIR__, 2) . '/src/helpers.php';

final class PropertyFormPageTest extends TestCase
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

    public function testPropertyFormRendersLuxuryHeaderAndCardsForNewProperty(): void
    {
        ob_start();
        try {
            $property = null;
            $error = null;
            $posted = [];
            $csrf = 'csrf_token_test';
            $photos = [];
            require dirname(__DIR__, 2) . '/views/property_form.php';
            $html = (string) ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        // Must display luxury header
        $this->assertStringContainsString('prop-form-page', $html);
        $this->assertStringContainsString('prop-form-header', $html);
        $this->assertStringContainsString('/painel', $html);
        $this->assertStringContainsString('Voltar ao Painel', $html);

        // Form id and attributes required by JavaScript
        $this->assertStringContainsString('id="property-form"', $html);
        $this->assertStringContainsString('data-editing="0"', $html);

        // Section cards
        $this->assertStringContainsString('prop-form-card', $html);

        // Core fields
        $this->assertStringContainsString('name="title"', $html);
        $this->assertStringContainsString('name="description"', $html);
        $this->assertStringContainsString('name="cep"', $html);
        $this->assertStringContainsString('name="nightly_price"', $html);
        $this->assertStringContainsString('name="pix_key"', $html);
    }

    public function testPropertyFormRendersEditModeWithPhotos(): void
    {
        $prop = $this->pdo->query("SELECT * FROM properties WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($prop);

        ob_start();
        try {
            $property = $prop;
            $error = null;
            $posted = [];
            $csrf = 'csrf_token_test';
            $photos = [
                ['id' => 101, 'path' => '/uploads/fotos/test.webp', 'status' => 'ready', 'is_primary' => 1]
            ];
            require dirname(__DIR__, 2) . '/views/property_form.php';
            $html = (string) ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        $this->assertStringContainsString('data-editing="1"', $html);
        $this->assertStringContainsString('prop-photos-card', $html);
        $this->assertStringContainsString('prop-form-danger-zone', $html);
        $this->assertStringContainsString($prop['title'], $html);
    }

    public function testPropertyFormRendersMapPinpointSectionAndInputs(): void
    {
        ob_start();
        try {
            $property = null;
            $error = null;
            $posted = [];
            $csrf = 'csrf_token_test';
            $photos = [];
            require dirname(__DIR__, 2) . '/views/property_form.php';
            $html = (string) ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        // Section & container
        $this->assertStringContainsString('prop-pinpoint-map-section', $html);
        $this->assertStringContainsString('id="property-pinpoint-map"', $html);

        // Inputs for lat and lng
        $this->assertStringContainsString('name="lat"', $html);
        $this->assertStringContainsString('name="lng"', $html);
        $this->assertStringContainsString('id="field-lat"', $html);
        $this->assertStringContainsString('id="field-lng"', $html);

        // MapLibre and PMTiles dependencies
        $this->assertStringContainsString('maplibre-gl.css', $html);
        $this->assertStringContainsString('maplibre-gl-csp.js', $html);
        $this->assertStringContainsString('pmtiles.js', $html);
    }

    public function testPropertyFormEditModePrePopulatesExactCoordinates(): void
    {
        $prop = $this->pdo->query("SELECT * FROM properties WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($prop);

        ob_start();
        try {
            $property = $prop;
            $error = null;
            $posted = [];
            $csrf = 'csrf_token_test';
            $photos = [];
            require dirname(__DIR__, 2) . '/views/property_form.php';
            $html = (string) ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        $expectedLat = (string) (float) $prop['lat'];
        $expectedLng = (string) (float) $prop['lng'];

        $this->assertStringContainsString('value="' . $expectedLat . '"', $html);
        $this->assertStringContainsString('value="' . $expectedLng . '"', $html);
        $this->assertStringContainsString('data-lat="' . $expectedLat . '"', $html);
        $this->assertStringContainsString('data-lng="' . $expectedLng . '"', $html);
    }

    public function testHouseServiceFromInputStoresExactCustomPinpointedCoordinates(): void
    {
        require_once dirname(__DIR__, 2) . '/src/HouseService.php';

        $cepRow = $this->pdo->query("SELECT cep FROM ceps WHERE street != '' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($cepRow);

        $input = [
            'title' => 'Chalé da Colina',
            'description' => 'Chalé charmoso com vista para o vale.',
            'cep' => $cepRow['cep'],
            'address_number' => '120',
            'max_guests' => '4',
            'nightly_price' => '350',
            'bedrooms' => '2',
            'beds' => '2',
            'lat' => '-27.581234',
            'lng' => '-48.512345',
        ];

        $res = HouseService::fromInput(1, $input);
        $this->assertArrayNotHasKey('error', $res);
        $this->assertEqualsWithDelta(-27.581234, $res['lat'], 0.000001);
        $this->assertEqualsWithDelta(-48.512345, $res['lng'], 0.000001);
    }

    public function testPropertyFormJsDispatchesInputEventsOnCoordChange(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/pages/property-form.js');
        $this->assertStringContainsString("fieldLat.dispatchEvent(new Event('input', { bubbles: true }));", $js);
        $this->assertStringContainsString("fieldLng.dispatchEvent(new Event('input', { bubbles: true }));", $js);
        $this->assertStringContainsString("fieldLat.dispatchEvent(new Event('change', { bubbles: true }));", $js);
        $this->assertStringContainsString("fieldLng.dispatchEvent(new Event('change', { bubbles: true }));", $js);
    }
}

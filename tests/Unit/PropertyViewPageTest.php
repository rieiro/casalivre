<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Db.php';
require_once dirname(__DIR__, 2) . '/src/I18n.php';
require_once dirname(__DIR__, 2) . '/src/HouseRepository.php';
require_once dirname(__DIR__, 2) . '/src/ImageWorker.php';
require_once dirname(__DIR__, 2) . '/src/helpers.php';
require_once dirname(__DIR__, 2) . '/src/Amenities.php';

final class PropertyViewPageTest extends TestCase
{
    public function testCalendarEditPageHidesOldTextNotification(): void
    {
        $property = [
            'id' => 1,
            'title' => 'Casa Teste',
            'hash' => 'abcdef0123456789',
            'calendar_horizon_months' => 3,
            'nightly_price' => 250,
            'min_nights' => 2,
        ];
        $days = [];
        $feeds = [];
        $message = 'Alterações salvas no calendário.';
        $message_tone = 'ok';
        $csrf = 'test_csrf';

        ob_start();
        try {
            require dirname(__DIR__, 2) . '/views/calendar.php';
        } finally {
            $html = (string) ob_get_clean();
        }

        // Old visible text banner must be removed (must be hidden element for toast trigger)
        $this->assertStringNotContainsString('<p class="alert ok">', $html);
        $this->assertStringContainsString('hidden data-flash-alert', $html);
    }

    public function testSafeDomProvidesLuxuryConfirmModal(): void
    {
        $safeDom = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/safe-dom.js');
        $this->assertStringContainsString('confirm(opts)', $safeDom);
        $this->assertStringContainsString('cl-modal-overlay', $safeDom);
    }

    public function testPropertyAndAccountEditorsUseLuxuryConfirmForDeletion(): void
    {
        $propJs = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/pages/property-form.js');
        $accountJs = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/pages/account.js');

        $this->assertStringContainsString('CL.confirm', $propJs);
        $this->assertStringContainsString('CL.confirm', $accountJs);
        $this->assertStringNotContainsString('confirm(CL.data().deleteConfirm)', $accountJs);
    }

    public function testPropertyViewRendersLuxuryLandingLayout(): void
    {
        $property = [
            'id' => 1,
            'user_id' => 1,
            'title' => 'Vila Marítima de Luxo',
            'description' => 'Espetacular residência com vista panorâmica para o oceano.',
            'city' => 'Florianópolis',
            'street' => 'Av. Beira Mar',
            'address_number' => '500',
            'lat' => -27.5954,
            'lng' => -48.5480,
            'bedrooms' => 4,
            'beds' => 6,
            'rooms' => 5,
            'max_guests' => 8,
            'nightly_price' => 120000,
            'cleaning_fee' => 25000,
            'checkin_time' => '15:00:00',
            'checkout_time' => '11:00:00',
            'min_nights' => 3,
            'amenities' => json_encode(['wifi' => 1, 'pool' => 1, 'bbq' => 1, 'sea_view' => 1, 'noise' => 1, 'noise_from' => '22:00', 'noise_until' => '07:00']),
            'public_hash' => 'abcdef0123456789',
            'rating_count' => 12,
            'rating_sum' => 118,
            'status' => 'active',
        ];

        $photos = [
            ['id' => 10, 'property_id' => 1, 'path' => '/uploads/fotos/foto1.jpg', 'is_cover' => true, 'position' => 1],
            ['id' => 11, 'property_id' => 1, 'path' => '/uploads/fotos/foto2.jpg', 'is_cover' => false, 'position' => 2],
        ];

        $score = 9.8;
        $quote = null;
        $use_api = false;
        $csrf = 'test_token';

        ob_start();
        try {
            require dirname(__DIR__, 2) . '/views/property.php';
        } finally {
            $html = (string) ob_get_clean();
        }

        // Must retain #property-detail and h1 for existing contracts
        $this->assertStringContainsString('id="property-detail"', $html);
        $this->assertStringContainsString('<h1 class="prop-view-title">Vila Marítima de Luxo</h1>', $html);

        // Luxury styling classes and structure
        $this->assertStringContainsString('prop-view-', $html);
        $this->assertStringContainsString('prop-view-booking-card', $html);
        $this->assertStringContainsString('prop-view-mosaic', $html);
        $this->assertStringContainsString('prop-view-amenities', $html);
        $this->assertStringContainsString('Lei do Silêncio', $html);
    }

    public function testPropertyScriptBuildsLuxuryLandingLayout(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/pages/property.js');
        $api = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Api.php');

        // Api must expose noise hours for quiet rules
        $this->assertStringContainsString("'noise_from' =>", $api);
        $this->assertStringContainsString("'noise_until' =>", $api);

        // property.js must construct luxury landing page elements
        $this->assertStringContainsString('prop-view-mosaic', $js);
        $this->assertStringContainsString('prop-view-booking-card', $js);
        $this->assertStringContainsString('prop-view-amenities', $js);
        $this->assertStringContainsString('Lei do Silêncio', $js);
    }

    public function testPropertyViewDoesNotContainReviewOfStays(): void
    {
        $property = [
            'id' => 1,
            'user_id' => 1,
            'title' => 'Casa Teste',
            'description' => 'Descrição',
            'city' => 'Florianópolis',
            'bedrooms' => 2,
            'beds' => 2,
            'rooms' => 3,
            'max_guests' => 4,
            'nightly_price' => 20000,
            'cleaning_fee' => 5000,
            'checkin_time' => '14:00:00',
            'checkout_time' => '11:00:00',
            'min_nights' => 1,
            'amenities' => '{}',
            'public_hash' => 'abcdef0123456789',
            'rating_count' => 0,
            'rating_sum' => 0,
            'status' => 'active',
        ];
        $photos = [];
        $score = null;
        $quote = null;
        $use_api = false;
        $csrf = 'token';

        ob_start();
        try {
            require dirname(__DIR__, 2) . '/views/property.php';
        } finally {
            $html = (string) ob_get_clean();
        }

        $this->assertStringNotContainsString('Avaliação da estadia', $html);
        $this->assertStringNotContainsString('id="review-form"', $html);
        $this->assertStringNotContainsString('id="review-card"', $html);

        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/pages/property.js');
        $this->assertStringNotContainsString('Avaliação da estadia', $js);
        $this->assertStringNotContainsString('review-form', $js);
        $this->assertStringNotContainsString('review-card', $js);
    }
}

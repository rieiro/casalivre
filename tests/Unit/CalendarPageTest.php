<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Db.php';
require_once dirname(__DIR__, 2) . '/src/I18n.php';
require_once dirname(__DIR__, 2) . '/src/HouseRepository.php';
require_once dirname(__DIR__, 2) . '/src/helpers.php';

final class CalendarPageTest extends TestCase
{
    public function testCalendarViewRendersFlashAlertDataAttributes(): void
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
            $html = (string) ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        $this->assertStringContainsString('data-flash-alert', $html);
        $this->assertStringContainsString('data-flash-tone="ok"', $html);
        $this->assertStringContainsString('Alterações salvas no calendário.', $html);
    }

    public function testCalendarViewHandlesMultipleMessageTypesAndTones(): void
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
        $csrf = 'test_csrf';

        // Error tone test
        $message = 'Data indisponível: 2026-12-25';
        $message_tone = 'error';

        ob_start();
        try {
            require dirname(__DIR__, 2) . '/views/calendar.php';
        } finally {
            $html = (string) ob_get_clean();
        }

        $this->assertStringContainsString('data-flash-alert', $html);
        $this->assertStringContainsString('data-flash-tone="error"', $html);
        $this->assertStringContainsString('Data indisponível: 2026-12-25', $html);

        // Multiple messages array test
        $message = [
            'Sincronização iniciada.',
            'Alterações salvas no calendário.',
        ];
        $message_tone = 'ok';

        ob_start();
        try {
            require dirname(__DIR__, 2) . '/views/calendar.php';
        } finally {
            $htmlMulti = (string) ob_get_clean();
        }

        $this->assertStringContainsString('Sincronização iniciada.', $htmlMulti);
        $this->assertStringContainsString('Alterações salvas no calendário.', $htmlMulti);
        $this->assertSame(2, substr_count($htmlMulti, 'data-flash-alert'));
    }

    public function testCalendarScriptUsesLuxuryToastNotification(): void
    {
        $calendarJs = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/pages/calendar-3.js');
        $safeDom = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/safe-dom.js');

        // safe-dom.js must provide CL.toast
        $this->assertStringContainsString('toast(type, title, message)', $safeDom);

        // calendar-3.js must trigger CL.toast for flash notifications and error handling
        $this->assertStringContainsString('data-flash-alert', $calendarJs);
        $this->assertStringContainsString('CL.toast', $calendarJs);
    }
public function testCalendarViewRendersSideBySidePriceAndMinNightsWithShortenedLabels(): void
    {
        $property = [
            "id" => 1,
            "title" => "Casa Teste",
            "hash" => "abcdef0123456789",
            "calendar_horizon_months" => 3,
            "nightly_price" => 250,
            "min_nights" => 2,
        ];
        $days = [];
        $feeds = [];
        $message = null;
        $message_tone = null;
        $csrf = "test_csrf";

        ob_start();
        try {
            require dirname(__DIR__, 2) . "/views/calendar.php";
            $html = (string) ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        // Must have .cal-fields container wrapping the side-by-side inputs
        $this->assertStringContainsString("class=\"cal-fields\"", $html);

        // Price input must come BEFORE min_nights input (night price on left, min nights on right)
        $pricePos = strpos($html, "name=\"price\"");
        $minNightsPos = strpos($html, "name=\"min_nights\"");
        $this->assertNotFalse($pricePos, "price input must exist");
        $this->assertNotFalse($minNightsPos, "min_nights input must exist");
        $this->assertLessThan($minNightsPos, $pricePos, "night price must appear before min nights");

        // Shortened labels & placeholders for calendar screen
        $this->assertSame("Diária", t("cal.price"));
        $this->assertSame("base", t("cal.price_hint"));
        $this->assertSame("Mín. noites", t("cal.min_nights"));
        $this->assertSame("Mín. de %s", t("cal.min_from"));
        $this->assertSame("Mín. %s a %s", t("cal.min_range"));

        // form.min_nights must remain unchanged for other screens
        $this->assertSame("Mínimo de noites", t("form.min_nights"));

        // Rendered view must use the shortened strings
        $this->assertStringContainsString("data-min-default=\"" . t("cal.min_nights") . "\"", $html);
        $this->assertStringContainsString("placeholder=\"" . t("cal.price_hint") . "\"", $html);
        $this->assertStringContainsString("data-min-label>" . t("cal.min_nights") . "<", $html);
    }
}

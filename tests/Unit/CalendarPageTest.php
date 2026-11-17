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
public function testCalendarViewRendersSideBySideActionButtonsWithShortenedLabels(): void
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

        // Must have .cal-actions container wrapping the action buttons
        $this->assertStringContainsString("class=\"cal-actions\"", $html);

        // Order must be Block, Open, Save (left to right)
        $blockPos = strpos($html, "data-cal-block");
        $openPos = strpos($html, "data-cal-open");
        $savePos = strpos($html, "data-cal-save");
        $this->assertNotFalse($blockPos, "block button must exist");
        $this->assertNotFalse($openPos, "open button must exist");
        $this->assertNotFalse($savePos, "save button must exist");
        $this->assertLessThan($openPos, $blockPos, "Block button must be before Open button");
        $this->assertLessThan($savePos, $openPos, "Open button must be before Save button");

        // Translations shortened to Block, Open, Save
        $this->assertSame("Bloquear", t("cal.block"));
        $this->assertSame("Abrir", t("cal.open_action"));
        $this->assertSame("Salvar", t("cal.save_changes"));

        // Rendered buttons must display the shortened text
        $this->assertStringContainsString("data-cal-block disabled>" . t("cal.block") . "<", $html);
        $this->assertStringContainsString("data-cal-open disabled>" . t("cal.open_action") . "<", $html);
        $this->assertStringContainsString("data-cal-save disabled>" . t("cal.save_changes") . "<", $html);
    }
public function testCalendarMobileResponsiveCompactLayout(): void
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

        // View must tag sync-locked warning with cal-sync-locked class for media query target
        $this->assertStringContainsString("cal-sync-locked", $html);

        $css = (string) file_get_contents(dirname(__DIR__, 2) . "/public/assets/app.css");

        // Legend must be hidden on mobile
        $this->assertMatchesRegularExpression("/@media\\s*\\(max-width:\\s*800px\\).*?\\.cal-legend\\s*\\{\\s*display:\\s*none;/s", $css);

        // Sync locked note must be hidden on mobile
        $this->assertMatchesRegularExpression("/@media\\s*\\(max-width:\\s*800px\\).*?\\.cal-sync-locked\\s*\\{\\s*display:\\s*none;/s", $css);

        // Calendar section must have compact mobile rules (e.g. smaller cal-day, cal-months)
        $this->assertMatchesRegularExpression("/@media\\s*\\(max-width:\\s*800px\\).*?\\.cal-day\\s*\\{\\s*min-height:\\s*3[0-9]px;/s", $css);

        // Sync board / sync table and add feed form must have mobile compact sizing
        $this->assertMatchesRegularExpression("/@media\\s*\\(max-width:\\s*800px\\).*?data-feed-form/s", $css);
        $this->assertMatchesRegularExpression("/@media\\s*\\(max-width:\\s*800px\\).*?\\.sync-table/s", $css);
    }
public function testCalendarViewRendersSelectionPricingBreakdownTable(): void
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

        // Must have breakdown table container hidden by default
        $this->assertStringContainsString("data-cal-summary-table", $html);
        $this->assertStringContainsString("cal-summary-table", $html);

        // Position: must be below cal-fields and before cal-actions
        $fieldsPos = strpos($html, "class=\"cal-fields\"");
        $tablePos = strpos($html, "data-cal-summary-table");
        $actionsPos = strpos($html, "class=\"cal-actions\"");
        $this->assertNotFalse($fieldsPos);
        $this->assertNotFalse($tablePos);
        $this->assertNotFalse($actionsPos);
        $this->assertLessThan($tablePos, $fieldsPos, "summary table must be below cal-fields");
        $this->assertLessThan($actionsPos, $tablePos, "summary table must be above cal-actions");

        // Translations for new text fields
        $this->assertSame("Taxas", t("cal.taxes"));
        $this->assertSame("Total", t("cal.total"));

        // Table elements
        $this->assertStringContainsString("data-cal-stay-formula", $html);
        $this->assertStringContainsString("data-cal-stay-total", $html);
        $this->assertStringContainsString("data-cal-taxes-val", $html);
        $this->assertStringContainsString("data-cal-grand-total", $html);

        // Rendered view includes the labels
        $this->assertStringContainsString(t("cal.taxes"), $html);
        $this->assertStringContainsString(t("cal.total"), $html);

        // Scripts must have dynamic calculation logic
        $js = (string) file_get_contents(dirname(__DIR__, 2) . "/public/assets/pages/calendar-2.js");
        $this->assertStringContainsString("data-cal-summary-table", $js);
        $this->assertStringContainsString("data-cal-stay-formula", $js);
        $this->assertStringContainsString("data-cal-stay-total", $js);
        $this->assertStringContainsString("data-cal-taxes-val", $js);
        $this->assertStringContainsString("data-cal-grand-total", $js);

        // CSS styles
        $css = (string) file_get_contents(dirname(__DIR__, 2) . "/public/assets/app.css");
        $this->assertStringContainsString(".cal-summary-table", $css);
        $this->assertStringContainsString(".cal-summary-row", $css);
    }

    public function testCalendarMobileHeaderAndControlsLayout(): void
    {
        $property = [
            "id" => 1,
            "title" => "Chalé 1",
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

        // 1. Calendar title heading must have cal-title class for mobile hiding
        $this->assertMatchesRegularExpression('/<h1[^>]*class="[^"]*cal-title[^"]*"[^>]*>/', $html);

        // 2. View switcher controls (.cal-view with data-cal-view buttons) must be above .cal-fields
        $viewPos = strpos($html, 'class="cal-view"');
        $fieldsPos = strpos($html, 'class="cal-fields"');
        $this->assertNotFalse($viewPos);
        $this->assertNotFalse($fieldsPos);
        $this->assertLessThan($fieldsPos, $viewPos, ".cal-view must be above .cal-fields");

        // 3. CSS rules under @media (max-width: 800px)
        $css = (string) file_get_contents(dirname(__DIR__, 2) . "/public/assets/app.css");

        // Top horizontal element (header.top) removed on mobile for calendar
        $this->assertMatchesRegularExpression('/@media\s*\(max-width:\s*800px\).*?(?:body\.page-calendar|body:has\(\.cal-board\))\s*header\.top\s*\{\s*display:\s*none;/s', $css);

        // 'Calendar - Chalé 1' (.cal-title) removed on mobile
        $this->assertMatchesRegularExpression('/@media\s*\(max-width:\s*800px\).*?\.cal-title\s*\{\s*display:\s*none;/s', $css);

        // Range date visual (.cal-summary) removed on mobile
        $this->assertMatchesRegularExpression('/@media\s*\(max-width:\s*800px\).*?\.cal-summary\s*\{\s*display:\s*none;/s', $css);

        // .cal-view must NOT be display: none on mobile
        $this->assertDoesNotMatchRegularExpression('/@media\s*\(max-width:\s*800px\).*?\.cal-view\s*\{\s*display:\s*none;/s', $css);

        // 4. JS logic must apply view mode on mobile as well (not force 'day' on !desktop.matches)
        $js = (string) file_get_contents(dirname(__DIR__, 2) . "/public/assets/pages/calendar-2.js");
        $this->assertStringNotContainsString("desktop.matches ? viewMode : 'day'", $js);
        $this->assertStringContainsString("const mode = viewMode;", $js);
    }

    public function testCalendarViewIncludesCleaningFeeAsTaxes(): void
    {
        $property = [
            "id" => 1,
            "title" => "Casa Teste",
            "hash" => "abcdef0123456789",
            "calendar_horizon_months" => 3,
            "nightly_price" => 250,
            "min_nights" => 2,
            "cleaning_fee" => 213,
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

        // Must pass cleaning fee on form dataset
        $this->assertStringContainsString('data-cleaning-fee="213"', $html);

        // JS must use cleaningFee in taxes calculation
        $js = (string) file_get_contents(dirname(__DIR__, 2) . "/public/assets/pages/calendar-2.js");
        $this->assertStringContainsString("cleaningFee", $js);
        $this->assertStringContainsString("form.dataset.cleaningFee", $js);
    }
}

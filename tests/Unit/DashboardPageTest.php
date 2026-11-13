<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Db.php';
require_once dirname(__DIR__, 2) . '/src/I18n.php';
require_once dirname(__DIR__, 2) . '/src/Settings.php';
require_once dirname(__DIR__, 2) . '/src/QuoteRepository.php';
require_once dirname(__DIR__, 2) . '/src/HouseRepository.php';
require_once dirname(__DIR__, 2) . '/src/PhotoRepository.php';
require_once dirname(__DIR__, 2) . '/src/helpers.php';

final class DashboardPageTest extends TestCase
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

    public function testDashboardViewRendersLuxuryHeroHeaderAndQuickActions(): void
    {
        ob_start();
        $properties = [];
        $use_api = true;
        $quotes_count = 5;
        $pending_count = 2;
        $csrf = 'csrf_token_test';
        require dirname(__DIR__, 2) . '/views/dashboard.php';
        $html = (string) ob_get_clean();

        // Must display luxury dashboard header
        $this->assertStringContainsString('dash-header', $html);
        $this->assertStringContainsString('dash-header-title', $html);
        $this->assertStringContainsString('btn-dash-primary', $html);
        $this->assertStringContainsString('/imoveis/novo', $html);
        $this->assertStringContainsString('/reservas', $html);
        $this->assertStringContainsString('dash-stats-bar', $html);
        $this->assertStringContainsString('dash-stat-item', $html);
        $this->assertStringContainsString('painel-loading', $html);
    }

    public function testDashboardViewRendersServerSideCardsWhenNotUsingApi(): void
    {
        $prop = $this->pdo->query("SELECT id, title, public_hash, nightly_price, max_guests FROM properties WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($prop);

        $properties = [
            [
                'id' => (int) $prop['id'],
                'public_hash' => (string) $prop['public_hash'],
                'title' => (string) $prop['title'],
                'city' => 'Florianópolis',
                'nightly_price' => (int) $prop['nightly_price'],
                'max_guests' => (int) $prop['max_guests'],
                'photo' => null,
                'status' => 'active',
                'created_at' => '2026-01-01 12:00:00',
            ]
        ];

        ob_start();
        $use_api = false;
        $quotes_count = 1;
        $pending_count = 0;
        $csrf = 'csrf_token_test';
        require dirname(__DIR__, 2) . '/views/dashboard.php';
        $html = (string) ob_get_clean();

        // Must display luxury card
        $this->assertStringContainsString('dash-card', $html);
        $this->assertStringContainsString($prop['title'], $html);
        $this->assertStringContainsString('Florianópolis', $html);
        $this->assertStringContainsString('calendario', $html);
        $this->assertStringContainsString('editar', $html);
        $this->assertStringContainsString('dash-card-price', $html);
    }

    public function testDashboardViewRendersLuxuryEmptyState(): void
    {
        ob_start();
        $properties = [];
        $use_api = false;
        $quotes_count = 0;
        $pending_count = 0;
        $csrf = 'csrf_token_test';
        require dirname(__DIR__, 2) . '/views/dashboard.php';
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('dash-empty-box', $html);
        $this->assertStringContainsString('/imoveis/novo', $html);
    }
    public function testIndexPhpRequiresQuoteRepositoryForPainel(): void
    {
        $content = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        $this->assertStringContainsString("require_once dirname(__DIR__) . '/src/QuoteRepository.php';", $content);
        $this->assertStringContainsString("require_once __DIR__ . '/../src/QuoteRepository.php';", $content);
    }

    public function testApiPainelUsesPrimaryPathsBatching(): void
    {
        $apiContent = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Api.php');
        $this->assertStringContainsString('PhotoRepository::primaryPaths($propIds)', $apiContent);
        $this->assertStringContainsString('\'photo\' => $photos[$id] ?? null,', $apiContent);
    }
}

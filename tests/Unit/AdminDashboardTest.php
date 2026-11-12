<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . "/src/Db.php";
require_once dirname(__DIR__, 2) . "/src/Settings.php";
require_once dirname(__DIR__, 2) . "/src/AdminRepository.php";
require_once dirname(__DIR__, 2) . "/src/Acoes/AdminDashboard.php";
require_once dirname(__DIR__, 2) . "/src/I18n.php";

final class AdminDashboardTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        putenv("APP_ENV=testing");
        Db::loadEnv();
        $this->pdo = db();
        $this->pdo->beginTransaction();
        Settings::seed($this->pdo);
    }

    protected function tearDown(): void
    {
        try {
            $this->pdo->exec("RESET ROLE");
        } catch (Throwable) {
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testDynamicTechnicalSettingsAreRegistered(): void
    {
        $defaults = Settings::defaults();
        $this->assertArrayHasKey("calendar_sync_interval_min", $defaults);
        $this->assertArrayHasKey("calendar_sync_fail_stop", $defaults);
        $this->assertArrayHasKey("calendar_fetch_timeout_sec", $defaults);
        $this->assertArrayHasKey("photo_webp_quality", $defaults);
        $this->assertArrayHasKey("quote_hold_minutes", $defaults);
        $this->assertArrayHasKey("maintenance_mode", $defaults);
        $this->assertArrayHasKey("admin_notes_general", $defaults);
    }

    public function testAdminRepositoryGathersAccurateKpis(): void
    {
        $kpis = AdminRepository::getKpis();

        $this->assertArrayHasKey("properties", $kpis);
        $this->assertArrayHasKey("total", $kpis["properties"]);
        $this->assertArrayHasKey("active", $kpis["properties"]);
        $this->assertArrayHasKey("inactive", $kpis["properties"]);

        $this->assertArrayHasKey("users", $kpis);
        $this->assertArrayHasKey("total", $kpis["users"]);
        $this->assertArrayHasKey("admins", $kpis["users"]);

        $this->assertArrayHasKey("feeds", $kpis);
        $this->assertArrayHasKey("total", $kpis["feeds"]);
        $this->assertArrayHasKey("ok", $kpis["feeds"]);

        $this->assertArrayHasKey("queues", $kpis);
        $this->assertArrayHasKey("image_queue", $kpis["queues"]);
        $this->assertArrayHasKey("mail_queue", $kpis["queues"]);
    }

    public function testAdminDashboardRejectsNonAdmin(): void
    {
        $regularUser = ["id" => 9999, "is_admin" => 0, "status" => "active", "email" => "user@test.local"];
        $res = AdminDashboard::run($regularUser);
        $this->assertSame("forbidden", $res->tipo);
    }

    public function testAdminDashboardAcceptsAdminAndReturnsData(): void
    {
        $adminUser = ["id" => 1, "is_admin" => 1, "status" => "active", "email" => "admin@test.local"];
        $res = AdminDashboard::run($adminUser);
        $this->assertSame("ok", $res->tipo);
        $this->assertArrayHasKey("kpis", $res->dados);
        $this->assertArrayHasKey("env", $res->dados);
        $this->assertArrayHasKey("recent_activity", $res->dados);
        $this->assertArrayHasKey("settings", $res->dados);
    }

    public function testAdminNotesGeneralSettingPersistence(): void
    {
        Settings::set("admin_notes_general", "Servidor migrado para void-musl.");
        $this->assertSame("Servidor migrado para void-musl.", Settings::get("admin_notes_general"));
    }

    public function testAdminDashboardViewRendersProperly(): void
    {
        $adminUser = ["id" => 1, "is_admin" => 1, "status" => "active", "email" => "admin@test.local"];
        $res = AdminDashboard::run($adminUser);

        $kpis = $res->dados["kpis"];
        $env = $res->dados["env"];
        $services = $res->dados["services"];
        $recent_activity = $res->dados["recent_activity"];
        $settings = $res->dados["settings"];
        $user = $adminUser;
        $csrf = "test-csrf-token";

        ob_start();
        include dirname(__DIR__, 2) . "/views/admin/dashboard.php";
        $content = (string) ob_get_clean();

        $this->assertNotEmpty($content);
        $this->assertStringContainsString("Estado dos Serviços & Infraestrutura", $content);
        $this->assertStringContainsString("Imóveis Cadastrados", $content);

        ob_start();
        $page_title = "Painel Geral";
        $active_tab = "dashboard";
        include dirname(__DIR__, 2) . "/views/admin_layout.php";
        $fullHtml = (string) ob_get_clean();

        $this->assertNotEmpty($fullHtml);
        $this->assertStringContainsString("CasaLivre", $fullHtml);
        $this->assertStringContainsString("Painel Geral", $fullHtml);
    }
}

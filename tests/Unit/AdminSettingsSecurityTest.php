<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . "/src/Db.php";
require_once dirname(__DIR__, 2) . "/src/Settings.php";
require_once dirname(__DIR__, 2) . "/src/AdminRepository.php";
require_once dirname(__DIR__, 2) . "/src/LoginRateLimiter.php";
require_once dirname(__DIR__, 2) . "/src/Acoes/AdminSettingsUpdate.php";
require_once dirname(__DIR__, 2) . "/src/Acoes/AdminSecurity.php";

final class AdminSettingsSecurityTest extends TestCase
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

    public function testAdminSecurityRepositoryListsAndModifiesIps(): void
    {
        $testIp = "203.0.113.42";
        $lockUntil = date("Y-m-d H:i:s", time() + 3600);
        $this->pdo->prepare("INSERT INTO login_rate_limit (ip, failures, locked_until) VALUES (?, 6, ?)")->execute([$testIp, $lockUntil]);

        $ips = AdminRepository::getSecurityIps();
        $this->assertNotEmpty($ips);
        $found = false;
        foreach ($ips as $row) {
            if ($row["ip"] === $testIp) {
                $found = true;
                $this->assertSame(6, (int) $row["failures"]);
                $this->assertTrue($row["is_locked"]);
            }
        }
        $this->assertTrue($found, "Test IP must be present in security ips list");

        // Unblock
        AdminRepository::unblockIp($testIp);
        $this->assertFalse(LoginRateLimiter::blocked($testIp));

        // Manual Block
        $manualIp = "198.51.100.99";
        AdminRepository::blockIp($manualIp, 120);
        $this->assertTrue(LoginRateLimiter::blocked($manualIp));
    }

    public function testAdminSettingsUpdateValidatesAndApplies(): void
    {
        $adminUser = ["id" => 1, "is_admin" => 1, "status" => "active", "email" => "admin@test.local"];

        $res = AdminSettingsUpdate::run($adminUser, [
            "calendar_sync_interval_min" => "45",
            "calendar_sync_fail_stop" => "12",
            "calendar_fetch_timeout_sec" => "25",
            "photo_webp_quality" => "85",
            "quote_hold_minutes" => "20",
            "maintenance_mode" => "1",
            "login_ip_lock_minutes" => "30",
            "login_ip_max_failures" => "4",
            "session_minutes" => "90",
            "password_min_length" => "10",
        ]);

        $this->assertSame("ok", $res->tipo);
        $this->assertSame(45, Settings::int("calendar_sync_interval_min"));
        $this->assertSame(12, Settings::int("calendar_sync_fail_stop"));
        $this->assertSame(25, Settings::int("calendar_fetch_timeout_sec"));
        $this->assertSame(85, Settings::int("photo_webp_quality"));
        $this->assertSame(20, Settings::int("quote_hold_minutes"));
        $this->assertSame("1", Settings::get("maintenance_mode"));
        $this->assertSame(30, Settings::int("login_ip_lock_minutes"));
        $this->assertSame(4, Settings::int("login_ip_max_failures"));
        $this->assertSame(90, Settings::int("session_minutes"));
        $this->assertSame(10, Settings::int("password_min_length"));
    }

    public function testAdminSecurityActionRejectsNonAdmin(): void
    {
        $regularUser = ["id" => 9999, "is_admin" => 0, "status" => "active", "email" => "user@test.local"];

        $resSettings = AdminSettingsUpdate::run($regularUser, ["maintenance_mode" => "1"]);
        $this->assertSame("forbidden", $resSettings->tipo);

        $resSecurity = AdminSecurity::unblock($regularUser, "1.2.3.4");
        $this->assertSame("forbidden", $resSecurity->tipo);

        $resBlock = AdminSecurity::block($regularUser, "1.2.3.4", 60);
        $this->assertSame("forbidden", $resBlock->tipo);
    }

    public function testAdminConfiguracoesAndSegurancaViewsRender(): void
    {
        $adminUser = ["id" => 1, "is_admin" => 1, "status" => "active", "email" => "admin@test.local"];
        $csrf = "test-csrf-token";
        $ok = null;
        $error = null;

        // Configuracoes view
        $settings = [];
        foreach (array_keys(Settings::defaults()) as $k) {
            $settings[$k] = Settings::get($k);
        }
        $user = $adminUser;

        ob_start();
        include dirname(__DIR__, 2) . "/views/admin/configuracoes.php";
        $configHtml = (string) ob_get_clean();
        $this->assertNotEmpty($configHtml);
        $this->assertStringContainsString("Parâmetros Técnicos da Plataforma", $configHtml);
        $this->assertStringContainsString("Qualidade de Compressão WebP", $configHtml);

        // Seguranca view
        $resSec = AdminSecurity::index($adminUser);
        $ips = $resSec->dados["ips"];

        ob_start();
        include dirname(__DIR__, 2) . "/views/admin/seguranca.php";
        $secHtml = (string) ob_get_clean();
        $this->assertNotEmpty($secHtml);
        $this->assertStringContainsString("Endereços IP Monitorados & Bloqueados", $secHtml);
        $this->assertStringContainsString("Bloqueio Manual de IP", $secHtml);
    }
}

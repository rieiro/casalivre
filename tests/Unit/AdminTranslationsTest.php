<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . "/src/Db.php";
require_once dirname(__DIR__, 2) . "/src/Settings.php";
require_once dirname(__DIR__, 2) . "/src/I18n.php";
require_once dirname(__DIR__, 2) . "/src/AdminRepository.php";
require_once dirname(__DIR__, 2) . "/src/Acoes/AdminTranslations.php";

final class AdminTranslationsTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        putenv("APP_ENV=testing");
        Db::loadEnv();
        $this->pdo = db();
        $this->pdo->beginTransaction();
        Settings::seed($this->pdo);
        I18n::seed($this->pdo);
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

    public function testTranslationsQueryAndGrouping(): void
    {
        $adminUser = ["id" => 1, "is_admin" => 1, "status" => "active", "email" => "admin@test.local"];

        $res = AdminTranslations::index($adminUser, ["prefix" => "nav"]);
        $this->assertSame("ok", $res->tipo);
        $this->assertArrayHasKey("translations", $res->dados);
        $this->assertArrayHasKey("prefixes", $res->dados);
        $this->assertArrayHasKey("nav.search", $res->dados["translations"]);
    }

    public function testTranslationUpdateAppliesAndRefreshes(): void
    {
        $adminUser = ["id" => 1, "is_admin" => 1, "status" => "active", "email" => "admin@test.local"];

        $res = AdminTranslations::update($adminUser, [
            "tkey" => "nav.search",
            "translations" => [
                "pt-BR" => "Explorar Imóveis",
                "en" => "Explore Stays",
                "es" => "Explorar Alojamientos",
            ],
        ]);

        $this->assertSame("ok", $res->tipo);

        $check = AdminRepository::getTranslations(null, "nav.search");
        $this->assertSame("Explorar Imóveis", $check["nav.search"]["pt-BR"]);
        $this->assertSame("Explore Stays", $check["nav.search"]["en"]);
        $this->assertSame("Explorar Alojamientos", $check["nav.search"]["es"]);
    }

    public function testTranslationsRejectsNonAdmin(): void
    {
        $regularUser = ["id" => 9999, "is_admin" => 0, "status" => "active", "email" => "user@test.local"];

        $resIndex = AdminTranslations::index($regularUser, []);
        $this->assertSame("forbidden", $resIndex->tipo);

        $resUpdate = AdminTranslations::update($regularUser, ["tkey" => "nav.search", "translations" => ["pt-BR" => "X"]]);
        $this->assertSame("forbidden", $resUpdate->tipo);
    }

    public function testAdminTranslationsViewRenders(): void
    {
        $adminUser = ["id" => 1, "is_admin" => 1, "status" => "active", "email" => "admin@test.local"];
        $csrf = "test-token";
        $ok = null;
        $error = null;

        $res = AdminTranslations::index($adminUser, ["prefix" => "nav"]);
        $translations = $res->dados["translations"];
        $prefixes = $res->dados["prefixes"];
        $current_search = null;
        $current_prefix = "nav";
        $user = $adminUser;

        ob_start();
        include dirname(__DIR__, 2) . "/views/admin/traducoes.php";
        $html = (string) ob_get_clean();

        $this->assertNotEmpty($html);
        $this->assertStringContainsString("Multi-Language Translation Matrix", $html);
        $this->assertStringContainsString("nav.search", $html);
    }
}

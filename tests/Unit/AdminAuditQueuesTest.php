<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . "/src/Db.php";
require_once dirname(__DIR__, 2) . "/src/Settings.php";
require_once dirname(__DIR__, 2) . "/src/AdminRepository.php";
require_once dirname(__DIR__, 2) . "/src/Acoes/AdminAudit.php";
require_once dirname(__DIR__, 2) . "/src/Acoes/AdminQueues.php";

final class AdminAuditQueuesTest extends TestCase
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

    public function testAuditLogQueryAndMetrics(): void
    {
        $adminUser = ["id" => 1, "is_admin" => 1, "status" => "active", "email" => "admin@test.local"];

        $res = AdminAudit::index($adminUser, ["limit" => 20]);
        $this->assertSame("ok", $res->tipo);
        $this->assertArrayHasKey("logs", $res->dados);
        $this->assertArrayHasKey("metrics", $res->dados);
        $this->assertArrayHasKey("actions", $res->dados);
    }

    public function testQueueDetailsAndRetry(): void
    {
        $adminUser = ["id" => 1, "is_admin" => 1, "status" => "active", "email" => "admin@test.local"];

        $res = AdminQueues::index($adminUser);
        $this->assertSame("ok", $res->tipo);
        $this->assertArrayHasKey("image_queue", $res->dados);
        $this->assertArrayHasKey("mail_queue", $res->dados);
        $this->assertArrayHasKey("services", $res->dados);

        // Test retry failed
        $retryRes = AdminQueues::retry($adminUser, "image_queue");
        $this->assertSame("ok", $retryRes->tipo);
    }

    public function testAuditAndQueuesRejectNonAdmin(): void
    {
        $regularUser = ["id" => 9999, "is_admin" => 0, "status" => "active", "email" => "user@test.local"];

        $resAudit = AdminAudit::index($regularUser, []);
        $this->assertSame("forbidden", $resAudit->tipo);

        $resQueues = AdminQueues::index($regularUser);
        $this->assertSame("forbidden", $resQueues->tipo);

        $resRetry = AdminQueues::retry($regularUser, "mail_queue");
        $this->assertSame("forbidden", $resRetry->tipo);
    }

    public function testAuditAndQueuesViewsRender(): void
    {
        $adminUser = ["id" => 1, "is_admin" => 1, "status" => "active", "email" => "admin@test.local"];
        $csrf = "test-token";
        $ok = null;
        $error = null;

        // Auditoria view
        $resAudit = AdminAudit::index($adminUser, ["limit" => 10]);
        $logs = $resAudit->dados["logs"];
        $metrics = $resAudit->dados["metrics"];
        $actions = $resAudit->dados["actions"];
        $current_code = null;
        $current_class = null;
        $current_limit = 100;
        $user = $adminUser;

        ob_start();
        include dirname(__DIR__, 2) . "/views/admin/auditoria.php";
        $auditHtml = (string) ob_get_clean();
        $this->assertNotEmpty($auditHtml);
        $this->assertStringContainsString("Total de Eventos", $auditHtml);
        $this->assertStringContainsString("Exportar Relatório CSV", $auditHtml);

        // Filas view
        $resQueues = AdminQueues::index($adminUser);
        $image_queue = $resQueues->dados["image_queue"];
        $mail_queue = $resQueues->dados["mail_queue"];
        $services = $resQueues->dados["services"];

        ob_start();
        include dirname(__DIR__, 2) . "/views/admin/filas.php";
        $filasHtml = (string) ob_get_clean();
        $this->assertNotEmpty($filasHtml);
        $this->assertStringContainsString("Supervisão de Daemons em Background", $filasHtml);
        $this->assertStringContainsString("Fila de Otimização de Imagens", $filasHtml);
    }
}

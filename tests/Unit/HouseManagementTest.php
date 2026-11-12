<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Db.php';
require_once dirname(__DIR__, 2) . '/src/HouseRepository.php';
require_once dirname(__DIR__, 2) . '/src/ImovelNaoEncontrado.php';

final class HouseManagementTest extends TestCase
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

    public function testManagerCanManageGroupPropertyViaFindManageable(): void
    {
        $stmt = $this->pdo->query("
            SELECT p.id as property_id, gm.user_id as manager_id, p.user_id as owner_id
            FROM properties p
            JOIN group_members gm ON gm.group_id = p.group_id AND gm.role = 'gestor'
            WHERE p.status = 'active'
            LIMIT 1
        ");
        $row = $stmt->fetch();
        if (!$row) {
            $this->markTestSkipped('No group property with manager in seed database');
        }

        $propertyId = (int) $row['property_id'];
        $managerId = (int) $row['manager_id'];
        $ownerId = (int) $row['owner_id'];

        $this->assertNotEquals($managerId, $ownerId);

        $this->assertNull(HouseRepository::findByUser($managerId, $propertyId));

        $manageable = HouseRepository::findManageable($managerId, $propertyId);
        $this->assertNotNull($manageable);
        $this->assertSame($propertyId, (int) $manageable['id']);

        $manageableOrFail = HouseRepository::findManageableOrFail($managerId, $propertyId);
        $this->assertSame($propertyId, (int) $manageableOrFail['id']);
    }

    public function testNonManagerNonOwnerCannotManageProperty(): void
    {
        $stmt = $this->pdo->query("SELECT id FROM properties WHERE status = 'active' LIMIT 1");
        $propertyId = (int) $stmt->fetchColumn();

        // Create random user ID not related to property or group
        $nonMemberId = 999999;

        $this->assertNull(HouseRepository::findManageable($nonMemberId, $propertyId));

        $this->expectException(ImovelNaoEncontrado::class);
        HouseRepository::findManageableOrFail($nonMemberId, $propertyId);
    }
}

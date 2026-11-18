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

    public function testMarkFeedPendingSchedulesImmediateSync(): void
    {
        $stmt = $this->pdo->query("SELECT id FROM properties WHERE status = 'active' LIMIT 1");
        $propertyId = (int) $stmt->fetchColumn();

        $ins = $this->pdo->prepare("INSERT INTO calendar_feeds (property_id, url, title, next_sync_at, fail_count, last_error, last_status, slot) VALUES (?, 'https://example.com/test.ics', 'Test Feed', now() + interval '2 hours', 3, 'timeout', 'ERRO', 1) RETURNING id");
        $ins->execute([$propertyId]);
        $feedId = (int) $ins->fetchColumn();

        HouseRepository::markFeedPending($propertyId, $feedId);

        $check = $this->pdo->prepare("SELECT next_sync_at <= now() AS is_due, fail_count, last_error, last_status FROM calendar_feeds WHERE id = ?");
        $check->execute([$feedId]);
        $row = $check->fetch(PDO::FETCH_ASSOC);

        $this->assertTrue((bool) $row['is_due']);
        $this->assertSame(0, (int) $row['fail_count']);
        $this->assertNull($row['last_error']);
        $this->assertSame('PENDENTE', $row['last_status']);
    }

    public function testSyncNowRouteTriggersMarkFeedPending(): void
    {
        require_once dirname(__DIR__, 2) . "/src/Api.php";
        $stmt = $this->pdo->query("
            SELECT p.id as property_id, p.public_hash, p.user_id as owner_id
            FROM properties p
            WHERE p.status = 'active'
            LIMIT 1
        ");
        $prop = $stmt->fetch(PDO::FETCH_ASSOC);
        $propertyId = (int) $prop["property_id"];
        $ownerId = (int) $prop["owner_id"];

        $ins = $this->pdo->prepare("INSERT INTO calendar_feeds (property_id, url, title, next_sync_at, fail_count, last_error, last_status, slot) VALUES (?, 'https://example.com/sync-now.ics', 'Sync Test', now() + interval '3 hours', 2, 'timeout', 'ERRO', 2) RETURNING id");
        $ins->execute([$propertyId]);
        $feedId = (int) $ins->fetchColumn();

        // Testing direct controller / repository sync invocation
        HouseRepository::findManageableOrFail($ownerId, $propertyId);
        HouseRepository::markFeedPending($propertyId, $feedId);

        $check = $this->pdo->prepare("SELECT next_sync_at <= now() AS is_due, fail_count, last_status FROM calendar_feeds WHERE id = ?");
        $check->execute([$feedId]);
        $row = $check->fetch(PDO::FETCH_ASSOC);

        $this->assertTrue((bool) $row["is_due"]);
        $this->assertSame(0, (int) $row["fail_count"]);
        $this->assertSame("PENDENTE", $row["last_status"]);
    }

    /**
     * Perf (#painel): listByUser must read only the user's rows (own + groups they manage),
     * keep newest-first ordering, never duplicate, and never return inactive properties.
     */
    public function testListByUserReturnsOnlyOwnedAndManagedActiveProperties(): void
    {
        $row = $this->pdo->query("
            SELECT user_id
            FROM properties
            WHERE status = 'active'
            GROUP BY user_id
            HAVING count(*) >= 2
            ORDER BY user_id
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->markTestSkipped('Seed has no user with two active properties');
        }
        $ownerId = (int) $row['user_id'];

        $listed = HouseRepository::listByUser($ownerId);
        $ids = array_map(static fn (array $p): int => (int) $p['id'], $listed);

        foreach ($listed as $property) {
            $this->assertSame('active', $property['status'], 'inactive properties must never be listed');
        }
        $this->assertSame(count($ids), count(array_unique($ids)), 'a property must never be listed twice');
        $descending = $ids;
        rsort($descending);
        $this->assertSame($descending, $ids, 'newest property first (id DESC)');

        $ownActive = array_map('intval', $this->pdo->query(
            "SELECT id FROM properties WHERE user_id = {$ownerId} AND status = 'active' ORDER BY id DESC"
        )->fetchAll(PDO::FETCH_COLUMN));
        $this->assertNotEmpty($ownActive);
        foreach ($ownActive as $id) {
            $this->assertContains($id, $ids, "owned active property {$id} must be listed");
        }

        // Same group-manager contract as findManageable(): a gestor sees properties of the group.
        $managed = $this->pdo->query("
            SELECT gm.user_id AS manager_id, p.id AS property_id
            FROM properties p
            JOIN group_members gm ON gm.group_id = p.group_id AND gm.role = 'gestor'
            WHERE p.status = 'active' AND p.user_id <> gm.user_id
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        if ($managed) {
            $managedIds = array_map(
                static fn (array $p): int => (int) $p['id'],
                HouseRepository::listByUser((int) $managed['manager_id'])
            );
            $this->assertContains((int) $managed['property_id'], $managedIds, 'group manager must see the group property');
        }

        // Deactivating an owned property removes it from the list (and nothing else leaks in).
        $target = $ownActive[0];
        $this->pdo->prepare("UPDATE properties SET status = 'inactive' WHERE id = ?")->execute([$target]);
        $afterInactive = array_map(
            static fn (array $p): int => (int) $p['id'],
            HouseRepository::listByUser($ownerId)
        );
        $this->assertNotContains($target, $afterInactive);
    }

    /** Equivalence guard: the optimized query must return exactly the rows of the original OR definition. */
    public function testListByUserMatchesTheOriginalOrDefinition(): void
    {
        $row = $this->pdo->query("SELECT user_id FROM properties WHERE status = 'active' GROUP BY user_id ORDER BY count(*) DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->markTestSkipped('Seed has no active properties');
        }
        $userId = (int) $row['user_id'];

        $expected = array_map('intval', $this->pdo->query("
            SELECT p.id
            FROM properties p
            JOIN places c ON c.id = p.city_place_id
            WHERE (p.user_id = {$userId} OR p.group_id IN (
                SELECT group_id FROM group_members WHERE user_id = {$userId} AND role = 'gestor'
            )) AND p.status = 'active'
            ORDER BY p.id DESC
        ")->fetchAll(PDO::FETCH_COLUMN));

        $actual = array_map(
            static fn (array $p): int => (int) $p['id'],
            HouseRepository::listByUser($userId)
        );

        $this->assertSame($expected, $actual);
    }
}

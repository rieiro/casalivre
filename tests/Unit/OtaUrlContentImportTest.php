<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Db.php';
require_once dirname(__DIR__, 2) . '/src/I18n.php';
require_once dirname(__DIR__, 2) . '/src/Settings.php';
require_once dirname(__DIR__, 2) . '/src/ActivityLog.php';
require_once dirname(__DIR__, 2) . '/src/CalendarSync.php';
require_once dirname(__DIR__, 2) . '/src/OtaPlatformRegistry.php';
require_once dirname(__DIR__, 2) . '/src/OtaFeedGate.php';
require_once dirname(__DIR__, 2) . '/src/OtaVerificationService.php';
require_once dirname(__DIR__, 2) . '/src/OtaImportService.php';
require_once dirname(__DIR__, 2) . '/src/HouseRepository.php';
require_once dirname(__DIR__, 2) . '/src/PhotoRepository.php';
require_once dirname(__DIR__, 2) . '/src/PhotoStorage.php';
require_once dirname(__DIR__, 2) . '/src/ImageWorker.php';
require_once dirname(__DIR__, 2) . '/src/ListingSearch.php';
require_once dirname(__DIR__, 2) . '/src/HouseService.php';
require_once dirname(__DIR__, 2) . '/src/PhotoFail.php';
require_once dirname(__DIR__, 2) . '/src/helpers.php';

final class OtaUrlContentImportTest extends TestCase
{
    private const T0 = 1795176000; // 2026-11-20 12:00:00 UTC

    private PDO $pdo;
    private int $now;
    private int $userId;
    private int $adminId;
    private int $propertyId;
    private string $tmpBase;
    private int $seq = 0;

    protected function setUp(): void
    {
        putenv('APP_ENV=testing');
        Db::loadEnv();
        $this->pdo = db();
        $this->pdo->beginTransaction();
        $this->now = self::T0;
        OtaVerificationService::$clock = fn (): int => $this->now;
        OtaVerificationService::$httpTransport = fn (): string => $this->emptyIcs();
        OtaImportService::$clock = fn (): int => $this->now;
        CalendarSync::$httpTransport = null;
        OtaFeedGate::$shareTransport = null;
        ImageWorker::$watermarkEdgeThreshold = 0.34;

        $this->tmpBase = sys_get_temp_dir() . '/cl-ota-test-' . bin2hex(random_bytes(5));
        PhotoStorage::$originals = $this->tmpBase . '/orig';
        PhotoStorage::$public = $this->tmpBase . '/pub';
        @mkdir(PhotoStorage::$originals, 0700, true);
        @mkdir(PhotoStorage::$public, 0755, true);

        $this->userId = $this->makeUser('host');
        $this->adminId = $this->makeUser('admin');
        $this->propertyId = $this->makeProperty($this->userId, 'Original', 'active');
    }

    protected function tearDown(): void
    {
        OtaVerificationService::$httpTransport = null;
        OtaVerificationService::$clock = null;
        OtaImportService::$clock = null;
        CalendarSync::$httpTransport = null;
        OtaFeedGate::$shareTransport = null;
        PhotoStorage::$originals = null;
        PhotoStorage::$public = null;
        PhotoStorage::$queueFactory = null;
        ImageWorker::$watermarkEdgeThreshold = 0.34;
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->removeDir($this->tmpBase);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }

    private function makeUser(string $prefix): int
    {
        $email = $prefix . '+' . bin2hex(random_bytes(5)) . '@test.local';
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (name, cpf, address, phone, email, password_hash, status)
             VALUES ('Anfitrião OTA', '52998224725', 'Rua A, 1', '48999999999', ?, 'x', 'active')
             RETURNING id"
        );
        $stmt->execute([$email]);
        return (int) $stmt->fetchColumn();
    }

    private function makeProperty(int $userId, string $title, string $status): int
    {
        $city = (int) $this->pdo->query("SELECT id FROM places WHERE type = 'city' ORDER BY id LIMIT 1")->fetchColumn();
        $stmt = $this->pdo->prepare(
            "INSERT INTO properties (user_id, title, description, summary, city_place_id, address,
                lat, lng, bedrooms, beds, max_guests, nightly_price, status)
             VALUES (?, ?, 'Descrição', 'Resumo', ?, 'Rua A, 1', -27.6, -48.5, 2, 2, 4, 250, ?)
             RETURNING id"
        );
        $stmt->execute([$userId, $title, $city, $status]);
        return (int) $stmt->fetchColumn();
    }

    private function importRow(int $propertyId, string $platform, string $canonical, string $status = 'draft_ready', ?int $expires = null): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO property_ota_imports
                (property_id, user_id, platform, canonical_listing_id, canonical_property_url, status,
                 extracted_payload, sanitization_flags, consent_at, expires_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, '{}'::jsonb, '{}'::jsonb, to_timestamp(?), to_timestamp(?), to_timestamp(?))
             RETURNING id"
        );
        $stmt->execute([
            $propertyId,
            $this->userId,
            $platform,
            $canonical,
            'https://www.airbnb.com/rooms/' . preg_replace('/\D/', '', $canonical),
            $status,
            $this->now,
            $expires ?? ($this->now + 86400),
            $this->now,
        ]);
        return (int) $stmt->fetchColumn();
    }

    private function feedRow(int $propertyId, string $platform, string $canonical, string $review = 'approved', ?int $verifiedAt = null): int
    {
        $this->seq++;
        $stmt = $this->pdo->prepare(
            "INSERT INTO calendar_feeds
                (property_id, slot, url, url_hash, source_platform, title, status, verification_mode,
                 property_url, canonical_listing_id, review_status, verified_at, consent_at, next_sync_at)
             VALUES (?, ?, ?, ?, ?, 'Feed', ?, 'ota', ?, ?, ?, ?, to_timestamp(?), to_timestamp(?))
             RETURNING id"
        );
        $url = 'https://www.airbnb.com/calendar/ical/' . preg_replace('/\D/', '', $canonical) . '.ics';
        $stmt->execute([
            $propertyId,
            $this->seq,
            $url,
            hash('sha256', $url),
            $platform,
            $verifiedAt === null ? 'pending_verification' : 'active',
            'https://www.airbnb.com/rooms/' . preg_replace('/\D/', '', $canonical),
            $canonical,
            $review,
            $verifiedAt === null ? null : gmdate('Y-m-d H:i:s', $verifiedAt),
            $this->now,
            $this->now,
        ]);
        return (int) $stmt->fetchColumn();
    }

    private function emptyIcs(): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CasaLivre//Test//EN\r\nEND:VCALENDAR\r\n";
    }

    private function icsWithBlocked(string $ymd): string
    {
        $end = (new DateTimeImmutable($ymd, new DateTimeZone('UTC')))->modify('+1 day')->format('Ymd');
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CasaLivre//Test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:blocked\r\nDTSTART;VALUE=DATE:" . str_replace('-', '', $ymd)
            . "\r\nDTEND;VALUE=DATE:" . $end . "\r\nSUMMARY:Blocked\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    private function listingHtml(string $title = 'Casa Importada', string $description = 'Descrição importada da casa.'): string
    {
        return '<html><head><script type="application/ld+json">' . json_encode([
            '@type' => 'VacationRental',
            'name' => $title,
            'description' => $description,
            'image' => ['https://a0.muscache.com/im/pictures/1.jpg?im_w=720'],
            'amenityFeature' => [['name' => 'Wifi'], ['name' => 'Piscina']],
            'occupancy' => ['maxValue' => 8],
        ]) . '</script></head><body></body></html>';
    }

    private function jpegBytes(bool $withComment = false, int $w = 120, int $h = 90): string
    {
        $img = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h; $y++) {
            $color = imagecolorallocate($img, 40 + $y, 90, 160 - (int) ($y / 2));
            imageline($img, 0, $y, $w, $y, $color);
        }
        ob_start();
        imagejpeg($img, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);
        if ($withComment) {
            $text = "CasaLivreSecret\x00";
            $segment = "\xFF\xFE" . pack('n', strlen($text) + 2) . $text;
            $bytes = substr($bytes, 0, 2) . $segment . substr($bytes, 2);
        }
        return $bytes;
    }

    private function writeOriginal(string $uuid, string $bytes, string $ext = 'jpg'): string
    {
        $name = $uuid . '.' . $ext;
        file_put_contents(PhotoStorage::$originals . '/' . $name, $bytes);
        return $name;
    }

    private function writeVariant(string $uuid, int $width): string
    {
        $file = PhotoStorage::$public . '/' . $uuid . '-' . $width . '.webp';
        file_put_contents($file, 'webp');
        return $file;
    }

    public function testImageHostAllowlistIsExactAndPlatformScoped(): void
    {
        $this->assertContains('a0.muscache.com', OtaPlatformRegistry::imageHosts('airbnb'));
        $this->assertTrue(OtaPlatformRegistry::isImageHost('airbnb', 'a0.muscache.com'));
        $this->assertTrue(OtaPlatformRegistry::isImageHost('airbnb', 'A1.MUSCACHE.COM'));
        $this->assertFalse(OtaPlatformRegistry::isImageHost('airbnb', 'evil.example'));
        $this->assertFalse(OtaPlatformRegistry::isImageHost('airbnb', 'a0.muscache.com.evil.example'));
        $this->assertFalse(OtaPlatformRegistry::isImageHost('booking', 'a0.muscache.com'));
        $this->assertTrue(OtaPlatformRegistry::isImageHost('booking', 'cf.bstatic.com'));
        $this->assertTrue(OtaPlatformRegistry::isImageHost('vrbo', 'images.trvl-media.com'));
    }

    public function testPreparePublicUrlSharesGateAndSsrfGuardRejectsPrivateTargets(): void
    {
        $public = OtaFeedGate::preparePublicUrl($this->propertyId, $this->userId, 'airbnb', 'https://www.airbnb.com/rooms/123?foo=1', true);
        $this->assertSame('airbnb', $public['platform']);
        $this->assertSame('airbnb:123', $public['canonical_listing_id']);
        $this->assertSame('https://www.airbnb.com/rooms/123', $public['property_url']);
        $this->assertGreaterThan(0, (int) $public['consent_at']);

        try {
            OtaFeedGate::preparePublicUrl($this->propertyId, $this->userId, 'airbnb', 'https://www.airbnb.com/rooms/123', false);
            $this->fail('consent must be required');
        } catch (OtaFeedGateException $e) {
            $this->assertSame('consent', $e->field);
        }
        try {
            OtaFeedGate::preparePublicUrl($this->propertyId, $this->userId, 'airbnb', 'https://evil.example/rooms/123', true);
            $this->fail('foreign host must be rejected');
        } catch (OtaFeedGateException $e) {
            $this->assertSame('property_url', $e->field);
        }

        $this->assertSame('bad_url', CalendarSync::fetchPublicResource('http://example.com/x')['error']);
        $this->assertSame('private_ip', CalendarSync::fetchPublicResource('https://127.0.0.1/x')['error']);
        $this->assertSame('private_ip', CalendarSync::fetchPublicResource('https://localhost/x')['error']);
    }

    public function testPublishedPropertyRequiresApprovedOtaFeedAndLocksTheUrl(): void
    {
        CalendarSync::$httpTransport = fn (): array => ['body' => $this->listingHtml(), 'http' => 200];
        $blocked = OtaImportService::startExisting(
            HouseRepository::find($this->propertyId),
            $this->userId,
            'airbnb',
            'https://www.airbnb.com/rooms/222',
            true
        );
        $this->assertSame('invalido', $blocked->tipo);
        $this->assertSame(t('ota.import.published_locked'), $blocked->mensagem);

        $this->feedRow($this->propertyId, 'airbnb', 'airbnb:222', 'approved', $this->now);
        $locked = OtaImportService::startExisting(
            HouseRepository::find($this->propertyId),
            $this->userId,
            'airbnb',
            'https://www.airbnb.com/rooms/111',
            true
        );
        $this->assertSame('invalido', $locked->tipo);
        $this->assertSame(t('ota.err_import_url_locked'), $locked->mensagem);

        $ok = OtaImportService::startExisting(
            HouseRepository::find($this->propertyId),
            $this->userId,
            'airbnb',
            'https://www.airbnb.com/rooms/222',
            true
        );
        $this->assertSame('ok', $ok->tipo, (string) $ok->mensagem);
    }

    public function testDraftCapBlocksFourthUnverifiedImport(): void
    {
        $draft = HouseRepository::insertDraft($this->userId);
        for ($i = 0; $i < 3; $i++) {
            $this->importRow((int) $draft['id'], 'airbnb', 'airbnb:' . (900 + $i));
        }
        $this->assertSame(3, OtaImportService::unverifiedDrafts($this->userId));
        $res = OtaImportService::startNewDraft($this->userId, 'airbnb', 'https://www.airbnb.com/rooms/999', true);
        $this->assertSame('limite', $res->tipo);
        $this->assertSame(t('ota.import.cap_reached'), $res->mensagem);
    }

    public function testNonDestructiveMergeFillsOnlyEmptyFieldsAndKeepsSuggestions(): void
    {
        $draft = HouseRepository::insertDraft($this->userId);
        $draftId = (int) $draft['id'];
        CalendarSync::$httpTransport = fn (): array => ['body' => $this->listingHtml(), 'http' => 200];

        $first = OtaImportService::startExisting(HouseRepository::find($draftId), $this->userId, 'airbnb', 'https://www.airbnb.com/rooms/333', true);
        $this->assertSame('ok', $first->tipo);
        $row = HouseRepository::find($draftId);
        $this->assertSame('Casa Importada', (string) $row['title']);
        $this->assertSame('Descrição importada da casa.', (string) $row['description']);
        $this->assertSame(8, (int) $row['max_guests']);
        $amenities = json_decode((string) $row['amenities'], true);
        $this->assertSame(1, $amenities['wifi'] ?? null);
        $this->assertSame(1, $amenities['pool'] ?? null);

        $this->pdo->prepare("UPDATE properties SET title = 'Meu título' WHERE id = ?")->execute([$draftId]);
        $this->pdo->prepare("DELETE FROM property_ota_imports WHERE property_id = ?")->execute([$draftId]);
        CalendarSync::$httpTransport = fn (): array => ['body' => $this->listingHtml('Outro título', 'Outra descrição.'), 'http' => 200];
        $second = OtaImportService::startExisting(HouseRepository::find($draftId), $this->userId, 'airbnb', 'https://www.airbnb.com/rooms/334', true);
        $this->assertSame('ok', $second->tipo);
        $row = HouseRepository::find($draftId);
        $this->assertSame('Meu título', (string) $row['title']);
        $this->assertSame('Descrição importada da casa.', (string) $row['description']);
        $import = OtaImportService::activeForProperty($draftId, 'airbnb');
        $this->assertSame('Outro título', (string) ($import['extracted_payload']['suggestions']['title'] ?? ''));
    }

    public function testEnqueuePhotosRejectsForeignHostUpgradesResolutionAndDedups(): void
    {
        $draft = HouseRepository::insertDraft($this->userId);
        $importId = $this->importRow((int) $draft['id'], 'airbnb', 'airbnb:444');
        $res = OtaImportService::enqueuePhotos((int) $draft['id'], $importId, 'airbnb', [
            'https://evil.example/x.jpg',
            'https://a0.muscache.com/im/pictures/a.jpg?im_w=720',
            'https://a0.muscache.com/im/pictures/a.jpg?im_w=1200',
        ]);
        $this->assertSame(1, $res['enqueued']);
        $this->assertSame(2, $res['skipped']);
        $rows = PhotoRepository::listByImport($importId);
        $this->assertCount(1, $rows);
        $this->assertSame('https://a0.muscache.com/im/pictures/a.jpg', (string) $rows[0]['source_url']);
        $this->assertSame(1, (int) $rows[0]['is_primary']);
    }

    public function testDiscardDeletesImportedPhotosAndReleasesTheUrl(): void
    {
        $draft = HouseRepository::insertDraft($this->userId);
        $draftId = (int) $draft['id'];
        $this->importRow($draftId, 'airbnb', 'airbnb:555');
        $uuid = PhotoStorage::randomId();
        $original = $this->writeOriginal($uuid, $this->jpegBytes());
        $variant = $this->writeVariant($uuid, 320);
        $this->pdo->prepare(
            "INSERT INTO photos (property_id, path, is_primary, sort_order, status, uuid, original, ota_import_id)
             VALUES (?, '/uploads/fotos/x.webp', 1, 0, 'ready', ?, ?, (SELECT id FROM property_ota_imports WHERE property_id = ? ORDER BY id DESC LIMIT 1))"
        )->execute([$draftId, $uuid, $original, $draftId]);
        $this->assertFileExists(PhotoStorage::$originals . "/" . $original);
        $this->assertFileExists($variant);

        $res = OtaImportService::discard($draftId, $this->userId, 'airbnb');
        $this->assertSame('ok', $res->tipo);
        $this->assertFileDoesNotExist(PhotoStorage::$originals . "/" . $original);
        $this->assertFileDoesNotExist($variant);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM photos WHERE property_id = ' . $draftId)->fetchColumn());
        $import = OtaImportService::activeForProperty($draftId, 'airbnb');
        $this->assertNull($import);
        $status = (string) $this->pdo->query('SELECT status FROM property_ota_imports WHERE property_id = ' . $draftId . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
        $this->assertSame('discarded', $status);
        // URL unlocked: a different canonical is accepted again.
        OtaFeedGate::preparePublicUrl($draftId, $this->userId, 'airbnb', 'https://www.airbnb.com/rooms/556', true);
    }

    public function testPublishBlockedByUnverifiedImportThenAllowedAfterDiscard(): void
    {
        $draft = HouseRepository::insertDraft($this->userId);
        $draftId = (int) $draft['id'];
        $this->importRow($draftId, 'airbnb', 'airbnb:666');
        $blocked = OtaImportService::publish($draftId, $this->userId);
        $this->assertSame('bloqueado', $blocked->tipo);
        $this->assertSame('draft', (string) HouseRepository::find($draftId)['status']);

        OtaImportService::discard($draftId, $this->userId, 'airbnb');
        $ok = OtaImportService::publish($draftId, $this->userId);
        $this->assertSame('ok', $ok->tipo);
        $this->assertSame('active', (string) HouseRepository::find($draftId)['status']);
        $this->assertSame('active', (string) $this->pdo->query('SELECT status FROM listing_search WHERE id = ' . $draftId)->fetchColumn());
    }

    public function testDraftIsHiddenFromPublicLookupsAndFeedWorker(): void
    {
        $draft = HouseRepository::insertDraft($this->userId);
        $draftId = (int) $draft['id'];
        ListingSearch::sync($draftId);
        $this->assertSame('inactive', (string) $this->pdo->query('SELECT status FROM listing_search WHERE id = ' . $draftId)->fetchColumn());
        $this->assertNull(HouseRepository::findByPublicHash(HouseRepository::ensurePublicHash(HouseRepository::find($draftId))));
        $this->assertNotNull(HouseRepository::findByPublicHashAny(HouseRepository::ensurePublicHash(HouseRepository::find($draftId))));
        $this->assertNotNull(HouseRepository::findManageable($this->userId, $draftId));
        $this->assertStringContainsString("p.status = 'active'", HouseRepository::CLAIM_DUE_FEED_SQL);
    }

    public function testRemoteImagePipelineStripsMetadataAndSkipsPhashDuplicates(): void
    {
        $draft = HouseRepository::insertDraft($this->userId);
        $draftId = (int) $draft['id'];
        $importId = $this->importRow($draftId, 'airbnb', 'airbnb:777');
        $bytes = $this->jpegBytes(true);
        CalendarSync::$httpTransport = fn (): array => ['body' => $bytes, 'http' => 200];

        $uuid = PhotoStorage::randomId();
        $row = PhotoRepository::insertProcessing($draftId, $uuid, null, 1, 0, [
            'source_url' => 'https://a0.muscache.com/im/pictures/a.jpg',
            'source_url_hash' => hash('sha256', 'a'),
            'ota_import_id' => $importId,
        ]);
        $photoId = (int) $row['id'];
        $this->assertTrue(ImageWorker::process($photoId));
        $ready = PhotoRepository::findById($photoId);
        $this->assertSame('ready', (string) $ready['status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', (string) $ready['phash']);
        $webp = (string) file_get_contents(PhotoStorage::variantFile($uuid, 1920));
        $this->assertStringNotContainsString('CasaLivreSecret', $webp);

        $uuid2 = PhotoStorage::randomId();
        $row2 = PhotoRepository::insertProcessing($draftId, $uuid2, null, 0, 1, [
            'source_url' => 'https://a0.muscache.com/im/pictures/b.jpg',
            'source_url_hash' => hash('sha256', 'b'),
            'ota_import_id' => $importId,
        ]);
        $this->assertFalse(ImageWorker::process((int) $row2['id']));
        $failed = PhotoRepository::findById((int) $row2['id']);
        $this->assertSame('failed', (string) $failed['status']);
        $this->assertSame(PhotoFail::DUPLICATE, (string) $failed['fail_reason']);
    }

    public function testWatermarkHeuristicFlagsCornerOverlay(): void
    {
        $clean = imagecreatetruecolor(200, 150);
        imagefill($clean, 0, 0, imagecolorallocate($clean, 240, 240, 240));
        $this->assertFalse(ImageWorker::watermarkFlagged($clean));
        imagedestroy($clean);

        ImageWorker::$watermarkEdgeThreshold = 0.18;
        $marked = imagecreatetruecolor(200, 150);
        imagefill($marked, 0, 0, imagecolorallocate($marked, 255, 255, 255));
        $black = imagecolorallocate($marked, 0, 0, 0);
        for ($x = 4; $x < 42; $x += 4) {
            imagefilledrectangle($marked, $x, 4, $x + 1, 30, $black);
        }
        $this->assertTrue(ImageWorker::watermarkFlagged($marked));
        imagedestroy($marked);
    }

    public function testVerifyFeedPromotesMatchingImportInsideTheTransaction(): void
    {
        $draft = HouseRepository::insertDraft($this->userId);
        $draftId = (int) $draft['id'];
        $importId = $this->importRow($draftId, 'airbnb', 'airbnb:888');
        $feedId = $this->feedRow($draftId, 'airbnb', 'airbnb:888', 'approved', null);
        $date = gmdate('Y-m-d', $this->now + 172800);
        $this->pdo->prepare(
            'UPDATE calendar_feeds SET challenge_date = ?, challenge_expires_at = to_timestamp(?) WHERE id = ?'
        )->execute([$date, $this->now + 90000, $feedId]);
        OtaVerificationService::$httpTransport = fn (): string => $this->icsWithBlocked($date);

        $res = HouseRepository::verifyFeed($draftId, $feedId, true);
        $this->assertSame('verified', (string) ($res['status'] ?? ''), (string) ($res['message'] ?? ''));
        $import = $this->pdo->query('SELECT status, calendar_feed_id FROM property_ota_imports WHERE id = ' . $importId)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('verified', (string) $import['status']);
        $this->assertSame($feedId, (int) $import['calendar_feed_id']);
    }

    public function testAdminReviewApproveAndRejectSyncTheImportStatus(): void
    {
        $draft = HouseRepository::insertDraft($this->userId);
        $draftId = (int) $draft['id'];
        $importA = $this->importRow($draftId, 'booking', 'booking:br/casa-a');
        $feedA = $this->feedRow($draftId, 'booking', 'booking:br/casa-a', 'pending_review', $this->now);
        $this->pdo->prepare('UPDATE calendar_feeds SET property_url = ? WHERE id = ?')
            ->execute(['https://www.booking.com/hotel/br/casa-a.html', $feedA]);
        $approved = OtaVerificationService::approveFeed($feedA, $this->adminId);
        $this->assertSame('approved', (string) $approved['status']);
        $this->assertSame('verified', (string) $this->pdo->query('SELECT status FROM property_ota_imports WHERE id = ' . $importA)->fetchColumn());

        $importB = $this->importRow($draftId, 'vrbo', 'vrbo:999');
        $feedB = $this->feedRow($draftId, 'vrbo', 'vrbo:999', 'pending_review', $this->now);
        $this->pdo->prepare('UPDATE calendar_feeds SET property_url = ? WHERE id = ?')
            ->execute(['https://www.vrbo.com/999', $feedB]);
        $rejected = OtaVerificationService::rejectFeed($feedB, $this->adminId, 'Não corresponde ao imóvel.');
        $this->assertSame('rejected', (string) $rejected['status']);
        $this->assertSame('rejected', (string) $this->pdo->query('SELECT status FROM property_ota_imports WHERE id = ' . $importB)->fetchColumn());
    }

    public function testAdminReviewExposesUrlImportBadge(): void
    {
        $draft = HouseRepository::insertDraft($this->userId);
        $draftId = (int) $draft['id'];
        $this->importRow($draftId, 'booking', 'booking:br/casa-badge');
        $feedId = $this->feedRow($draftId, 'booking', 'booking:br/casa-badge', 'pending_review', $this->now);
        $this->pdo->prepare('UPDATE calendar_feeds SET property_url = ? WHERE id = ?')
            ->execute(['https://www.booking.com/hotel/br/casa-badge.html', $feedId]);

        $pending = OtaVerificationService::listPendingReviews();
        $found = null;
        foreach ($pending as $row) {
            if ((int) $row['id'] === $feedId) {
                $found = $row;
                break;
            }
        }
        $this->assertNotNull($found);
        $this->assertTrue((bool) $found['has_url_import']);

        $user = ['id' => $this->userId, 'name' => 'Host', 'email' => 'host@test.local'];
        $csrf = 'csrf';
        $ok = true;
        $error = null;
        ob_start();
        require dirname(__DIR__, 2) . '/views/admin/verificacoes.php';
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('Importado via URL', $html);
        $this->assertStringContainsString('NÃO comprovam titularidade', $html);
    }

    public function testExpireStaleMarksExpiredAndDeletesImportedPhotos(): void
    {
        $draft = HouseRepository::insertDraft($this->userId);
        $draftId = (int) $draft['id'];
        $importId = $this->importRow($draftId, 'airbnb', 'airbnb:1010', 'draft_ready', $this->now - 60);
        $uuid = PhotoStorage::randomId();
        $original = $this->writeOriginal($uuid, $this->jpegBytes());
        $variant = $this->writeVariant($uuid, 320);
        $this->pdo->prepare(
            "INSERT INTO photos (property_id, path, is_primary, sort_order, status, uuid, original, ota_import_id)
             VALUES (?, '/uploads/fotos/x.webp', 1, 0, 'ready', ?, ?, ?)"
        )->execute([$draftId, $uuid, $original, $importId]);

        $res = OtaImportService::expireStale($this->now);
        $this->assertSame(1, $res['expired']);
        $this->assertSame(1, $res['photos']);
        $this->assertSame('expired', (string) $this->pdo->query('SELECT status FROM property_ota_imports WHERE id = ' . $importId)->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM photos WHERE ota_import_id = ' . $importId)->fetchColumn());
        $this->assertFileDoesNotExist(PhotoStorage::$originals . "/" . $original);
        $this->assertFileDoesNotExist($variant);
    }

    public function testImportDiscardAndPublishExposeAuditEventsInResultado(): void
    {
        CalendarSync::$httpTransport = fn (): array => ['body' => $this->listingHtml(), 'http' => 200];
        $draft = HouseRepository::insertDraft($this->userId);
        $draftId = (int) $draft['id'];

        $import = OtaImportService::startExisting(
            HouseRepository::find($draftId),
            $this->userId,
            'airbnb',
            'https://www.airbnb.com/rooms/1212',
            true
        );
        $this->assertSame('ok', $import->tipo);
        $this->assertNotEmpty($import->eventos);
        $this->assertSame(ActivityLog::OTA_IMPORT_START, $import->eventos[0][0]);
        $this->assertArrayNotHasKey('event', $import->dados);

        $discard = OtaImportService::discard($draftId, $this->userId, 'airbnb');
        $this->assertSame('ok', $discard->tipo);
        $this->assertNotEmpty($discard->eventos);
        $this->assertSame(ActivityLog::OTA_IMPORT_DISCARD, $discard->eventos[0][0]);
        $this->assertArrayNotHasKey('event', $discard->dados);

        $publish = OtaImportService::publish($draftId, $this->userId);
        $this->assertSame('ok', $publish->tipo);
        $this->assertNotEmpty($publish->eventos);
        $this->assertSame(ActivityLog::PROPERTY_PUBLISH, $publish->eventos[0][0]);
        $this->assertArrayNotHasKey('event', $publish->dados);

        $new = OtaImportService::startNewDraft($this->userId, 'airbnb', 'https://www.airbnb.com/rooms/1313', true);
        $this->assertSame('ok', $new->tipo, (string) $new->mensagem);
        $this->assertNotEmpty($new->eventos);
        $this->assertSame(ActivityLog::OTA_IMPORT_START, $new->eventos[0][0]);
        $this->assertArrayNotHasKey('event', $new->dados);
    }

    public function testDraftRemovalDiscardsImportsAndReleasesTheCap(): void
    {
        $draft = HouseRepository::insertDraft($this->userId);
        $draftId = (int) $draft['id'];
        $this->importRow($draftId, 'airbnb', 'airbnb:1414', 'draft_ready');
        $this->assertSame(1, OtaImportService::unverifiedDrafts($this->userId));

        $this->assertTrue(HouseService::remove($this->userId, $draftId));

        $row = HouseRepository::find($draftId);
        $this->assertSame('inactive', (string) ($row['status'] ?? ''));
        $this->assertSame(
            'discarded',
            (string) $this->pdo->query('SELECT status FROM property_ota_imports WHERE property_id = ' . $draftId . ' ORDER BY id DESC LIMIT 1')->fetchColumn()
        );
        $this->assertSame(0, OtaImportService::unverifiedDrafts($this->userId));
    }

    public function testStartNewDraftRejectsNotFoundListingWithoutCreatingRows(): void
    {
        $propertiesBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM properties')->fetchColumn();
        $importsBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM property_ota_imports')->fetchColumn();
        CalendarSync::$httpTransport = fn (): array => ['error' => 'http_4xx', 'http' => 404];

        $res = OtaImportService::startNewDraft(
            $this->userId,
            'airbnb',
            'https://www.airbnb.com/rooms/1616',
            true
        );

        $this->assertSame('invalido', $res->tipo);
        $this->assertSame(t('ota.import.url_not_found'), $res->mensagem);
        $this->assertSame('property_url', $res->dados['field'] ?? null);
        $this->assertSame($propertiesBefore, (int) $this->pdo->query('SELECT COUNT(*) FROM properties')->fetchColumn());
        $this->assertSame($importsBefore, (int) $this->pdo->query('SELECT COUNT(*) FROM property_ota_imports')->fetchColumn());
    }

    public function testListingHtmlFollowsSamePlatformRedirectsAndRejectsForeignHosts(): void
    {
        $calls = [];
        CalendarSync::$httpTransport = function (string $url) use (&$calls): array {
            $calls[] = $url;
            if ($url === 'https://www.airbnb.com/rooms/999?locale=en') {
                return ['http' => 301, 'location' => '/rooms/999'];
            }
            if ($url === 'https://www.airbnb.com/rooms/999') {
                return ['body' => '<html>ok</html>', 'http' => 200];
            }
            return ['error' => 'http_4xx', 'http' => 404];
        };
        $result = CalendarSync::fetchListingHtml('https://www.airbnb.com/rooms/999?locale=en', 'airbnb');
        $this->assertSame('<html>ok</html>', $result['body'] ?? null);
        $this->assertSame(['https://www.airbnb.com/rooms/999?locale=en', 'https://www.airbnb.com/rooms/999'], $calls);

        $calls = [];
        CalendarSync::$httpTransport = function (string $url) use (&$calls): array {
            $calls[] = $url;
            return ['http' => 302, 'location' => 'https://evil.example/rooms/1000'];
        };
        $blocked = CalendarSync::fetchListingHtml('https://www.airbnb.com/rooms/1000', 'airbnb');
        $this->assertSame('bad_url', $blocked['error'] ?? null);
        $this->assertCount(1, $calls);
    }

    public function testFailedImportDoesNotLockOrShowReviewAndDraftHidesPublicLink(): void
    {
        $draft = HouseRepository::insertDraft($this->userId);
        $draftId = (int) $draft['id'];
        $this->importRow($draftId, 'airbnb', 'airbnb:1717', 'failed');

        $property = HouseRepository::find($draftId);
        $imports = OtaImportService::importsForProperty($draftId);
        $photos = [];
        $csrf = 'csrf';
        $error = null;
        $import_error = null;
        $import_notice = null;
        $posted = [];
        ob_start();
        require dirname(__DIR__, 2) . '/views/property_form.php';
        $formHtml = (string) ob_get_clean();

        $this->assertStringNotContainsString(t('form.import.locked'), $formHtml);
        $this->assertStringNotContainsString(t('form.import.review_title'), $formHtml);
        $this->assertStringNotContainsString('is-view', $formHtml);
        $this->assertStringNotContainsString('Ver Anúncio', $formHtml);
        $this->assertSame(1, substr_count($formHtml, 'id="field-amenities"'));

        // Validation errors keep the pasted URL/platform in the import form.
        $property = null;
        $imports = [];
        $posted = ['platform' => 'airbnb', 'property_url' => 'https://www.airbnb.com/rooms/1818'];
        ob_start();
        require dirname(__DIR__, 2) . '/views/property_form.php';
        $newFormHtml = (string) ob_get_clean();
        $this->assertStringContainsString('value="https://www.airbnb.com/rooms/1818"', $newFormHtml);
        $this->assertMatchesRegularExpression('/<option value="airbnb" selected>/', $newFormHtml);

        // Calendar must not lock the URL for a failed import.
        $property = HouseRepository::find($draftId);
        $days = [];
        $feeds = [];
        $message = null;
        $message_tone = null;
        $ota_imports = OtaImportService::importsForProperty($draftId);
        ob_start();
        require dirname(__DIR__, 2) . '/views/calendar.php';
        $calendarHtml = (string) ob_get_clean();
        $this->assertStringContainsString('data-import-locks="[]"', $calendarHtml);
        $this->assertStringNotContainsString('airbnb:1717', $calendarHtml);
    }

    /** Pasted page source with canonical/OG/JSON-LD binding and an optional body payload. */
    private function pastedListingHtml(string $canonical, string $title = 'Casa Colada', string $description = 'Conteúdo colado.', array $images = [], string $extraBody = ''): string
    {
        $json = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'VacationRental',
            'name' => $title,
            'description' => $description,
            'image' => $images,
            'amenityFeature' => [['name' => 'Wifi'], ['name' => 'Piscina']],
            'occupancy' => ['maxValue' => 6],
        ], JSON_UNESCAPED_UNICODE);
        return '<html><head><link rel="canonical" href="' . $canonical . '">'
            . '<meta property="og:url" content="' . $canonical . '">'
            . '<script type="application/ld+json">' . $json . '</script></head><body>' . $extraBody . '</body></html>';
    }

    public function testBooking202ChallengeReturnsBlockedWithoutDraftOrCap(): void
    {
        $propertiesBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM properties')->fetchColumn();
        $importsBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM property_ota_imports')->fetchColumn();
        CalendarSync::$httpTransport = fn (): array => [
            'http' => 202,
            'headers' => ['x-page-id' => 'wildcard-challenge-handler'],
            'body' => '<html><head><title></title></head><body><script>reportChallengeError()</script></body></html>',
        ];

        $res = OtaImportService::startNewDraft($this->userId, 'booking', 'https://www.booking.com/hotel/br/casa-block.html', true);

        $this->assertSame('bloqueado_ota', $res->tipo);
        $this->assertSame('blocked_by_ota', $res->dados['error_code'] ?? null);
        $this->assertSame('property_url', $res->dados['field'] ?? null);
        $this->assertSame($propertiesBefore, (int) $this->pdo->query('SELECT COUNT(*) FROM properties')->fetchColumn());
        $this->assertSame($importsBefore, (int) $this->pdo->query('SELECT COUNT(*) FROM property_ota_imports')->fetchColumn());
        $this->assertSame(0, OtaImportService::unverifiedDrafts($this->userId));
    }

    public function testVrbo429ChallengeHeaderReturnsBlocked(): void
    {
        CalendarSync::$httpTransport = fn (): array => [
            'error' => 'http_5xx',
            'http' => 429,
            'headers' => ['x-hcom-origin-id' => 'wildcard-challenge-handler'],
        ];

        $res = OtaImportService::startNewDraft($this->userId, 'vrbo', 'https://www.vrbo.com/1234567', true);
        $this->assertSame('bloqueado_ota', $res->tipo);
        $this->assertSame('blocked_by_ota', $res->dados['error_code'] ?? null);
        $this->assertSame(0, OtaImportService::unverifiedDrafts($this->userId));
    }

    public function testHttp200WafShellIsBlockedBeforeCreatingADraft(): void
    {
        CalendarSync::$httpTransport = fn (): array => [
            'http' => 200,
            'body' => '<html><head><title></title></head><body><script>reportChallengeError()</script></body></html>',
        ];

        $res = OtaImportService::startNewDraft($this->userId, 'booking', 'https://www.booking.com/hotel/br/casa-waf.html', true);
        $this->assertSame('bloqueado_ota', $res->tipo);
        $this->assertSame(0, OtaImportService::unverifiedDrafts($this->userId));
    }

    public function testAirbnb200JsonLdStillImportsDirectlyIntoDraftReady(): void
    {
        CalendarSync::$httpTransport = fn (): array => ['body' => $this->listingHtml('Casa Airbnb Direta'), 'http' => 200];
        $draft = HouseRepository::insertDraft($this->userId);
        $draftId = (int) $draft['id'];

        $res = OtaImportService::startExisting(HouseRepository::find($draftId), $this->userId, 'airbnb', 'https://www.airbnb.com/rooms/2020', true);

        $this->assertSame('ok', $res->tipo);
        $import = OtaImportService::activeForProperty($draftId, 'airbnb');
        $this->assertSame('draft_ready', (string) $import['status']);
        $this->assertArrayNotHasKey('fetch_mode', (array) $import['sanitization_flags']);
        $this->assertSame('Casa Direta', (string) HouseRepository::find($draftId)['title']);
    }

    public function testBookingHtmlPasteCreatesDraftReadyLocksUrlAndQueuesOnlyAllowlistedPhotos(): void
    {
        $html = $this->pastedListingHtml(
            'https://www.booking.com/hotel/br/casa-colada.pt-br.html',
            'Casa Colada Booking',
            'Descrição colada do Booking.',
            [
                'https://cf.bstatic.com/xdata/images/hotel/max1024x768/1.jpg',
                'https://evil.example/x.jpg',
                'http://127.0.0.1/x.jpg',
            ]
        );

        $res = OtaImportService::startNewDraft(
            $this->userId,
            'booking',
            'https://www.booking.com/hotel/br/casa-colada.html',
            true,
            $html
        );

        $this->assertSame('ok', $res->tipo, (string) $res->mensagem);
        $propertyId = (int) ($res->dados['property_id'] ?? 0);
        $this->assertGreaterThan(0, $propertyId);
        $import = OtaImportService::activeForProperty($propertyId, 'booking');
        $this->assertSame('draft_ready', (string) $import['status']);
        $this->assertSame('html_paste', $import['sanitization_flags']['fetch_mode'] ?? null);
        $this->assertSame('Casa Colada', (string) HouseRepository::find($propertyId)['title']);

        $photos = PhotoRepository::listByImport((int) $import['id']);
        $this->assertCount(1, $photos);
        $this->assertSame('https://cf.bstatic.com/xdata/images/hotel/max1024x768/1.jpg', (string) $photos[0]['source_url']);

        // URL is locked to the pasted canonical listing id.
        try {
            OtaFeedGate::preparePublicUrl($propertyId, $this->userId, 'booking', 'https://www.booking.com/hotel/br/outra-casa.html', true);
            $this->fail('different canonical must be locked');
        } catch (OtaFeedGateException $e) {
            $this->assertSame('property_url', $e->field);
        }
        OtaFeedGate::preparePublicUrl($propertyId, $this->userId, 'booking', 'https://www.booking.com/hotel/br/casa-colada.html', true);
    }

    public function testVrboRegionalDomainHtmlPasteIsAccepted(): void
    {
        $html = $this->pastedListingHtml(
            'https://www.aluguetemporada.com.br/pt-br/imovel/casa-colada/7654321',
            'Casa Colada Vrbo',
            'Descrição colada do Vrbo.',
            ['https://images.trvl-media.com/lodging/1.jpg']
        );

        $res = OtaImportService::startNewDraft($this->userId, 'vrbo', 'https://www.vrbo.com/7654321', true, $html);

        $this->assertSame('ok', $res->tipo, (string) $res->mensagem);
        $propertyId = (int) ($res->dados['property_id'] ?? 0);
        $import = OtaImportService::activeForProperty($propertyId, 'vrbo');
        $this->assertSame('html_paste', $import['sanitization_flags']['fetch_mode'] ?? null);
        $photos = PhotoRepository::listByImport((int) $import['id']);
        $this->assertCount(1, $photos);
        $this->assertStringContainsString('images.trvl-media.com', (string) $photos[0]['source_url']);
    }

    public function testSpoofedPasteWithAnotherListingCanonicalIsRejectedWithoutRows(): void
    {
        $draft = HouseRepository::insertDraft($this->userId);
        $draftId = (int) $draft['id'];
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM property_ota_imports WHERE property_id = ' . $draftId)->fetchColumn();
        $html = $this->pastedListingHtml('https://www.booking.com/hotel/br/outra-casa.html');

        $res = OtaImportService::startExisting(
            HouseRepository::find($draftId),
            $this->userId,
            'booking',
            'https://www.booking.com/hotel/br/casa-colada.html',
            true,
            $html
        );

        $this->assertSame('invalido', $res->tipo);
        $this->assertSame('html_url_mismatch', $res->dados['error_code'] ?? null);
        $this->assertSame('raw_html', $res->dados['field'] ?? null);
        $this->assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM property_ota_imports WHERE property_id = ' . $draftId)->fetchColumn());
    }

    public function testPasteFromAnotherPlatformIsRejectedAsMismatch(): void
    {
        $html = $this->pastedListingHtml('https://www.airbnb.com/rooms/3333333');

        $res = OtaImportService::startNewDraft(
            $this->userId,
            'booking',
            'https://www.booking.com/hotel/br/casa-colada.html',
            true,
            $html
        );

        $this->assertSame('invalido', $res->tipo);
        $this->assertSame('html_url_mismatch', $res->dados['error_code'] ?? null);
    }

    public function testPastedHtmlStripsScriptsAndIgnoresNonAllowlistedImages(): void
    {
        $html = $this->pastedListingHtml(
            'https://www.airbnb.com/rooms/4444444',
            '<script>alert(1)</script>Casa Boa',
            '<b>Bom</b> anúncio https://evil.example/x',
            ['https://evil.example/a.jpg', 'https://a0.muscache.com/im/pictures/ok.jpg']
        );
        $draft = HouseRepository::insertDraft($this->userId);
        $draftId = (int) $draft['id'];

        $res = OtaImportService::startExisting(HouseRepository::find($draftId), $this->userId, 'airbnb', 'https://www.airbnb.com/rooms/4444444', true, $html);

        $this->assertSame('ok', $res->tipo, (string) $res->mensagem);
        $row = HouseRepository::find($draftId);
        $this->assertStringNotContainsString('<script', (string) $row['title']);
        $this->assertStringContainsString('Casa Boa', (string) $row['title']);
        $this->assertStringNotContainsString('evil.example', (string) $row['description']);

        $import = OtaImportService::activeForProperty($draftId, 'airbnb');
        $photos = PhotoRepository::listByImport((int) $import['id']);
        $this->assertCount(1, $photos);
        $this->assertSame('https://a0.muscache.com/im/pictures/ok.jpg', (string) $photos[0]['source_url']);
    }

    public function testOversizedAndWafShellPastesAreRejectedCleanly(): void
    {
        $propertiesBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM properties')->fetchColumn();
        $oversized = $this->pastedListingHtml(
            'https://www.booking.com/hotel/br/casa-grande.html',
            'Casa Grande',
            'Descrição.',
            [],
            str_repeat('x', OtaImportService::MAX_RAW_HTML_BYTES + 1)
        );
        $tooBig = OtaImportService::startNewDraft($this->userId, 'booking', 'https://www.booking.com/hotel/br/casa-grande.html', true, $oversized);
        $this->assertSame('invalido', $tooBig->tipo);
        $this->assertSame('payload_too_large', $tooBig->dados['error_code'] ?? null);

        $shell = '<html><head><title></title></head><body><script>reportChallengeError()</script></body></html>';
        $waf = OtaImportService::startNewDraft($this->userId, 'booking', 'https://www.booking.com/hotel/br/casa-waf.html', true, $shell);
        $this->assertSame('invalido', $waf->tipo);
        $this->assertSame('extraction_empty', $waf->dados['error_code'] ?? null);
        $this->assertSame($propertiesBefore, (int) $this->pdo->query('SELECT COUNT(*) FROM properties')->fetchColumn());
    }

    public function testPublishedGateAndUrlLockStillApplyToHtmlPaste(): void
    {
        $html222 = $this->pastedListingHtml('https://www.airbnb.com/rooms/222');
        $blocked = OtaImportService::startExisting(
            HouseRepository::find($this->propertyId),
            $this->userId,
            'airbnb',
            'https://www.airbnb.com/rooms/222',
            true,
            $html222
        );
        $this->assertSame('invalido', $blocked->tipo);
        $this->assertSame(t('ota.import.published_locked'), $blocked->mensagem);

        $this->feedRow($this->propertyId, 'airbnb', 'airbnb:222', 'approved', $this->now);
        $locked = OtaImportService::startExisting(
            HouseRepository::find($this->propertyId),
            $this->userId,
            'airbnb',
            'https://www.airbnb.com/rooms/111',
            true,
            $this->pastedListingHtml('https://www.airbnb.com/rooms/111')
        );
        $this->assertSame('invalido', $locked->tipo);
        $this->assertSame(t('ota.err_import_url_locked'), $locked->mensagem);

        $ok = OtaImportService::startExisting(
            HouseRepository::find($this->propertyId),
            $this->userId,
            'airbnb',
            'https://www.airbnb.com/rooms/222',
            true,
            $this->pastedListingHtml('https://www.airbnb.com/rooms/222')
        );
        $this->assertSame('ok', $ok->tipo, (string) $ok->mensagem);
        $import = OtaImportService::activeForProperty($this->propertyId, 'airbnb');
        $this->assertSame('draft_ready', (string) $import['status']);
        $this->assertSame('html_paste', $import['sanitization_flags']['fetch_mode'] ?? null);
    }

    public function testPasteWithItemListJsonLdReferencingAnotherListingStillSucceeds(): void
    {
        $html = '<html><head><link rel="canonical" href="https://www.airbnb.com/rooms/5050505">'
            . '<script type="application/ld+json">' . json_encode([
                '@graph' => [
                    [
                        '@type' => 'VacationRental',
                        '@id' => 'https://www.airbnb.com/rooms/5050505',
                        'url' => 'https://www.airbnb.com/rooms/5050505',
                        'name' => 'Casa com ItemList',
                        'description' => 'Descrição da casa.',
                        'image' => ['https://a0.muscache.com/im/pictures/itemlist.jpg'],
                    ],
                    [
                        '@type' => 'ItemList',
                        'url' => 'https://www.airbnb.com/rooms/6060606',
                        'itemListElement' => [
                            ['@type' => 'ListItem', 'url' => 'https://www.airbnb.com/rooms/7070707'],
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE) . '</script></head><body></body></html>';

        $res = OtaImportService::startNewDraft($this->userId, 'airbnb', 'https://www.airbnb.com/rooms/5050505', true, $html);

        $this->assertSame('ok', $res->tipo, (string) $res->mensagem);
        $propertyId = (int) ($res->dados['property_id'] ?? 0);
        $import = OtaImportService::activeForProperty($propertyId, 'airbnb');
        $this->assertSame('draft_ready', (string) $import['status']);
        $this->assertSame('html_paste', $import['sanitization_flags']['fetch_mode'] ?? null);
    }

    public function testFetchImageBytesDoesNotAdvertiseUnsupportedAvifAcceptHeader(): void
    {
        $seenAccept = null;
        CalendarSync::$httpTransport = function (string $url, int $maxBytes, string $accept = '') use (&$seenAccept): array {
            $seenAccept = $accept;
            return ['body' => $this->jpegBytes(), 'http' => 200];
        };

        $res = CalendarSync::fetchImageBytes('https://a0.muscache.com/im/pictures/test.jpeg');
        $this->assertArrayHasKey('body', $res);
        $this->assertNotNull($seenAccept);
        $this->assertStringNotContainsString('image/avif', strtolower((string) $seenAccept));
        $this->assertStringContainsString('image/webp', strtolower((string) $seenAccept));
        $this->assertStringContainsString('image/jpeg', strtolower((string) $seenAccept));
        $this->assertStringContainsString('image/png', strtolower((string) $seenAccept));
    }

    public function testAirbnbLayeredImportPopulatesGuestsBedroomsBedsAmenitiesAndFullPhotos(): void
    {
        $html = '<html><head>'
            . '<script type="application/ld+json">' . json_encode([
                '@type' => 'VacationRental',
                'name' => 'Vista Livre Pinheiros',
                'description' => 'Apartamento completo com piscina.',
                'image' => ['https://a0.muscache.com/im/pictures/cover.jpeg'],
                'containsPlace' => ['@type' => 'Accommodation', 'occupancy' => ['value' => 3]],
            ], JSON_UNESCAPED_UNICODE) . '</script>'
            . '<script id="data-deferred-state-0" type="application/json">' . json_encode([
                'niobeClientData' => [[
                    'StaysPdpSections:{}',
                    [
                        'data' => [
                            'node' => [
                                '__typename' => 'DemandStayListing',
                                'personCapacity' => 3,
                                'pdpPresentation' => [
                                    'overview' => ['__typename' => 'StaysPdpOverview', 'items' => ['3 hóspedes', '2 quartos', '3 camas']],
                                    'amenities' => [
                                        'seeAllAmenitiesGroups' => [[
                                            'amenities' => [
                                                ['__typename' => 'AmenityItem', 'available' => true, 'title' => 'Wi-Fi', 'icon' => 'SYSTEM_WI_FI'],
                                                ['__typename' => 'AmenityItem', 'available' => true, 'title' => 'Piscina', 'icon' => 'SYSTEM_POOL'],
                                                ['__typename' => 'AmenityItem', 'available' => true, 'title' => 'Máquina de lavar', 'icon' => 'SYSTEM_WASHER'],
                                                ['__typename' => 'AmenityItem', 'available' => false, 'title' => 'Detector de fumaça', 'icon' => 'SYSTEM_NO_DETECTOR_SMOKE'],
                                            ],
                                        ]],
                                    ],
                                    'mediaTour' => [
                                        '__typename' => 'MediaTour',
                                        'name' => 'Tour por fotos',
                                        'stops' => [[
                                            'items' => [
                                                ['image' => ['uri' => 'https://a0.muscache.com/im/pictures/cover.jpeg']],
                                                ['image' => ['uri' => 'https://a0.muscache.com/im/pictures/extra1.jpeg']],
                                                ['image' => ['uri' => 'https://a0.muscache.com/im/pictures/extra2.png']],
                                            ],
                                        ]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]],
            ], JSON_UNESCAPED_UNICODE) . '</script>'
            . '</head></html>';

        CalendarSync::$httpTransport = fn (): array => ['body' => $html, 'http' => 200];

        $res = OtaImportService::startNewDraft($this->userId, 'airbnb', 'https://www.airbnb.com.br/rooms/1763202099314111379', true);
        $this->assertSame('ok', $res->tipo, (string) $res->mensagem);

        $propertyId = (int) ($res->dados['property_id'] ?? 0);
        $row = HouseRepository::find($propertyId);
        $this->assertSame(3, (int) $row['max_guests']);
        $this->assertSame(2, (int) $row['bedrooms']);
        $this->assertSame(3, (int) $row['beds']);
        $this->assertSame(1, (int) $row['pool']);

        $amenities = json_decode((string) $row['amenities'], true);
        $this->assertSame(1, $amenities['wifi'] ?? null);
        $this->assertSame(1, $amenities['pool'] ?? null);
        $this->assertSame(1, $amenities['washer'] ?? null);
        $this->assertArrayNotHasKey('smoke', $amenities);

        $photos = PhotoRepository::listByImport((int) ($res->dados['import_id'] ?? 0));
        $this->assertCount(3, $photos);
        $this->assertSame('https://a0.muscache.com/im/pictures/cover.jpeg', (string) $photos[0]['source_url']);
        $this->assertSame(1, (int) $photos[0]['is_primary']);
    }

    public function testBookingHtmlPasteUpgradesSmallPhotoPathAndImportsFacilitiesAndGallery(): void
    {
        $canonical = 'https://www.booking.com/hotel/br/ap-aconchegante-morumbi.html';
        $html = '<html><head>'
            . '<link rel="canonical" href="' . $canonical . '">'
            . '<script type="application/ld+json">' . json_encode([
                '@type' => 'Apartment',
                'name' => 'Ap 910 Morumbi',
                'description' => 'Apartamento com piscina.',
                'image' => 'https://cf.bstatic.com/xdata/images/hotel/max500/590115980.jpg?k=abc&o=&hp=1',
            ], JSON_UNESCAPED_UNICODE) . '</script>'
            . '<script>var x = { hotelPhotos: ['
            . "{id: '590115981', large_url: 'https://cf.bstatic.com/xdata/images/hotel/max500/590115981.jpg?k=def&o='}"
            . '] };</script>'
            . '</head><body>'
            . '<div data-testid="property-most-popular-facilities-wrapper"><ul>'
            . '<li><span>Wi-Fi gratuito</span></li>'
            . '<li><span>Piscina ao ar livre</span></li>'
            . '<li><span>Ar-condicionado</span></li>'
            . '</ul></div>'
            . '</body></html>';

        $res = OtaImportService::startNewDraft($this->userId, 'booking', $canonical, true, $html);
        $this->assertSame('ok', $res->tipo, (string) $res->mensagem);

        $propertyId = (int) ($res->dados['property_id'] ?? 0);
        $row = HouseRepository::find($propertyId);
        $this->assertSame(1, (int) $row['pool']);
        $amenities = json_decode((string) $row['amenities'], true);
        $this->assertSame(1, $amenities['wifi'] ?? null);
        $this->assertSame(1, $amenities['pool'] ?? null);
        $this->assertSame(1, $amenities['ac'] ?? null);

        $photos = PhotoRepository::listByImport((int) ($res->dados['import_id'] ?? 0));
        $this->assertCount(2, $photos);
        $this->assertSame('https://cf.bstatic.com/xdata/images/hotel/max1024x768/590115980.jpg?k=abc&o=&hp=1', (string) $photos[0]['source_url']);
        $this->assertSame('https://cf.bstatic.com/xdata/images/hotel/max1024x768/590115981.jpg?k=def&o=', (string) $photos[1]['source_url']);
    }

    public function testBookingImportedPhotosStripExifXmpComDeleteOriginalAndHideSourceUrl(): void
    {
        require_once dirname(__DIR__, 2) . '/src/Api.php';

        $draft = HouseRepository::insertDraft($this->userId);
        $draftId = (int) $draft['id'];
        $importId = $this->importRow($draftId, 'booking', 'booking:br/ap-morumbi');

        $jpeg = $this->jpegBytes(false, 200, 150);
        $comPayload = "Booking.com Property 590115980 - Copyright Booking Holdings\x00";
        $xmpPayload = "http://ns.adobe.com/xap/1.0/\x00<x:xmpmeta><rdf:Description>Booking.com GPS -23.5689,-46.6871</rdf:Description></x:xmpmeta>";
        $iccPayload = "ICC_PROFILE\x00\x01\x01BookingColorProfile";
        $segments = "\xFF\xFE" . pack('n', strlen($comPayload) + 2) . $comPayload
            . "\xFF\xE1" . pack('n', strlen($xmpPayload) + 2) . $xmpPayload
            . "\xFF\xE2" . pack('n', strlen($iccPayload) + 2) . $iccPayload;
        $taintedJpeg = substr($jpeg, 0, 2) . $segments . substr($jpeg, 2);

        CalendarSync::$httpTransport = fn (): array => ['body' => $taintedJpeg, 'http' => 200];

        $uuid = PhotoStorage::randomId();
        $sourceUrl = 'https://cf.bstatic.com/xdata/images/hotel/max1024x768/590115980.jpg?k=bookingsecret&o=';
        $row = PhotoRepository::insertProcessing($draftId, $uuid, null, 1, 0, [
            'source_url' => $sourceUrl,
            'source_url_hash' => hash('sha256', $sourceUrl),
            'ota_import_id' => $importId,
        ]);
        $photoId = (int) $row['id'];

        $this->assertTrue(ImageWorker::process($photoId));

        $ready = PhotoRepository::findById($photoId);
        $this->assertSame('ready', (string) $ready['status']);
        $this->assertNull($ready['original']);
        $this->assertMatchesRegularExpression('#^/uploads/fotos/[0-9a-f-]{36}-1920\.webp$#', (string) $ready['path']);
        $this->assertStringNotContainsString('590115980', (string) $ready['path']);
        $this->assertStringNotContainsString('booking', strtolower((string) $ready['path']));

        $origLeftovers = glob(PhotoStorage::$originals . '/*') ?: [];
        $this->assertSame([], $origLeftovers, 'Downloaded original must be deleted after WebP generation');

        foreach (ImageWorker::SIZES as $width) {
            $variantFile = PhotoStorage::variantFile($uuid, $width);
            $this->assertFileExists($variantFile);
            $info = @getimagesize($variantFile);
            $this->assertIsArray($info);
            $this->assertSame(IMAGETYPE_WEBP, $info[2]);

            $webp = (string) file_get_contents($variantFile);
            $this->assertSame('RIFF', substr($webp, 0, 4));
            $this->assertSame('WEBP', substr($webp, 8, 4));
            foreach (['Booking', '590115980', 'bookingsecret', 'cf.bstatic', 'xmpmeta', 'ICC_PROFILE', 'Copyright', 'Exif'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $webp);
            }
        }

        $publicPhotos = api_public_photos(PhotoRepository::listReady($draftId));
        $this->assertCount(1, $publicPhotos);
        $this->assertSame(['path', 'is_primary', 'sort_order'], array_keys($publicPhotos[0]));
        $this->assertSame((string) $ready['path'], $publicPhotos[0]['path']);
    }

    public function testPruneOrphanOriginalsRemovesStaleUnreferencedAndRemoteTempFilesOnly(): void
    {
        $now = self::T0;
        $staleOrphan = PhotoStorage::randomId() . '.jpg';
        $freshOrphan = PhotoStorage::randomId() . '.jpg';
        $activeProcessing = PhotoStorage::randomId() . '.jpg';
        $staleTemp = 'cl-remote-deadbeef';

        file_put_contents(PhotoStorage::$originals . '/' . $staleOrphan, 'x');
        file_put_contents(PhotoStorage::$originals . '/' . $freshOrphan, 'x');
        file_put_contents(PhotoStorage::$originals . '/' . $activeProcessing, 'x');
        file_put_contents(PhotoStorage::$originals . '/' . $staleTemp, 'x');

        touch(PhotoStorage::$originals . '/' . $staleOrphan, $now - 3600);
        touch(PhotoStorage::$originals . '/' . $freshOrphan, $now - 60);
        touch(PhotoStorage::$originals . '/' . $activeProcessing, $now - 3600);
        touch(PhotoStorage::$originals . '/' . $staleTemp, $now - 3600);

        PhotoRepository::insertProcessing($this->propertyId, substr($activeProcessing, 0, 36), $activeProcessing, 0, 0);

        $removed = PhotoStorage::pruneOrphanOriginals(PhotoRepository::STUCK_AFTER_SECONDS, $now);

        $this->assertSame(2, $removed);
        $this->assertFileDoesNotExist(PhotoStorage::$originals . '/' . $staleOrphan);
        $this->assertFileDoesNotExist(PhotoStorage::$originals . '/' . $staleTemp);
        $this->assertFileExists(PhotoStorage::$originals . '/' . $freshOrphan);
        $this->assertFileExists(PhotoStorage::$originals . '/' . $activeProcessing);
    }

    public function testBookingHtmlPasteUsesPageDescriptionOverEnglishJsonLd(): void
    {
        $canonical = 'https://www.booking.com/hotel/br/ap-aconchegante-morumbi.html';
        $html = '<html><head>'
            . '<link rel="canonical" href="' . $canonical . '">'
            . '<script type="application/ld+json">' . json_encode([
                '@type' => 'Apartment',
                'name' => 'Ap 910 Morumbi',
                'description' => 'This apartment offers accommodations with a terrace and free Wifi.',
                'image' => 'https://cf.bstatic.com/xdata/images/hotel/max500/590115980.jpg?k=abc&o=',
            ], JSON_UNESCAPED_UNICODE) . '</script></head><body>'
            . '<div id="property_description_content"><p>Apartamento aconchegante no Morumbi, perto do estádio.</p></div>'
            . '</body></html>';

        $res = OtaImportService::startNewDraft($this->userId, 'booking', $canonical, true, $html);
        $this->assertSame('ok', $res->tipo, (string) $res->mensagem);

        $row = HouseRepository::find((int) ($res->dados['property_id'] ?? 0));
        $this->assertSame('Apartamento aconchegante no Morumbi, perto do estádio.', (string) $row['description']);
    }
}

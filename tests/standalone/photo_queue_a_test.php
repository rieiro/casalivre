<?php

declare(strict_types=1);

// Queue-A: stuck processing photos, permanent image failures, upload reasons, failed photo in the list.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/PhotoStorage.php';
require_once CL_ROOT . '/src/PhotoRepository.php';
require_once CL_ROOT . '/src/PhotoFail.php';
require_once CL_ROOT . '/src/PermanentJobFailure.php';
require_once CL_ROOT . '/src/ImageWorker.php';
require_once CL_ROOT . '/src/QueueWorker.php';
require_once CL_ROOT . '/src/QueueWorkerLoop.php';
require_once CL_ROOT . '/src/ActivityLog.php';
require_once CL_ROOT . '/src/Settings.php';

t_temp_db();
$owner = t_seed_owner();
$hid = (int) $owner['house_id'];
$tmp = sys_get_temp_dir() . '/cl-qa-' . bin2hex(random_bytes(3));
mkdir($tmp, 0700);
PhotoStorage::$originals = $tmp;
PhotoStorage::$acceptFile = static fn (string $f): bool => is_file($f);
PhotoStorage::$moveFile = static fn (string $from, string $to): bool => copy($from, $to);

$jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBxMTEhUTExMWFRUXGBcYGBgYGBoYGBgYGBgYGBgYGBgYHSggGBolHRgXITEhJSkrLi4uFx8zODMtNygtLisBCgoKDg0OGxAQGy0lHyUtLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLf/AABEIAQABAAMBIgACEQEDEQH/xAAbAAACAgMBAAAAAAAAAAAAAAADBAECBQAGB//EABQBAQAAAAAAAAAAAAAAAAAAAAD/xAAUAQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAGf/9k=', true);
$gif = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;";
$ihdr = pack('NNCCCCC', 6000, 5000, 8, 2, 0, 0, 0);
$pngBomb = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr)) . str_repeat("\0", 64);

$photo = static function (string $status, string $original, string $age) use ($hid): int {
    $uuid = PhotoStorage::randomId();
    $st = db()->prepare('INSERT INTO photos (property_id, path, status, uuid, original, created_at) VALUES (?, ?, ?, ?, ?, ' . $age . ') RETURNING id');
    $st->execute([$hid, '', $status, $uuid, $original]);
    return (int) $st->fetchColumn();
};

echo "stuck sweep\n";
$oldName = PhotoStorage::randomId() . '.jpg';
$freshName = PhotoStorage::randomId() . '.jpg';
file_put_contents($tmp . '/' . $oldName, $jpeg);
file_put_contents($tmp . '/' . $freshName, $jpeg);
$oldId = $photo('processing', $oldName, "now() - interval '31 minutes'");
$freshId = $photo('processing', $freshName, 'now()');
$readyName = PhotoStorage::randomId() . '.jpg';
file_put_contents($tmp . '/' . $readyName, $jpeg);
$readyId = $photo('ready', $readyName, "now() - interval '2 hours'");
$log = tempnam(sys_get_temp_dir(), 'qa-log');
$prevLog = ini_get('error_log');
ini_set('error_log', $log);
QueueWorkerLoop::sweepStuck();
ini_set('error_log', (string) $prevLog);
$old = PhotoRepository::findById($oldId);
$fresh = PhotoRepository::findById($freshId);
$ready = PhotoRepository::findById($readyId);
t_eq(['failed', PhotoFail::STUCK, null], [$old['status'], $old['fail_reason'], $old['original']], 'old processing row is failed with stuck');
t_ok(!is_file($tmp . '/' . $oldName), 'old original deleted');
t_eq(['processing', $freshName], [$fresh['status'], $fresh['original']], 'fresh processing row untouched');
t_ok(is_file($tmp . '/' . $freshName), 'fresh original kept');
t_eq('ready', $ready['status'], 'ready row untouched');
t_ok(is_file($tmp . '/' . $readyName), 'ready file kept');
t_ok(str_contains((string) file_get_contents($log), 'ALERT') && str_contains((string) file_get_contents($log), PhotoFail::STUCK), 'stuck sweep logs an ALERT');
$logged = (int) db()->query('SELECT COUNT(*) FROM audit.audit_log WHERE code = ' . ActivityLog::PHOTO_STUCK)->fetchColumn();
t_eq(1, $logged, 'stuck sweep writes PHOTO_STUCK');
@unlink($log);

echo "permanent reasons\n";
$cases = [
    PhotoFail::MISSING_ORIGINAL => '',
    PhotoFail::CORRUPT => 'not-an-image',
    PhotoFail::INVALID_TYPE => $gif,
    PhotoFail::TOO_MANY_PIXELS => $pngBomb,
];
foreach ($cases as $reason => $bytes) {
    $name = PhotoStorage::randomId() . '.jpg';
    $id = $photo('processing', $name, 'now()');
    if ($bytes !== '') {
        file_put_contents($tmp . '/' . $name, $bytes);
    }
    $caught = null;
    try {
        ImageWorker::process($id);
    } catch (Throwable $e) {
        $caught = $e;
    }
    t_ok($caught instanceof PermanentJobFailure && $caught->reason === $reason, $reason . ' is permanent');
    db()->prepare('DELETE FROM photos WHERE id = ?')->execute([$id]);
    @unlink($tmp . '/' . $name);
}

echo "sql queue: permanent once, transient retries\n";
$lock = $tmp . '/worker.lock';
$missing = $photo('processing', PhotoStorage::randomId() . '.jpg', 'now()');
$queue = new DbImageQueue(db());
$queue->push($missing);
$rep = ImageWorker::run($queue, $lock, 5, 30);
t_eq(1, $rep['desistencias'] ?? null, 'permanent failure gives up on the first run');
$job = db()->query('SELECT status, attempts, last_error FROM image_queue ORDER BY id DESC LIMIT 1')->fetch();
t_eq('failed', $job['status'], 'sql job failed');
t_eq(0, (int) $job['attempts'], 'permanent failure does not count an attempt');
t_ok(str_contains((string) $job['last_error'], 'PermanentJobFailure') && str_contains((string) $job['last_error'], PhotoFail::MISSING_ORIGINAL), 'sql last_error has the class and the reason code');
t_eq([PhotoFail::MISSING_ORIGINAL, 'failed'], [PhotoRepository::findById($missing)['fail_reason'], PhotoRepository::findById($missing)['status']], 'photo failed with the reason code');

$again = $photo('processing', PhotoStorage::randomId() . '.jpg', 'now()');
file_put_contents($tmp . '/' . PhotoRepository::findById($again)['original'], $jpeg);
$queue->push($again);
$tries = 0;
$rep = QueueWorker::run($queue, $lock, static function () use (&$tries): bool {
    $tries++;
    throw new RuntimeException('disk');
}, 1, 7, 30);
t_eq(1, $tries, 'transient error is attempted');
t_eq(0, $rep['desistencias'] ?? null, 'first transient failure is not a give-up');
$pending = db()->query("SELECT status, attempts FROM image_queue WHERE photo_id = $again")->fetch();
t_eq(['pending', 1], [$pending['status'], (int) $pending['attempts']], 'transient failure stays pending with one attempt');

echo "valkey path: same QueueWorker, stub queue (no Valkey in this environment)\n";
final class RecordingQueue implements ImageQueue
{
    /** @var list<array{id:int, photo_id:int, attempts:int}> */
    public array $pending = [];
    /** @var list<array{op:string, id:int, error:string}> */
    public array $calls = [];

    public function push(int $photoId): bool
    {
        $this->pending[] = ['id' => count($this->pending) + 1, 'photo_id' => $photoId, 'attempts' => 0];
        return true;
    }

    public function reserve(int $limit): array
    {
        $job = array_shift($this->pending);
        return $job === null ? [] : [$job];
    }

    public function ack(int $id): void
    {
        $this->calls[] = ['op' => 'ack', 'id' => $id, 'error' => ''];
    }

    public function fail(int $id, string $error = ''): bool
    {
        $this->calls[] = ['op' => 'fail', 'id' => $id, 'error' => $error];
        return false;
    }

    public function giveUp(int $id, string $error = 'stale'): void
    {
        $this->calls[] = ['op' => 'giveUp', 'id' => $id, 'error' => $error];
    }

    public function purge(int $days): int
    {
        return 0;
    }
}
$stub = new RecordingQueue();
$stub->push(1);
QueueWorker::run($stub, $tmp . '/stub-permanent.lock', static function (): bool {
    throw new PermanentJobFailure(PhotoFail::CORRUPT);
}, 1, 7, 30);
t_eq(['giveUp'], array_column($stub->calls, 'op'), 'stub (Valkey-shaped) giveUp once, no fail()');
t_ok(str_contains($stub->calls[0]['error'], 'PermanentJobFailure') && str_contains($stub->calls[0]['error'], PhotoFail::CORRUPT), 'stub error has the class and the reason code');
$stub2 = new RecordingQueue();
$stub2->push(2);
QueueWorker::run($stub2, $tmp . '/stub-transient.lock', static function (): bool {
    throw new RuntimeException('disk');
}, 1, 7, 30);
t_eq(['fail'], array_column($stub2->calls, 'op'), 'stub transient calls fail(), not giveUp()');

echo "upload rejection reasons\n";
$room = PhotoRepository::count($hid) + 1;
db()->prepare("UPDATE settings SET value = ? WHERE skey = 'max_photos_per_property'")->execute([(string) $room]);
Settings::refresh();
Settings::clearCache();
$up = ['name' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
$add = static function (string $name, string $bytes, int $size, int $error = UPLOAD_ERR_OK) use (&$up, $tmp): void {
    $path = $tmp . '/in-' . count($up['name']);
    file_put_contents($path, $bytes);
    $up['name'][] = $name;
    $up['tmp_name'][] = $path;
    $up['error'][] = $error;
    $up['size'][] = $size;
};
$add('ok.jpg', $jpeg, strlen($jpeg));
$add('big.jpg', $jpeg, PhotoStorage::MAX_UPLOAD_BYTES + 1);
$add('clip.heic', 'not-heic', 20);
$add('extra.jpg', $jpeg, strlen($jpeg));
$created = [];
$stored = PhotoStorage::storeUploads((int) $owner['user_id'], $hid, $up, $created);
t_eq(1, $stored['saved'], 'one jpeg stored under the limit');
t_eq(
    [PhotoFail::TOO_LARGE, PhotoFail::WRONG_TYPE, PhotoFail::OVER_LIMIT],
    array_column($stored['rejected'], 'reason'),
    'too large, wrong type, then over the limit'
);

echo "web and API show the reason\n";
$base = t_server();
$jar = tempnam(sys_get_temp_dir(), 'jar');
$csrf = t_login($base, $jar, $owner['email'], $owner['password']);
$body = "--b\r\nContent-Disposition: form-data; name=\"fotos[0]\"; filename=\"ferias.heic\"\r\nContent-Type: image/heic\r\n\r\nnot-an-image\r\n--b--\r\n";
$api = t_http('POST', $base . '/api/casas/' . $owner['hash'] . '/fotos', $body, ['Content-Type: multipart/form-data; boundary=b', 'X-CSRF-Token: ' . $csrf], $jar);
$json = json_decode($api['body'], true) ?: [];
t_eq(422, $api['status'], 'API rejects the bad file');
t_eq([['name' => 'ferias.heic', 'reason' => PhotoFail::WRONG_TYPE]], $json['rejeitadas'] ?? null, 'API JSON has the reason');
t_ok(str_contains((string) ($json['error'] ?? ''), 'HEIC'), 'API error mentions HEIC');
$webBody = "--w\r\nContent-Disposition: form-data; name=\"_csrf\"\r\n\r\n$csrf\r\n" . str_replace('--b', '--w', $body);
$web = t_http('POST', $base . '/imoveis/' . $owner['hash'] . '/fotos', $webBody, ['Content-Type: multipart/form-data; boundary=w'], $jar);
t_eq(302, $web['status'], 'web upload redirects');
$edit = t_http('GET', $base . '/imoveis/' . $owner['hash'] . '/editar', null, [], $jar);
t_ok(str_contains($edit['body'], 'HEIC'), 'web edit page shows the HEIC reason');

echo "failed photo in the list\n";
$failed = $photo('failed', '', 'now()');
db()->prepare('UPDATE photos SET fail_reason = ? WHERE id = ?')->execute([PhotoFail::INVALID_TYPE, $failed]);
$page = t_http('GET', $base . '/imoveis/' . $owner['hash'] . '/editar', null, [], $jar);
t_ok(str_contains($page['body'], t('photo.fail_invalid_type')), 'edit page shows the failed reason');
t_ok(str_contains($page['body'], '/fotos/' . $failed . '/excluir') && str_contains($page['body'], t('form.photo_remove')), 'failed photo has a remove action');
db()->prepare("UPDATE settings SET value = '40' WHERE skey = 'max_photos_per_property'")->execute();
Settings::refresh();
Settings::clearCache();

t_done();

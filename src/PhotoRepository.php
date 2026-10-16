<?php

declare(strict_types=1);

require_once __DIR__ . '/ReturningWrite.php';

final class PhotoRepository
{
    public static function list(int $propertyId): array
    {
        $stmt = db()->prepare('SELECT * FROM photos WHERE property_id = ? ORDER BY is_primary DESC, sort_order ASC, id ASC');
        $stmt->execute([$propertyId]);
        return $stmt->fetchAll();
    }

    /** A foto é deste imóvel? Uma consulta pontual (sem carregar a lista inteira). */
    public static function belongsTo(int $propertyId, int $photoId): bool
    {
        $stmt = db()->prepare('SELECT 1 FROM photos WHERE id = ? AND property_id = ?');
        $stmt->execute([$photoId, $propertyId]);
        return $stmt->fetchColumn() !== false;
    }

    /** Só fotos prontas (público). */
    public static function listReady(int $propertyId): array
    {
        $stmt = db()->prepare("SELECT * FROM photos WHERE property_id = ? AND status = 'ready' ORDER BY is_primary DESC, sort_order ASC, id ASC");
        $stmt->execute([$propertyId]);
        return $stmt->fetchAll();
    }

    public static function findById(int $photoId): ?array
    {
        $stmt = db()->prepare('SELECT * FROM photos WHERE id = ?');
        $stmt->execute([$photoId]);
        $row = $stmt->fetch();
        $stmt->closeCursor();
        return $row ?: null;
    }

    public static function insertProcessing(int $propertyId, string $uuid, string $original, int $primary, int $sort): array
    {
        $rows = ReturningWrite::insert('photos', [
            'property_id' => $propertyId,
            'path' => '',
            'is_primary' => $primary,
            'sort_order' => $sort,
            'status' => 'processing',
            'uuid' => $uuid,
            'original' => $original,
        ], ['id', 'property_id', 'path', 'is_primary', 'sort_order', 'status']);
        return $rows[0]['new'] ?? [];
    }

    /** false se a foto deixou de estar em processing (removida ou imóvel inativado no meio). */
    public static function markReady(int $photoId, string $path): bool
    {
        $stmt = db()->prepare("UPDATE photos SET status = 'ready', path = ?, original = NULL WHERE id = ? AND status = 'processing'");
        $stmt->execute([$path, $photoId]);
        return $stmt->rowCount() === 1;
    }

    public static function markFailed(int $photoId): void
    {
        db()->prepare("UPDATE photos SET status = 'failed', original = NULL WHERE id = ? AND status = 'processing'")->execute([$photoId]);
    }

    public static function find(int $propertyId, int $photoId): ?array
    {
        $stmt = db()->prepare('SELECT * FROM photos WHERE id = ? AND property_id = ?');
        $stmt->execute([$photoId, $propertyId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function count(int $propertyId): int
    {
        $stmt = db()->prepare('SELECT COUNT(*) FROM photos WHERE property_id = ?');
        $stmt->execute([$propertyId]);
        return (int) $stmt->fetchColumn();
    }

    public static function primaryPath(int $propertyId): ?string
    {
        $stmt = db()->prepare("SELECT path FROM photos WHERE property_id = ? AND status = 'ready' ORDER BY is_primary DESC, sort_order ASC, id ASC LIMIT 1");
        $stmt->execute([$propertyId]);
        $path = $stmt->fetchColumn();
        return $path ? (string) $path : null;
    }

    public static function primaryPaths(array $ids): array
    {
        $paths = [];
        $ids = array_values(array_unique(array_map('intval', $ids)));
        foreach (array_chunk($ids, 400) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = db()->prepare("SELECT property_id, path FROM photos WHERE property_id IN ($placeholders) AND status = 'ready' ORDER BY property_id, is_primary DESC, sort_order ASC, id ASC");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll() as $row) {
                $id = (int) $row['property_id'];
                if (!isset($paths[$id])) {
                    $paths[$id] = (string) $row['path'];
                }
            }
        }
        return $paths;
    }

    public static function insert(int $propertyId, string $path, int $primary, int $sort): array
    {
        $rows = ReturningWrite::insert('photos', [
            'property_id' => $propertyId,
            'path' => $path,
            'is_primary' => $primary,
            'sort_order' => $sort,
        ], ['id', 'property_id', 'path', 'is_primary', 'sort_order']);
        return $rows[0]['new'] ?? [];
    }

    public static function delete(int $propertyId, int $photoId): array
    {
        $rows = ReturningWrite::remove('photos', 'id = ? AND property_id = ?', [$photoId, $propertyId], ['id', 'path', 'is_primary', 'sort_order', 'status', 'uuid', 'original']);
        return ['old' => $rows[0]['old'] ?? null, 'capa' => self::ensurePrimary($propertyId)];
    }

    public static function deleteIds(int $propertyId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return ['old' => [], 'capa' => []];
        }
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        $rows = ReturningWrite::remove('photos', 'property_id = ? AND id IN (' . $marks . ')', [$propertyId, ...$ids], ['id', 'path', 'is_primary', 'sort_order', 'status', 'uuid', 'original']);
        return ['old' => array_map(static fn (array $row): array => $row['old'], $rows), 'capa' => self::ensurePrimary($propertyId)];
    }

    public static function makePrimary(int $propertyId, int $photoId): array
    {
        $json = (string) db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'json_build_object' : 'json_object';
        $sql = 'UPDATE ' . ReturningWrite::q('photos')
            . ' SET ' . ReturningWrite::q('audit_hold') . ' = ' . $json . "('id', " . ReturningWrite::q('id') . ", 'path', " . ReturningWrite::q('path') . ", 'is_primary', " . ReturningWrite::q('is_primary') . ", 'sort_order', " . ReturningWrite::q('sort_order') . '), '
            . ReturningWrite::q('is_primary') . ' = CASE WHEN id = ? THEN 1 ELSE 0 END WHERE property_id = ? AND EXISTS (SELECT 1 FROM photos target WHERE target.id = ? AND target.property_id = ?) RETURNING '
            . ReturningWrite::q('audit_hold') . ', ' . ReturningWrite::q('id') . ', ' . ReturningWrite::q('path') . ', ' . ReturningWrite::q('is_primary') . ', ' . ReturningWrite::q('sort_order');
        return self::changedCovers(ReturningWrite::query($sql, [$photoId, $propertyId, $photoId, $propertyId]));
    }

    public static function ensurePrimary(int $propertyId): array
    {
        $json = (string) db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'json_build_object' : 'json_object';
        $sql = 'UPDATE ' . ReturningWrite::q('photos')
            . ' SET ' . ReturningWrite::q('audit_hold') . ' = ' . $json . "('id', " . ReturningWrite::q('id') . ", 'path', " . ReturningWrite::q('path') . ", 'is_primary', " . ReturningWrite::q('is_primary') . ", 'sort_order', " . ReturningWrite::q('sort_order') . '), '
            . ReturningWrite::q('is_primary') . ' = CASE WHEN id = (SELECT id FROM photos WHERE property_id = ? ORDER BY sort_order, id LIMIT 1) THEN 1 ELSE 0 END WHERE property_id = ? AND NOT EXISTS (SELECT 1 FROM photos p WHERE p.property_id = ? AND p.is_primary = 1) RETURNING '
            . ReturningWrite::q('audit_hold') . ', ' . ReturningWrite::q('id') . ', ' . ReturningWrite::q('path') . ', ' . ReturningWrite::q('is_primary') . ', ' . ReturningWrite::q('sort_order');
        return self::changedCovers(ReturningWrite::query($sql, [$propertyId, $propertyId, $propertyId]));
    }

    private static function changedCovers(array $rows): array
    {
        $changed = [];
        foreach ($rows as $row) {
            $old = json_decode((string) ($row['audit_hold'] ?? ''), true);
            if (!is_array($old)) {
                continue;
            }
            $new = [
                'id' => (int) ($row['id'] ?? 0),
                'path' => (string) ($row['path'] ?? ''),
                'is_primary' => (int) ($row['is_primary'] ?? 0),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
            ];
            if ((int) ($old['is_primary'] ?? 0) === $new['is_primary']) {
                continue;
            }
            $changed[] = ['old' => $old, 'new' => $new];
        }
        return $changed;
    }
}

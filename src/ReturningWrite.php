<?php

declare(strict_types=1);

final class ReturningWrite
{
    public static function update(string $table, array $values, string $where, array $whereParams, array $keys, array $columns): array
    {
        self::check($table, [...array_keys($values), ...$columns, 'audit_hold']);
        $jsonArgs = [];
        foreach ($columns as $column) {
            $jsonArgs[] = "'" . $column . "', " . self::q($column);
        }
        $set = [self::q('audit_hold') . ' = json_build_object(' . implode(', ', $jsonArgs) . ')'];
        $params = [];
        foreach ($values as $column => $value) {
            $set[] = self::q($column) . ' = ?';
            $params[] = $value;
        }
        $returning = [self::q('audit_hold')];
        foreach ($columns as $column) {
            $returning[] = self::q($column);
        }
        $sql = 'UPDATE ' . self::q($table)
            . ' SET ' . implode(', ', $set)
            . ' WHERE ' . $where
            . ' RETURNING ' . implode(', ', $returning);
        $stmt = db()->prepare($sql);
        $stmt->execute([...$params, ...$whereParams]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $old = json_decode((string) ($row['audit_hold'] ?? ''), true);
            $new = [];
            foreach ($columns as $column) {
                $new[$column] = $row[$column] ?? null;
            }
            $out[] = ['old' => is_array($old) ? $old : [], 'new' => $new];
        }
        return $out;
    }

    public static function remove(string $table, string $where, array $params, array $columns): array
    {
        self::check($table, $columns);
        $sql = 'DELETE FROM ' . self::q($table) . ' WHERE ' . $where
            . ' RETURNING ' . implode(', ', array_map([self::class, 'q'], $columns));
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = ['old' => $row, 'new' => []];
        }
        return $out;
    }

    public static function insert(string $table, array $values, array $columns): array
    {
        self::check($table, [...array_keys($values), ...$columns]);
        $columnsSql = implode(', ', array_map([self::class, 'q'], array_keys($values)));
        $marks = implode(', ', array_fill(0, count($values), '?'));
        $sql = 'INSERT INTO ' . self::q($table) . ' (' . $columnsSql . ') VALUES (' . $marks . ') RETURNING '
            . implode(', ', array_map([self::class, 'q'], $columns));
        $stmt = db()->prepare($sql);
        $stmt->execute(array_values($values));
        $row = $stmt->fetch();
        return $row ? [['old' => [], 'new' => $row]] : [];
    }

    public static function query(string $sql, array $params): array
    {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function pairs(array $rows, array $columns): array
    {
        $out = [];
        foreach ($rows as $row) {
            $old = [];
            $new = [];
            foreach ($columns as $column) {
                $old[$column] = $row['old_' . $column] ?? null;
                $new[$column] = $row['new_' . $column] ?? null;
            }
            $out[] = ['old' => $old, 'new' => $new];
        }
        return $out;
    }

    public static function q(string $name): string
    {
        return '"' . $name . '"';
    }

    private static function check(string $table, array $names): void
    {
        foreach ([$table, ...$names] as $name) {
            if (!preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
                throw new InvalidArgumentException('Coluna inválida.');
            }
        }
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Perf (#painel): the `quotes` table is owned by database/schema.pgsql.sql (versioned by schema_meta),
 * so no request path may run CREATE TABLE / CREATE INDEX on it. ensureTable() stays available for
 * tests and seed scripts, but must never be called from the web routes or from the repository itself.
 */
final class QuoteSchemaOwnershipTest extends TestCase
{
    private static function source(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testSchemaOwnsQuotesTableAndIndexes(): void
    {
        $schema = self::source('database/schema.pgsql.sql');

        $this->assertMatchesRegularExpression('/CREATE TABLE IF NOT EXISTS quotes\s*\(/', $schema);
        $this->assertStringContainsString('idx_quotes_hash', $schema);
        $this->assertStringContainsString('idx_quotes_property', $schema);
    }

    public function testRequestPathsDoNotRunQuoteDdl(): void
    {
        $this->assertStringNotContainsString(
            'QuoteRepository::ensureTable()',
            self::source('public/index.php'),
            'public/index.php must not run DDL per request'
        );
        $this->assertStringNotContainsString(
            'QuoteRepository::ensureTable()',
            self::source('src/Api.php'),
            'Api.php must not run DDL per request'
        );

        $repository = self::source('src/QuoteRepository.php');
        $this->assertStringNotContainsString(
            'self::ensureTable();',
            $repository,
            'QuoteRepository methods must rely on the versioned schema, not on DDL calls'
        );
        $this->assertStringContainsString(
            'public static function ensureTable(): void',
            $repository,
            'ensureTable() must remain available for tests and seed scripts'
        );
    }
}

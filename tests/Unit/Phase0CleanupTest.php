<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Phase0CleanupTest extends TestCase
{
    public function testDeadHelpersAreNotDefined(): void
    {
        require_once dirname(__DIR__, 2) . "/src/helpers.php";

        $deadHelpers = [
            "password_is_weak",
            "account_previous",
            "password_error",
            "phone_digits",
            "phone_is_valid",
            "date_placeholder",
            "word_count",
            "primary_photo",
            "base_url",
        ];

        foreach ($deadHelpers as $fn) {
            self::assertFalse(function_exists($fn), "Helper function {$fn} should be deleted");
        }
    }

    public function testOrphanedViewsAreRemoved(): void
    {
        $base = dirname(__DIR__, 2);
        $orphaned = [
            $base . "/views/admin_quote.php",
            $base . "/views/admin_reservations.php",
            $base . "/views/registered.php",
        ];

        foreach ($orphaned as $view) {
            self::assertFileDoesNotExist($view, "Orphaned view {$view} should be removed");
        }
    }

    public function testAssetsArePrecompressedForGzipStatic(): void
    {
        require_once dirname(__DIR__, 2) . "/src/AssetManifest.php";
        $manifest = AssetManifest::build();
        $publicDir = AssetManifest::publicDir();

        self::assertNotEmpty($manifest);
        foreach ($manifest as $rel => $hash) {
            $abs = $publicDir . $rel;
            $ext = pathinfo($abs, PATHINFO_EXTENSION);
            if (in_array($ext, ["css", "js", "json", "svg"], true)) {
                $gz = $abs . ".gz";
                self::assertFileExists($gz, "Asset {$rel} must have pre-compressed .gz file");
                self::assertSame(file_get_contents($abs), gzdecode((string) file_get_contents($gz)));
            }
        }
    }
}

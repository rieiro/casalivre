<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Perf (#painel): photos are immutable (UUID file names), so /uploads/ must be served with a
 * one-year immutable cache and without executing PHP (prompts/deploy.md). Revalidating each
 * photo on every dashboard load costs one conditional request per card.
 */
final class NginxUploadsCacheTest extends TestCase
{
    private static function block(string $source, string $needle): string
    {
        $start = strpos($source, $needle);
        if ($start === false) {
            return '';
        }
        $open = strpos($source, '{', $start);
        if ($open === false) {
            return '';
        }
        $depth = 0;
        $length = strlen($source);
        for ($i = $open; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        return '';
    }

    public function testUploadsLocationCachesImmutablyAndNeverRunsPhp(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/ops/dev-env/templates/nginx.conf.in');
        $block = self::block($template, 'location /uploads/');

        $this->assertNotSame('', $block, 'nginx.conf.in must have a location /uploads/ block');
        $this->assertStringContainsString('max-age=31536000', $block);
        $this->assertStringContainsString('immutable', $block);
        $this->assertStringContainsString('X-Content-Type-Options nosniff', $block);
        $this->assertStringContainsString('autoindex off', $block);
        $this->assertStringContainsString('location ~ \.php$ { return 404; }', $block);
        $this->assertStringNotContainsString('fastcgi_pass', $block, '/uploads/ must never reach PHP');
    }
}

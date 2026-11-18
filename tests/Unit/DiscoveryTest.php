<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DiscoveryTest extends TestCase
{
    public function testRobotsTxtAllowsMajorAiCrawlers(): void
    {
        $file = dirname(__DIR__, 2) . '/public/robots.txt';
        $this->assertFileExists($file);
        $content = (string) file_get_contents($file);

        $this->assertStringContainsString('User-agent: OAI-SearchBot', $content);
        $this->assertStringContainsString('User-agent: ClaudeBot', $content);
        $this->assertStringContainsString('User-agent: Google-Extended', $content);
        $this->assertStringContainsString('User-agent: PerplexityBot', $content);
        $this->assertStringContainsString('Allow: /', $content);
        $this->assertStringContainsString('Disallow: /admin', $content);
        $this->assertStringContainsString('Disallow: /conta', $content);
        $this->assertStringContainsString('Disallow: /painel', $content);
    }

    public function testLlmsTxtContainsPlatformSpec(): void
    {
        $file = dirname(__DIR__, 2) . '/public/llms.txt';
        $this->assertFileExists($file);
        $content = (string) file_get_contents($file);

        $this->assertStringContainsString('/llms-full.txt', $content);
        $this->assertStringContainsString('/openapi.json', $content);
        $this->assertStringContainsString('/api/v1/agent/search', $content);
        $this->assertStringContainsString('/mcp', $content);
        $this->assertStringContainsString('Do not request host CPF', $content);
    }

    public function testOpenApiJsonIsValidAndMatchesSpec(): void
    {
        $file = dirname(__DIR__, 2) . '/openapi.json';
        $this->assertFileExists($file);
        $json = json_decode((string) file_get_contents($file), true);

        $this->assertIsArray($json);
        $this->assertSame('3.1.0', $json['openapi']);
        $this->assertSame('1.0.0', $json['info']['version']);
        $this->assertArrayHasKey('/api/v1/agent/search', $json['paths']);
        $this->assertArrayHasKey('/api/v1/agent/properties/{public_hash}', $json['paths']);
        $this->assertArrayHasKey('/api/v1/agent/properties/{public_hash}/quote', $json['paths']);
        $this->assertArrayHasKey('/api/v1/agent/properties/{public_hash}/intent', $json['paths']);
    }
}

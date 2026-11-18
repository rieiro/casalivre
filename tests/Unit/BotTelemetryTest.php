<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Db.php';
require_once dirname(__DIR__, 2) . '/src/BotTelemetry.php';

final class BotTelemetryTest extends TestCase
{
    public function testClassifyRequestIdentifiesCrawlersAndAgents(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; OAI-SearchBot/1.0)';
        $this->assertSame('OAI-SearchBot', BotTelemetry::classifyRequest('/busca', 'GET'));

        $_SERVER['HTTP_USER_AGENT'] = 'ClaudeBot/1.0';
        $this->assertSame('ClaudeBot', BotTelemetry::classifyRequest('/', 'GET'));

        $_SERVER['HTTP_USER_AGENT'] = 'Google-Extended/1.0';
        $this->assertSame('Google-Extended', BotTelemetry::classifyRequest('/imoveis/123', 'GET'));

        $_SERVER['HTTP_USER_AGENT'] = 'PerplexityBot/1.0';
        $this->assertSame('PerplexityBot', BotTelemetry::classifyRequest('/llms.txt', 'GET'));

        $_SERVER['HTTP_USER_AGENT'] = 'CasaLivre-MCP-Sim';
        $this->assertSame('CasaLivre-MCP-Sim', BotTelemetry::classifyRequest('/mcp', 'POST'));

        $_SERVER['HTTP_USER_AGENT'] = 'curl/7.88.1';
        $this->assertSame('agent', BotTelemetry::classifyRequest('/api/v1/agent/search', 'GET'));
        $this->assertSame('agent', BotTelemetry::classifyRequest('/mcp', 'POST'));

        // Human requests must be null (never tracked in bot_action_logs)
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
        $this->assertNull(BotTelemetry::classifyRequest('/busca', 'GET'));
        $this->assertNull(BotTelemetry::classifyRequest('/imoveis/0123456789abcdef', 'GET'));
        $this->assertNull(BotTelemetry::classifyRequest('/api/busca', 'GET'));
    }

    public function testRecordDoesNotThrow(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        BotTelemetry::record(
            botName: 'OAI-SearchBot',
            channel: 'rest',
            actionName: 'search_properties',
            statusCode: 200,
            latencyMs: 45,
            propertyId: null,
            searchParams: ['localidade' => 'Florianópolis', 'hospedes' => 2]
        );
        $this->assertTrue(true);
    }
}

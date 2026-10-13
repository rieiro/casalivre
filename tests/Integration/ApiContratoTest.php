<?php
declare(strict_types=1);
require_once __DIR__ . '/HttpTestCase.php';

/** CT-API-001..004 (versão PHPUnit; casos completos em scripts/run-casos-de-teste.php) */
final class ApiContratoTest extends HttpTestCase
{
    public function testCasasSemLoginRetorna401Json(): void
    {
        [$s, $h, $b] = $this->http('GET', '/api/casas');
        self::assertSame(401, $s);
        self::assertStringContainsStringIgnoringCase('application/json', $h);
        self::assertArrayHasKey('error', json_decode($b, true));
    }

    public function testPostSemCsrfRetorna419(): void
    {
        [$s, , $b] = $this->http('POST', '/api/casas', ['title' => 'x']);
        self::assertSame(419, $s);
        self::assertArrayHasKey('error', json_decode($b, true));
    }

    public function testApiCidadesRetornaListaJson(): void
    {
        [$s, , $b] = $this->http('GET', '/api/cidades?q=flor');
        self::assertSame(200, $s, '/api/cidades deveria existir');
        $d = json_decode($b, true);
        self::assertIsArray($d);
        self::assertArrayHasKey('text', $d[0]);
    }

    public function testApiCepsRetornaEnderecoJson(): void
    {
        [$s, , $b] = $this->http('GET', '/api/ceps?q=88010000');
        self::assertSame(200, $s, '/api/ceps deveria existir');
        foreach (['cep', 'street', 'neighborhood', 'city', 'uf'] as $k) {
            self::assertArrayHasKey($k, json_decode($b, true));
        }
    }

    public function testIcsPorPublicHashInexistenteRetorna404(): void
    {
        [$s] = $this->http('GET', '/api/imoveis/0000000000000000/calendario.ics');
        self::assertSame(404, $s);
    }
}

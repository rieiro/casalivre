<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Db.php';
require_once dirname(__DIR__, 2) . '/conta/Geo/CityRepository.php';
require_once dirname(__DIR__, 2) . '/src/SearchQuery.php';

final class PlaceResolutionApostropheTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        putenv('APP_ENV=testing');
        Db::loadEnv();
        $this->pdo = db();
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testResolveWithStraightApostrophe(): void
    {
        $res = CityRepository::resolve("Alta Floresta D'Oeste, RO");
        $this->assertNotNull($res['place']);
        $this->assertSame(132, $res['place']['id']);
        $this->assertNull($res['resolution']);
    }

    public function testResolveWithHtmlEntities(): void
    {
        $res1 = CityRepository::resolve('Alta Floresta D&#039;Oeste, RO');
        $this->assertNotNull($res1['place'], 'Failed resolving with &#039;');
        $this->assertSame(132, $res1['place']['id']);

        $res2 = CityRepository::resolve('Alta Floresta D&#39;Oeste, RO');
        $this->assertNotNull($res2['place'], 'Failed resolving with &#39;');
        $this->assertSame(132, $res2['place']['id']);

        $res3 = CityRepository::resolve('Alta Floresta D&apos;Oeste, RO');
        $this->assertNotNull($res3['place'], 'Failed resolving with &apos;');
        $this->assertSame(132, $res3['place']['id']);
    }

    public function testResolveWithTypographicalApostrophes(): void
    {
        // U+2019 RIGHT SINGLE QUOTATION MARK
        $res1 = CityRepository::resolve("Alta Floresta D\u{2019}Oeste, RO");
        $this->assertNotNull($res1['place'], 'Failed resolving with curly apostrophe U+2019');
        $this->assertSame(132, $res1['place']['id']);

        // U+2018 LEFT SINGLE QUOTATION MARK
        $res2 = CityRepository::resolve("Alta Floresta D\u{2018}Oeste, RO");
        $this->assertNotNull($res2['place'], 'Failed resolving with curly apostrophe U+2018');
        $this->assertSame(132, $res2['place']['id']);

        // ASCII 96 GRAVE ACCENT
        $res3 = CityRepository::resolve("Alta Floresta D\x60Oeste, RO");
        $this->assertNotNull($res3['place'], 'Failed resolving with grave accent');
        $this->assertSame(132, $res3['place']['id']);
    }

    public function testSearchQueryDecodesHtmlEntities(): void
    {
        $q = SearchQuery::fromRequest(['localidade' => 'Alta Floresta D&#039;Oeste, RO']);
        $this->assertSame("Alta Floresta D'Oeste, RO", $q->localidade);
    }
}

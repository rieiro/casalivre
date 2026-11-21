<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . "/src/Db.php";
require_once dirname(__DIR__, 2) . "/conta/Geo/CityRepository.php";
require_once dirname(__DIR__, 2) . "/src/SearchQuery.php";
require_once dirname(__DIR__, 2) . "/src/SearchSql.php";
require_once dirname(__DIR__, 2) . "/src/SearchService.php";
require_once dirname(__DIR__, 2) . "/src/ListingSearch.php";
require_once dirname(__DIR__, 2) . "/src/Settings.php";

final class SearchPaginationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        putenv("APP_ENV=testing");
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

    /** Deterministic fixtures inside the test transaction: 100 Aliança/PE + 1 Saquarema. */
    private function seedFixtures(): void
    {
        $place = CityRepository::resolve('Aliança, PE')['place'];
        $this->assertIsArray($place, 'Aliança, PE must resolve');
        $cityId = (int) $place['id'];
        $this->assertNotNull(CityRepository::byId(4937), 'Saquarema (4937) must exist');

        $user = $this->pdo->query("SELECT id FROM users WHERE status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
        $this->assertNotFalse($user, 'a user is required to seed properties');

        $this->pdo->prepare('DELETE FROM listing_search WHERE city_place_id IN (?, ?)')->execute([$cityId, 4937]);
        $this->pdo->prepare('DELETE FROM properties WHERE city_place_id IN (?, ?)')->execute([$cityId, 4937]);

        $insert = $this->pdo->prepare(
            'INSERT INTO properties (user_id, title, description, summary, city_place_id, address, lat, lng, bedrooms, beds, max_guests, nightly_price, min_nights, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id'
        );
        for ($i = 0; $i < 100; $i++) {
            $insert->execute([(int) $user, 'Aliança ' . $i, 'Descrição', 'Resumo', $cityId, 'Rua ' . $i, -7.6, -35.2, 2, 2, 2, 200, 1, 'active']);
            ListingSearch::sync((int) $insert->fetchColumn());
        }
        $insert->execute([(int) $user, 'Saquarema 1', 'Descrição', 'Resumo', 4937, 'Rua 1', -22.9, -42.5, 2, 3, 3, 300, 2, 'active']);
        ListingSearch::sync((int) $insert->fetchColumn());
    }

    public function testSearchQueryParsesPagination(): void
    {
        $q1 = SearchQuery::fromRequest(["localidade" => "Aliança, PE"]);
        $this->assertSame(1, $q1->pagina);

        $q2 = SearchQuery::fromRequest(["localidade" => "Aliança, PE", "pagina" => "2"]);
        $this->assertSame(2, $q2->pagina);

        $q3 = SearchQuery::fromRequest(["localidade" => "Aliança, PE", "page" => "3"]);
        $this->assertSame(3, $q3->pagina);

        $this->assertSame(2, $q2->filters()["pagina"]);
        $this->assertSame(2, $q2->pageFilters()["pagina"]);

        // filtersHash must remain identical regardless of pagina
        $this->assertSame($q1->filtersHash(), $q2->filtersHash());
    }

    public function testSearchSqlBatchWithOffset(): void
    {
        // Updated: 2026-11 - seed fixtures in transaction and use dynamic future dates.
        $this->seedFixtures();
        $place = CityRepository::resolve("Aliança, PE")["place"];
        $this->assertIsArray($place);
        $checkin = gmdate('Y-m-d', strtotime('+15 days'));
        $checkout = gmdate('Y-m-d', strtotime('+19 days'));

        $batch1 = SearchSql::batch($place, null, 2, null, $checkin, $checkout, null, 20, 0.0, SearchQuery::SORT_RELEVANCE, 0);
        $batch2 = SearchSql::batch($place, null, 2, null, $checkin, $checkout, null, 20, 0.0, SearchQuery::SORT_RELEVANCE, 20);

        $this->assertCount(20, $batch1);
        $this->assertCount(20, $batch2);

        $ids1 = array_column($batch1, "id");
        $ids2 = array_column($batch2, "id");
        $intersection = array_intersect($ids1, $ids2);
        $this->assertEmpty($intersection, "Batch with offset 20 must not overlap with batch at offset 0");
    }

    public function testSearchSqlTotalCount(): void
    {
        // Updated: 2026-11 - seed fixtures in transaction and use dynamic future dates.
        $this->seedFixtures();
        $place = CityRepository::resolve("Aliança, PE")["place"];
        $this->assertIsArray($place);
        $checkin = gmdate('Y-m-d', strtotime('+15 days'));
        $checkout = gmdate('Y-m-d', strtotime('+19 days'));

        $totalCount = SearchSql::totalCount($place, null, 2, null, $checkin, $checkout, 0.0);
        $this->assertSame(100, $totalCount);
    }

    public function testSearchServicePaginationWithCityDatesAndGuests(): void
    {
        // Updated: 2026-11 - seed fixtures in transaction and use dynamic future dates.
        $this->seedFixtures();
        $requestBase = [
            "localidade" => "Aliança, PE",
            "checkin" => gmdate('Y-m-d', strtotime('+15 days')),
            "checkout" => gmdate('Y-m-d', strtotime('+19 days')),
            "hospedes" => "2",
        ];

        // Page 1
        $page1Query = SearchQuery::fromRequest($requestBase + ["pagina" => 1]);
        $page1Result = SearchService::search($page1Query, null);

        $this->assertArrayHasKey("page", $page1Result);
        $this->assertArrayHasKey("total_pages", $page1Result);
        $this->assertArrayHasKey("total", $page1Result);
        $this->assertArrayHasKey("per_page", $page1Result);
        $this->assertSame(1, $page1Result["page"]);
        $this->assertSame(20, $page1Result["per_page"]);
        $this->assertSame(100, $page1Result["total"]);
        $this->assertSame(5, $page1Result["total_pages"]);
        $this->assertCount(20, $page1Result["items"]);

        // Page 2
        $page2Query = SearchQuery::fromRequest($requestBase + ["pagina" => 2]);
        $page2Result = SearchService::search($page2Query, null);

        $this->assertSame(2, $page2Result["page"]);
        $this->assertSame(20, $page2Result["per_page"]);
        $this->assertSame(100, $page2Result["total"]);
        $this->assertSame(5, $page2Result["total_pages"]);
        $this->assertCount(20, $page2Result["items"]);

        // Check no overlap between page 1 and page 2
        $ids1 = array_map(fn($item) => $item["id"], $page1Result["items"]);
        $ids2 = array_map(fn($item) => $item["id"], $page2Result["items"]);
        $intersection = array_intersect($ids1, $ids2);
        $this->assertEmpty($intersection, "Page 1 and Page 2 must not have overlapping properties");

        // Page 5 (last page)
        $page5Query = SearchQuery::fromRequest($requestBase + ["pagina" => 5]);
        $page5Result = SearchService::search($page5Query, null);

        $this->assertSame(5, $page5Result["page"]);
        $this->assertCount(20, $page5Result["items"]);

        // Page 6 (out of range)
        $page6Query = SearchQuery::fromRequest($requestBase + ["pagina" => 6]);
        $page6Result = SearchService::search($page6Query, null);

        $this->assertSame(6, $page6Result["page"]);
        $this->assertEmpty($page6Result["items"]);
    }
    public function testSearchRespectsMinNightsAndKeepsCountCoherent(): void
    {
        // Updated: 2026-11 - seed fixtures in transaction and use dynamic future dates.
        $this->seedFixtures();
        $place = CityRepository::byId(4937);
        $this->assertNotNull($place, "Saquarema (4937) must exist");
        $checkin = gmdate('Y-m-d', strtotime('+15 days'));

        // 1 night stay when min_nights is 2: total and items must both be 0
        $q1 = SearchQuery::fromRequest([
            "local_id" => "4937",
            "checkin" => $checkin,
            "checkout" => gmdate('Y-m-d', strtotime($checkin . ' +1 day')),
            "hospedes" => "3",
        ]);
        $res1 = SearchService::search($q1, null);
        $this->assertSame(0, $res1["total"], "Total count must be 0 when stay length is below min_nights");
        $this->assertSame(0, $res1["total_aprox"]);
        $this->assertEmpty($res1["items"]);

        // 2 nights stay: property meets min_nights, total and items must both be 1
        $q2 = SearchQuery::fromRequest([
            "local_id" => "4937",
            "checkin" => $checkin,
            "checkout" => gmdate('Y-m-d', strtotime($checkin . ' +2 days')),
            "hospedes" => "3",
        ]);
        $res2 = SearchService::search($q2, null);
        $this->assertSame(1, $res2["total"]);
        $this->assertCount(1, $res2["items"]);

        // search.js must ensure count coherence when items is empty
        $js = (string) file_get_contents(dirname(__DIR__, 2) . "/public/assets/pages/search.js");
        $this->assertStringContainsString("items.length === 0", $js);
    }
    public function testSearchJsHasValidSyntax(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('Node is not installed.');
        }
        $jsPath = dirname(__DIR__, 2) . "/public/assets/pages/search.js";
        exec(escapeshellarg($node) . " -c " . escapeshellarg($jsPath), $output, $returnCode);
        $this->assertSame(0, $returnCode, "search.js must have valid syntax with no duplicate identifier errors");
    }
}

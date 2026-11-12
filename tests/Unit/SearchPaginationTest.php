<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . "/src/Db.php";
require_once dirname(__DIR__, 2) . "/conta/Geo/CityRepository.php";
require_once dirname(__DIR__, 2) . "/src/SearchQuery.php";
require_once dirname(__DIR__, 2) . "/src/SearchSql.php";
require_once dirname(__DIR__, 2) . "/src/SearchService.php";

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
        $place = CityRepository::resolve("Aliança, PE")["place"];
        $this->assertIsArray($place);

        $batch1 = SearchSql::batch($place, null, 2, null, "2026-12-01", "2026-12-05", null, 20, 0.0, SearchQuery::SORT_RELEVANCE, 0);
        $batch2 = SearchSql::batch($place, null, 2, null, "2026-12-01", "2026-12-05", null, 20, 0.0, SearchQuery::SORT_RELEVANCE, 20);

        $this->assertCount(20, $batch1);
        $this->assertCount(20, $batch2);

        $ids1 = array_column($batch1, "id");
        $ids2 = array_column($batch2, "id");
        $intersection = array_intersect($ids1, $ids2);
        $this->assertEmpty($intersection, "Batch with offset 20 must not overlap with batch at offset 0");
    }

    public function testSearchSqlTotalCount(): void
    {
        $place = CityRepository::resolve("Aliança, PE")["place"];
        $this->assertIsArray($place);

        $totalCount = SearchSql::totalCount($place, null, 2, null, "2026-12-01", "2026-12-05", 0.0);
        $this->assertSame(100, $totalCount);
    }

    public function testSearchServicePaginationWithCityDatesAndGuests(): void
    {
        $requestBase = [
            "localidade" => "Aliança, PE",
            "checkin" => "2026-12-01",
            "checkout" => "2026-12-05",
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
}

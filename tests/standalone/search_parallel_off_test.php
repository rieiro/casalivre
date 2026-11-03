<?php

declare(strict_types=1);

// Search turns parallel gather off for the whole listing transaction (SET LOCAL, same place as statement_timeout).
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/SearchService.php';

final class SearchParallelProbe extends PDOStatement
{
    public static ?PDO $pdo = null;
    public static ?string $parallel = null;

    public function execute(?array $params = null): bool
    {
        if (self::$parallel === null && self::$pdo instanceof PDO) {
            self::$parallel = (string) self::$pdo->query("SELECT current_setting('max_parallel_workers_per_gather')")->fetchColumn();
        }
        return parent::execute($params);
    }
}

t_temp_db();
$before = (string) db()->query("SELECT current_setting('max_parallel_workers_per_gather')")->fetchColumn();
t_ok($before !== '0', 'the session default is not 0');
SearchParallelProbe::$pdo = db();
db()->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SearchParallelProbe::class]);
SearchService::search(SearchQuery::fromRequest(['localidade' => 'Gramado, RS']), null);
t_eq('0', SearchParallelProbe::$parallel, 'the search transaction sets max_parallel_workers_per_gather to 0');
$after = (string) db()->query("SELECT current_setting('max_parallel_workers_per_gather')")->fetchColumn();
t_eq($before, $after, 'SET LOCAL does not leak past the search transaction');

t_done();

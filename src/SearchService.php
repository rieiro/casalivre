<?php

declare(strict_types=1);

/** Busca pública de imóveis (movido de public/index.php sem alterar os corpos). */
final class SearchService
{
    public static function run(bool $log = true): array
    {
        $filters = [
            'localidade' => trim((string) ($_GET['localidade'] ?? '')),
            'checkin' => (string) ($_GET['checkin'] ?? ''),
            'checkout' => (string) ($_GET['checkout'] ?? ''),
            'hospedes' => (int) ($_GET['hospedes'] ?? 0),
            'raio' => (int) ($_GET['raio'] ?? 0),
            'preco_min' => (int) ($_GET['preco_min'] ?? 0),
            'preco_max' => (int) ($_GET['preco_max'] ?? 0),
            'score_min' => (float) ($_GET['score_min'] ?? 0),
            'amenidades' => array_values(array_filter((array) ($_GET['amenidade'] ?? []), 'is_string')),
        ];
        $searched = $filters['localidade'] !== '' || $filters['checkin'] !== '' || $filters['checkout'] !== '';
        $facets = [];
        $results = $searched ? search_properties($filters, $facets) : [];
        if ($searched && $log) {
            activity(ActivityLog::SEARCH, $filters + ['resultados' => count($results)]);
        }
        return [$filters, $results, $searched, $facets];
    }

    public static function search(array $filters, ?array &$facets = null): array
    {
        $localidade = $filters['localidade'];
        $center = $localidade !== '' ? CityRepository::match($localidade) : null;
        $source = HouseRepository::all();
        $nights = nights_between($filters['checkin'], $filters['checkout']);
        $centerFold = $center ? fold((string) $center['name']) : '';
        $labelFold = $center ? fold((string) $center['label']) : '';
        $displayFold = $center ? fold($center['name'] . ', ' . $center['uf']) : '';
        $pool = [];
        foreach ($source as $property) {
            $cityFold = fold((string) $property['city']);
            if ($center && $filters['raio'] > 0) {
                // Raio e distância sobre a coordenada pública (não revela a posição exata).
                $distance = public_distance_km($center, $property);
                if ($distance > $filters['raio']) {
                    continue;
                }
                $property['distance_km'] = $distance;
            } elseif ($center && $cityFold !== $centerFold && $cityFold !== $labelFold && $cityFold !== $displayFold) {
                continue;
            } elseif (!$center && $localidade !== '' && !str_contains($cityFold . ' ' . fold((string) (house_neighborhood($property) ?? '')), fold($localidade))) {
                // Só cidade e bairro: buscar no endereço viraria um oráculo ("localidade=Rua X, 123").
                continue;
            }
            if ($filters['hospedes'] > 0 && (int) $property['max_guests'] < $filters['hospedes']) {
                continue;
            }
            $pool[] = $property;
        }
        $ids = array_map(static fn (array $property): int => (int) $property['id'], $pool);
        $calendars = $nights ? HouseRepository::calendarForProperties($ids, $nights) : [];
        $quoted = [];
        foreach ($pool as $property) {
            $id = (int) $property['id'];
            $quote = $nights
                ? stay_quote($property, $nights, $calendars[$id] ?? [])
                : ['nights' => 0, 'total' => (int) $property['nightly_price'], 'nightly' => (int) $property['nightly_price']];
            if ($nights && $quote === null) {
                continue;
            }
            if ($filters['preco_min'] > 0 && $quote['nightly'] < $filters['preco_min']) {
                continue;
            }
            if ($filters['preco_max'] > 0 && $quote['nightly'] > $filters['preco_max']) {
                continue;
            }
            $property['quote'] = $quote;
            $quoted[] = $property;
        }
        $quotedIds = array_map(static fn (array $property): int => (int) $property['id'], $quoted);
        $scores = HouseRepository::averageScores($quotedIds);
        $photos = PhotoRepository::primaryPaths($quotedIds);
        $wanted = array_values(array_filter(
            $filters['amenidades'] ?? [],
            static fn (string $key): bool => in_array($key, Amenities::keys(), true)
        ));
        $counts = array_fill_keys(Amenities::keys(), 0);
        $rows = [];
        foreach ($quoted as $property) {
            $id = (int) $property['id'];
            $score = $scores[$id] ?? null;
            if ($filters['score_min'] > 0 && ($score === null || $score < $filters['score_min'])) {
                continue;
            }
            $selected = Amenities::selected($property);
            foreach (Amenities::keys() as $key) {
                if (!empty($selected[$key])) {
                    $counts[$key]++;
                }
            }
            foreach ($wanted as $key) {
                if (empty($selected[$key])) {
                    continue 2;
                }
            }
            $property['photo'] = $photos[$id] ?? null;
            $property['score'] = $score;
            $rows[] = $property;
        }
        if ($facets !== null) {
            $facets = $counts;
        }
        return $rows;
    }
}

// Nomes globais mantidos (index.php e Api.php chamam por eles).
function run_search(bool $log = true): array
{
    return SearchService::run($log);
}

function search_properties(array $filters, ?array &$facets = null): array
{
    return SearchService::search($filters, $facets);
}

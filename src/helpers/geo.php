<?php

declare(strict_types=1);

function compose_address(string $street, string $number, string $complement): string
{
    $parts = [];
    foreach ([$street, $number, $complement] as $part) {
        $part = trim($part);
        if ($part !== '') {
            $parts[] = $part;
        }
    }
    return implode(', ', $parts);
}

/** Static ODbL credit. The same anchor is cloned into the autocomplete and the map control. */
function osm_attribution_anchor(): string
{
    return '<a href="https://www.openstreetmap.org/copyright" rel="noopener noreferrer" target="_blank">© OpenStreetMap contributors</a>';
}

function osm_attribution(): void
{
    echo '<p class="osm-attribution">' . osm_attribution_anchor() . '</p>';
}

/** Hidden source for the place field. Not shown until a suggestion list contains a POI. */
function osm_attribution_template(): void
{
    echo '<template id="osm-attribution"'
        . ' data-type-city="' . e(t('place.type_city')) . '"'
        . ' data-type-neighborhood="' . e(t('place.type_neighborhood')) . '"'
        . ' data-type-poi="' . e(t('place.type_poi')) . '"'
        . ' data-type-region="' . e(t('place.type_region')) . '">';
    osm_attribution();
    echo '</template>';
}

/**
 * Parent city name for each place id. Cities have none.
 *
 * @param list<int> $ids
 * @return array<int, string>
 */
function place_parents(array $ids): array
{
    $clean = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $clean[$id] = $id;
        }
    }
    if ($clean === []) {
        return [];
    }
    $parents = [];
    $sql = 'SELECT p.id, c.name AS parent_name FROM places p LEFT JOIN places c ON c.id = p.parent_id WHERE p.id IN (' . implode(',', $clean) . ')';
    foreach (db()->query($sql) as $row) {
        $name = trim((string) ($row['parent_name'] ?? ''));
        if ($name !== '') {
            $parents[(int) $row['id']] = $name;
        }
    }
    return $parents;
}

/** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
function place_with_parents(array $rows): array
{
    $parents = place_parents(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows));
    foreach ($rows as $i => $row) {
        $parent = $parents[(int) ($row['id'] ?? 0)] ?? '';
        if ($parent !== '') {
            $rows[$i]['parent'] = $parent;
        }
    }
    return $rows;
}

/** Name, type and parent (or UF when the place has no parent). */
function place_option_text(array $option): string
{
    $type = (string) ($option['type'] ?? '');
    $typeLabel = t(match ($type) {
        'city' => 'place.type_city',
        'neighborhood' => 'place.type_neighborhood',
        'poi' => 'place.type_poi',
        'region' => 'place.type_region',
        default => 'place.type_poi',
    });
    $uf = rtrim((string) ($option['uf'] ?? ''));
    $parent = trim((string) ($option['parent'] ?? ''));
    $where = $parent !== '' ? ($uf !== '' ? $parent . ', ' . $uf : $parent) : $uf;
    return (string) ($option['name'] ?? '') . ' · ' . $typeLabel . ($where !== '' ? ' · ' . $where : '');
}

/**
 * Submitted text that did not resolve to one place. No listing query.
 *
 * @return array{resolution:?string,options:list<array<string, mixed>>}
 */
function unresolved_place(SearchQuery $query): array
{
    if ($query->localId !== null || $query->localidade === '') {
        return ['resolution' => null, 'options' => []];
    }
    $resolved = CityRepository::resolve($query->localidade);
    if ($resolved['place'] !== null) {
        return ['resolution' => null, 'options' => []];
    }
    return [
        'resolution' => $resolved['resolution'],
        'options' => place_with_parents($resolved['options']),
    ];
}

/** Display label shared by autocomplete options and the search/map field. */
function place_label(?array $place, string $fallback = ''): string
{
    if ($place === null) {
        return $fallback;
    }
    return $place['name'] . ', ' . $place['uf'];
}

function city_label_for_cep(string $cep): string
{
    $digits = str_pad(preg_replace('/\D/', '', $cep) ?? '', 8, '0', STR_PAD_LEFT);
    if (strlen($digits) !== 8) {
        return '';
    }
    $row = CepRepository::find($digits);
    if (!$row || trim((string) $row['city']) === '') {
        return '';
    }
    $known = CityRepository::exactCity((string) $row['city'], (string) $row['uf']);
    return (string) ($known['label'] ?? ($row['city'] . ', ' . $row['uf']));
}

function address_from_input(array $input): ?array
{
    $cep = str_pad(preg_replace('/\D/', '', (string) ($input['cep'] ?? '')) ?? '', 8, '0', STR_PAD_LEFT);
    if (strlen($cep) !== 8) {
        return null;
    }
    $row = CepRepository::find($cep);
    if (!$row || trim((string) $row['street']) === '') {
        return null;
    }
    $number = trim((string) ($input['address_number'] ?? ''));
    if ($number === '') {
        return null;
    }
    $complement = trim((string) ($input['address_complement'] ?? ''));
    $street = trim((string) $row['street']);
    return [
        'cep' => $cep,
        'street' => $street,
        'address_number' => $number,
        'address_complement' => $complement,
        'address' => compose_address($street, $number, $complement),
        'cep_city' => (string) $row['city'],
        'cep_uf' => (string) $row['uf'],
        'cep_neighborhood' => (string) $row['neighborhood'],
    ];
}

function cities(): array
{
    return [
        'Florianópolis' => [-27.5954, -48.5480],
        'Rio de Janeiro' => [-22.9068, -43.1729],
        'São Paulo' => [-23.5505, -46.6333],
        'Salvador' => [-12.9777, -38.5016],
        'Gramado' => [-29.3746, -50.8764],
    ];
}

function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1, sqrt($a)));
}

/**
 * Coordenada pública de um imóvel (mapa, busca e API). Nunca expõe a coordenada exata:
 * aplica um deslocamento fixo por imóvel de 300 a 500 m (direção e distância derivadas de
 * HMAC-SHA256('property:'+id, GEO_SALT)) e arredonda para 3 casas; o resultado final fica
 * sempre entre 300 e 500 m do ponto real. Sem GEO_SALT fora de dev/local: sem deslocamento,
 * arredonda para 2 casas (~1 km) e registra geo_salt_missing uma vez (defesa extra para CLI/scripts:
 * o app web nem sobe sem GEO_SALT >= 32, ver GeoSalt::enforce). Em dev/local usa um
 * sal fixo. O dono continua vendo a coordenada exata no painel.
 *
 * @return array{lat: float, lng: float}
 */
function public_coord(int $id, float $lat, float $lng): array
{
    $salt = geo_salt();
    if ($salt === null) {
        return ['lat' => round($lat, 2), 'lng' => round($lng, 2)];
    }
    $candidate = ['lat' => round($lat, 3), 'lng' => round($lng, 3)];
    for ($i = 0; $i < 64; $i++) {
        $h = hash_hmac('sha256', 'property:' . $id . ($i === 0 ? '' : ':' . $i), $salt, true);
        $u1 = unpack('N', substr($h, 0, 4))[1] / 4294967295;
        $u2 = unpack('N', substr($h, 4, 4))[1] / 4294967295;
        $bearing = $u1 * 2 * M_PI;
        $dist = 300.0 + $u2 * 200.0; // metros
        $dLat = ($dist * cos($bearing)) / 111320.0;
        $dLng = ($dist * sin($bearing)) / (111320.0 * max(0.01, cos(deg2rad($lat))));
        $candidate = ['lat' => round($lat + $dLat, 3), 'lng' => round($lng + $dLng, 3)];
        $real = haversine_km($lat, $lng, $candidate['lat'], $candidate['lng']) * 1000;
        if ($real >= 300.0 && $real <= 500.0) {
            return $candidate;
        }
    }
    return $candidate;
}

/** Sal do deslocamento público; null (e log geo_salt_missing uma vez) fora de dev sem GEO_SALT. */
function geo_salt(): ?string
{
    if (class_exists('Db')) {
        Db::loadEnv();
    }
    $salt = trim((string) getenv('GEO_SALT'));
    if (strlen($salt) >= 32) {
        return $salt;
    }
    if (AppEnv::isDev()) {
        return 'casalivre-dev-geo-salt-nao-usar-em-producao';
    }
    static $logged = false;
    if (!$logged) {
        $logged = true;
        error_log('geo_salt_missing: GEO_SALT ausente; coordenadas públicas arredondadas para 2 casas, sem deslocamento');
    }
    return null;
}

/**
 * Distância pública (km inteiro) entre um centro e a coordenada pública do imóvel.
 */
function public_distance_km(array $center, array $property): int
{
    $pc = public_coord((int) $property['id'], (float) $property['lat'], (float) $property['lng']);
    return (int) round(haversine_km((float) $center['lat'], (float) $center['lng'], $pc['lat'], $pc['lng']));
}

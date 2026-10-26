<?php

declare(strict_types=1);

require_once __DIR__ . '/Amenities.php';
require_once __DIR__ . '/Rooms.php';
require_once __DIR__ . '/PhotoStorage.php';
require_once __DIR__ . '/PhotoRepository.php';
require_once __DIR__ . '/CityRepository.php';

final class HouseService
{
    /** Campos que só aceitam lista (rooms[...], amenity[...]); todos os outros são texto/escalares. */
    private const ARRAY_FIELDS = ['rooms', 'amenity'];

    /** Mensagem de erro se algum campo vier com o tipo errado (texto como array, ou rooms/amenity como texto). */
    public static function typeError(array $input): ?string
    {
        foreach ($input as $key => $value) {
            if (in_array((string) $key, self::ARRAY_FIELDS, true)) {
                if (!is_array($value)) {
                    return t('err.field_type');
                }
            } elseif (is_array($value) || is_object($value)) {
                return t('err.field_type');
            }
        }
        return null;
    }

    /**
     * Lê um campo de texto. Ausente/null -> $default. Valor não escalar (array/objeto) também devolve
     * $default, mas nunca chega aqui: fromInput() já recusou com 422 via typeError().
     */
    private static function strField(array $input, string $key, string $default = ''): string
    {
        $value = $input[$key] ?? $default;
        return is_scalar($value) ? (string) $value : $default;
    }

    public static function fromInput(int $userId, array $input, ?array $current = null): array
    {
        $typeError = self::typeError($input);
        if ($typeError !== null) {
            return ['error' => $typeError];
        }
        $title = trim(self::strField($input, 'title'));
        $description = trim(self::strField($input, 'description'));
        $place = address_from_input($input);
        $address = $place['address'] ?? '';
        $layout = Rooms::parse($input);
        if (isset($layout['error'])) {
            return $layout;
        }
        $maxGuests = (int) ($input['max_guests'] ?? 0);
        $price = parse_money((string) ($input['nightly_price'] ?? '0'));
        $checkin = self::strField($input, 'checkin_time', '15:00');
        $checkout = self::strField($input, 'checkout_time', '11:00');
        $minNights = max(1, (int) ($input['min_nights'] ?? 1));
        $calendarCap = max(1, Settings::int('calendar_months'));
        $calendarMonths = array_key_exists('calendar_months', $input)
            ? (int) $input['calendar_months']
            : 6;
        $cleaningFee = array_key_exists('cleaning_fee', $input) ? parse_money((string) $input['cleaning_fee']) : 0;
        $known = $place ? CityRepository::exactCity($place['cep_city'], $place['cep_uf']) : null;
        if (!$place) {
            return ['error' => t('err.cep')];
        }
        if ($title === '' || $description === '' || !$known) {
            return ['error' => t('err.house')];
        }
        $titleLen = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
        $descriptionLen = function_exists('mb_strlen') ? mb_strlen($description) : strlen($description);
        if ($titleLen > 25 || $descriptionLen > 500) {
            return ['error' => t('err.house_length')];
        }
        if ($maxGuests < 1 || $maxGuests > 999 || $price < 1 || $price > 999999) {
            return ['error' => t('err.house_numbers')];
        }
        if ($calendarMonths < 1 || $calendarMonths > $calendarCap) {
            return ['error' => sprintf(t('err.calendar_months'), (string) $calendarCap)];
        }
        if ($cleaningFee < 0 || $cleaningFee > 999999) {
            return ['error' => t('err.cleaning')];
        }
        $lat = (float) $known['lat'];
        $lng = (float) $known['lng'];
        $lat += (float) ($input['lat_offset'] ?? 0);
        $lng += (float) ($input['lng_offset'] ?? 0);
        $sameCity = $current && (int) $current['city_place_id'] === (int) $known['id'];
        if ($sameCity && ($input['lat_offset'] ?? '') === '' && ($input['lng_offset'] ?? '') === '') {
            $lat = (float) $current['lat'];
            $lng = (float) $current['lng'];
        }
        $amenities = Amenities::fromInput($input);
        if (!empty($amenities['noise'])) {
            $noiseFrom = Amenities::clock((string) ($input['noise_from'] ?? ''));
            $noiseUntil = Amenities::clock((string) ($input['noise_until'] ?? ''));
            if ($noiseFrom === null || $noiseUntil === null || $noiseFrom >= $noiseUntil) {
                return ['error' => t('err.noise')];
            }
            $amenities['noise_from'] = $noiseFrom;
            $amenities['noise_until'] = $noiseUntil;
        }
        return [
            'user_id' => $userId,
            'title' => $title,
            'description' => $description,
            'summary' => summary_from($description, 45),
            'city_place_id' => (int) $known['id'],
            'neighborhood_place_id' => CityRepository::neighborhoodId((int) $known['id'], (string) $place['cep_neighborhood']),
            'address' => $address,
            'cep' => $place['cep'],
            'street' => $place['street'],
            'address_number' => $place['address_number'],
            'address_complement' => $place['address_complement'],
            'lat' => $lat,
            'lng' => $lng,
            'bedrooms' => $layout['bedrooms'],
            'beds' => $layout['beds'],
            'rooms' => $layout['json'],
            'max_guests' => $maxGuests,
            'nightly_price' => $price,
            'cleaning_fee' => $cleaningFee,
            'checkin_time' => $checkin,
            'checkout_time' => $checkout,
            'min_nights' => $minNights,
            'calendar_months' => $calendarMonths,
            'pool' => !empty($amenities['pool']) ? 1 : 0,
            'bbq' => !empty($amenities['bbq']) ? 1 : 0,
            'garage' => !empty($amenities['garage']) ? 1 : 0,
            'amenities' => json_encode($amenities, JSON_UNESCAPED_UNICODE),
        ];
    }

    public static function create(int $userId, array $input): array
    {
        $fields = self::fromInput($userId, $input);
        if (isset($fields['error'])) {
            return $fields;
        }
        $row = HouseRepository::insertRow($fields);
        return ['id' => (int) ($row['id'] ?? 0), 'atual' => self::previous($row)];
    }

    public static function update(int $userId, int $id, array $input): array
    {
        $current = HouseRepository::findByUser($userId, $id);
        if (!$current) {
            return ['error' => t('err.house_missing')];
        }
        $fields = self::fromInput($userId, $input, $current);
        if (isset($fields['error'])) {
            return $fields;
        }
        $changed = HouseRepository::update($id, $userId, $fields);
        if ($changed === []) {
            return ['error' => t('err.house_missing')];
        }
        $removed = HouseRepository::clearBlockedAfter($id, max_calendar_day($fields));
        if ($removed !== []) {
            $audit = HouseRepository::nightAudit($removed);
            activity(ActivityLog::CALENDAR_UPDATE, ['imovel_id' => $id, 'motivo' => 'limite'], $audit['anterior'], ['estado' => 'removida']);
        }
        return [
            'id' => $id,
            'anterior' => self::previous($changed[0]['old']),
            'atual' => self::previous($changed[0]['new']),
        ];
    }

    public static function previous(array $current): array
    {
        return [
            'titulo' => (string) ($current['title'] ?? ''),
            'descricao' => (string) ($current['description'] ?? ''),
            'cidade' => (string) ($current['city'] ?? ''),
            'endereco' => (string) ($current['address'] ?? ''),
            'cep' => (string) ($current['cep'] ?? ''),
            'diaria' => (int) ($current['nightly_price'] ?? 0),
            'taxa_limpeza' => (int) ($current['cleaning_fee'] ?? 0),
            'quartos' => (int) ($current['bedrooms'] ?? 0),
            'camas' => (int) ($current['beds'] ?? 0),
            'quartos_camas' => Rooms::describe(Rooms::decode((string) ($current['rooms'] ?? ''))),
            'hospedes' => (int) ($current['max_guests'] ?? 0),
            'minimo_noites' => (int) ($current['min_nights'] ?? 0),
            'meses_calendario' => (int) ($current['calendar_months'] ?? 0),
            'checkin' => (string) ($current['checkin_time'] ?? ''),
            'checkout' => (string) ($current['checkout_time'] ?? ''),
            'piscina' => (int) ($current['pool'] ?? 0),
            'churrasqueira' => (int) ($current['bbq'] ?? 0),
            'garagem' => (int) ($current['garage'] ?? 0),
            'comodidades' => self::amenityNames($current),
            'lei_silencio' => Amenities::noiseWindow($current),
            'estado' => (string) ($current['status'] ?? 'active'),
        ];
    }

    public static function amenityNames(array $current): array
    {
        $raw = $current['amenities'] ?? null;
        $decoded = [];
        if (is_array($raw)) {
            $decoded = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) {
                $decoded = $parsed;
            }
        }
        foreach (['pool', 'bbq', 'garage'] as $key) {
            if (!empty($current[$key])) {
                $decoded[$key] = 1;
            }
        }
        $names = [];
        foreach (Amenities::keys() as $key) {
            if (!empty($decoded[$key])) {
                $names[] = $key;
            }
        }
        return $names;
    }

    public static function amenityChange(array $before, array $after): array
    {
        $old = is_array($before['comodidades'] ?? null) ? $before['comodidades'] : self::amenityNames($before);
        $new = is_array($after['comodidades'] ?? null) ? $after['comodidades'] : self::amenityNames($after);
        return [
            'comodidades_incluidas' => array_values(array_diff($new, $old)),
            'comodidades_removidas' => array_values(array_diff($old, $new)),
        ];
    }

    public static function remove(int $userId, int $id): bool
    {
        $changed = HouseRepository::inactivate($id, $userId, date('Y-m-d H:i:s'));
        if ($changed === []) {
            return false;
        }
        $old = $changed[0]['old'];
        $new = $changed[0]['new'];
        // Segurança #39: imóvel inativado não deixa WebP públicos acessíveis pelo caminho direto.
        PhotoStorage::retireHouse($id);
        activity(ActivityLog::PROPERTY_DELETE, [
            'imovel_id' => $id,
            'titulo' => (string) ($new['title'] ?? ''),
            'estado' => (string) ($new['status'] ?? 'inactive'),
            'inativado_em' => (string) ($new['inactivated_at'] ?? ''),
        ], self::previous($old), self::previous($new));
        return true;
    }
}

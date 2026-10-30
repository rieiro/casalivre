<?php

declare(strict_types=1);

/** Typed public-search input. Built from the request; SearchService does not read $_GET. */
final class SearchQuery
{
    public const VERSION = 2;
    public const SORT_RELEVANCE = 'relevance';
    public const SORT_RATING = 'rating';
    /** PostgreSQL integer (int4) maximum. A larger id or key overflows the bind. */
    public const LAST_ID_MAX = 2147483647;

    /**
     * @param list<string> $amenidades
     */
    public function __construct(
        public readonly string $localidade,
        public readonly ?int $localId,
        public readonly string $checkin,
        public readonly string $checkout,
        public readonly int $hospedes,
        public readonly int $raio,
        public readonly int $precoMin,
        public readonly int $precoMax,
        public readonly float $scoreMin,
        public readonly array $amenidades,
        public readonly ?string $grupo,
        public readonly string $sort,
    ) {
    }

    /** @param array<string, mixed> $request query parameters already taken off the request */
    public static function fromRequest(array $request): self
    {
        $amenidades = array_values(array_unique(array_values(array_filter(
            (array) ($request['amenidade'] ?? []),
            'is_string'
        ))));
        $grupo = array_key_exists('grupo', $request)
            ? (is_string($request['grupo']) ? $request['grupo'] : '')
            : null;
        return new self(
            trim((string) ($request['localidade'] ?? '')),
            self::readLocalId($request),
            (string) ($request['checkin'] ?? ''),
            (string) ($request['checkout'] ?? ''),
            (int) ($request['hospedes'] ?? 0),
            (int) ($request['raio'] ?? 0),
            (int) ($request['preco_min'] ?? 0),
            (int) ($request['preco_max'] ?? 0),
            self::readScoreMin($request['score_min'] ?? 0),
            $amenidades,
            $grupo,
            self::sortOf($request['sort'] ?? null),
        );
    }

    /** Unknown values are relevance. */
    private static function sortOf(mixed $raw): string
    {
        return $raw === self::SORT_RATING ? self::SORT_RATING : self::SORT_RELEVANCE;
    }

    public function searched(): bool
    {
        return $this->localId !== null || $this->localidade !== '' || $this->checkin !== '' || $this->checkout !== '';
    }

    /** A positive PostgreSQL int4 parsed from a digit string, or null. */
    public static function positiveInt4(mixed $raw): ?int
    {
        return is_string($raw) && preg_match('/^[1-9]\d*$/', $raw) === 1 && (int) $raw <= self::LAST_ID_MAX
            ? (int) $raw
            : null;
    }

    /** +INF cannot be stored in JSON, and it is above 10. */
    private static function readScoreMin(mixed $raw): float
    {
        $score = (float) $raw;
        return $score >= 0 ? min($score, 11.0) : 0.0;
    }

    /** Absent → null. Present and a positive int4 → that id. Present but unusable → 0. */
    private static function readLocalId(array $request): ?int
    {
        if (!array_key_exists('local_id', $request) || $request['local_id'] === '') {
            return null;
        }
        $raw = $request['local_id'];
        return self::positiveInt4($raw) ?? 0;
    }

    /** Same keys the current search page and /api/busca clients already read. */
    public function filters(): array
    {
        $filters = [
            'localidade' => $this->localidade,
            'checkin' => $this->checkin,
            'checkout' => $this->checkout,
            'hospedes' => $this->hospedes,
            'raio' => $this->raio,
            'preco_min' => $this->precoMin,
            'preco_max' => $this->precoMax,
            'score_min' => $this->scoreMin,
            'amenidades' => $this->amenidades,
        ];
        if ($this->localId > 0) {
            unset($filters['localidade']);
            $filters['local_id'] = $this->localId;
        }
        if ($this->grupo !== null) {
            $filters['grupo'] = $this->grupo;
        }
        return $filters;
    }

    /** Page views must not copy a foreign `grupo` into links; the storefront API URL forces the path group. */
    public function pageFilters(): array
    {
        $filters = $this->filters();
        unset($filters['grupo']);
        return $filters;
    }

    public function filtersHash(): string
    {
        $amenidades = $this->amenidades;
        sort($amenidades);
        return hash('sha256', (string) json_encode([
            $this->localidade,
            $this->localId,
            $this->checkin,
            $this->checkout,
            $this->hospedes,
            $this->raio,
            $this->precoMin,
            $this->precoMax,
            $this->scoreMin,
            $amenidades,
            $this->grupo,
        ], JSON_UNESCAPED_UNICODE));
    }

    public static function cursor(int $lastKey, int $id, string $sort, string $filtersHash): string
    {
        $json = json_encode([
            'v' => self::VERSION,
            'sort' => $sort,
            'last_key' => $lastKey,
            'id' => $id,
            'filters_hash' => $filtersHash,
        ], JSON_UNESCAPED_SLASHES);
        return rtrim(strtr(base64_encode((string) $json), '+/', '-_'), '=');
    }

    /** @return array{key:int,id:int} */
    public static function position(string $cursor, string $filtersHash, string $sort): array
    {
        $pad = strtr($cursor, '-_', '+/');
        $pad .= str_repeat('=', (4 - strlen($pad) % 4) % 4);
        $raw = base64_decode($pad, true);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $hash = is_array($data) ? ($data['filters_hash'] ?? null) : null;
        $key = is_array($data) ? ($data['last_key'] ?? null) : null;
        $id = is_array($data) ? ($data['id'] ?? null) : null;
        if (!is_array($data)
            || ($data['v'] ?? null) !== self::VERSION
            || ($data['sort'] ?? null) !== $sort
            || !is_int($key)
            || $key < 0
            || $key > self::LAST_ID_MAX
            || !is_int($id)
            || $id < 1
            || $id > self::LAST_ID_MAX
            || !is_string($hash)
            || !hash_equals($filtersHash, $hash)) {
            throw new InvalidSearchCursor();
        }
        return ['key' => $key, 'id' => $id];
    }
}

/** Opaque cursor was tampered or belongs to a different filter set. */
final class InvalidSearchCursor extends InvalidArgumentException
{
}

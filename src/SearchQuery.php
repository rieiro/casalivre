<?php

declare(strict_types=1);

/** Typed public-search input. Built from the request; SearchService does not read $_GET. */
final class SearchQuery
{
    public const VERSION = 1;
    public const SORT = 'id';
    /** PostgreSQL integer (int4) maximum. A larger last_id overflows the id bind. */
    public const LAST_ID_MAX = 2147483647;

    /**
     * @param list<string> $amenidades
     */
    public function __construct(
        public readonly string $localidade,
        public readonly string $checkin,
        public readonly string $checkout,
        public readonly int $hospedes,
        public readonly int $raio,
        public readonly int $precoMin,
        public readonly int $precoMax,
        public readonly float $scoreMin,
        public readonly array $amenidades,
        public readonly ?string $grupo,
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
            (string) ($request['checkin'] ?? ''),
            (string) ($request['checkout'] ?? ''),
            (int) ($request['hospedes'] ?? 0),
            (int) ($request['raio'] ?? 0),
            (int) ($request['preco_min'] ?? 0),
            (int) ($request['preco_max'] ?? 0),
            (float) ($request['score_min'] ?? 0),
            $amenidades,
            $grupo,
        );
    }

    public function searched(): bool
    {
        return $this->localidade !== '' || $this->checkin !== '' || $this->checkout !== '';
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

    public static function cursor(int $lastId, string $filtersHash): string
    {
        $json = json_encode([
            'v' => self::VERSION,
            'sort' => self::SORT,
            'last_id' => $lastId,
            'filters_hash' => $filtersHash,
        ], JSON_UNESCAPED_SLASHES);
        return rtrim(strtr(base64_encode((string) $json), '+/', '-_'), '=');
    }

    public static function lastId(string $cursor, string $filtersHash): int
    {
        $pad = strtr($cursor, '-_', '+/');
        $pad .= str_repeat('=', (4 - strlen($pad) % 4) % 4);
        $raw = base64_decode($pad, true);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $hash = is_array($data) ? ($data['filters_hash'] ?? null) : null;
        if (!is_array($data)
            || ($data['v'] ?? null) !== self::VERSION
            || ($data['sort'] ?? null) !== self::SORT
            || !is_int($data['last_id'] ?? null)
            || $data['last_id'] < 1
            || $data['last_id'] > self::LAST_ID_MAX
            || !is_string($hash)
            || !hash_equals($filtersHash, $hash)) {
            throw new InvalidSearchCursor();
        }
        return $data['last_id'];
    }
}

/** Opaque cursor was tampered or belongs to a different filter set. */
final class InvalidSearchCursor extends InvalidArgumentException
{
}

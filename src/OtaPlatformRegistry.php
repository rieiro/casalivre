<?php

declare(strict_types=1);

/**
 * Single source of truth for the OTA verification allowlist.
 * Exact hosts only (never str_contains), per-platform public/ical/share rules and UI metadata.
 */
final class OtaPlatformRegistry
{
    /** @var array<string, array<string, mixed>> */
    private const PLATFORMS = [
        'airbnb' => [
            'label' => 'Airbnb',
            'public_hosts' => ['www.airbnb.com', 'airbnb.com', 'www.airbnb.com.br', 'airbnb.com.br'],
            'share_hosts' => ['abnb.me'],
            'ical_hosts' => ['www.airbnb.com', 'airbnb.com', 'www.airbnb.com.br', 'airbnb.com.br'],
            'public_path_regex' => '#\A/rooms/(\d+)\z#',
            'public_placeholder' => 'https://www.airbnb.com/rooms/12345678',
            'ical_placeholder' => 'https://www.airbnb.com/calendar/ical/12345678.ics',
            'requires_admin_review' => false,
        ],
        'booking' => [
            'label' => 'Booking.com',
            'public_hosts' => ['www.booking.com', 'booking.com'],
            'share_hosts' => ['www.booking.com', 'booking.com'],
            'ical_hosts' => ['ical.booking.com', 'admin.booking.com', 'www.booking.com'],
            'public_path_regex' => '~\A/hotel/([a-z]{2})/([a-z0-9][a-z0-9_-]*)(?:\.[a-z]{2}(?:-[a-z]{2})?)?\.html\z~i',
            'public_placeholder' => 'https://www.booking.com/hotel/br/casa-teste.html',
            'ical_placeholder' => 'https://ical.booking.com/v1/export?t=...',
            'requires_admin_review' => true,
        ],
        'vrbo' => [
            'label' => 'Vrbo',
            'public_hosts' => ['www.vrbo.com', 'vrbo.com', 'www.aluguetemporada.com.br', 'aluguetemporada.com.br'],
            'share_hosts' => [],
            'ical_hosts' => ['www.vrbo.com', 'vrbo.com', 'www.aluguetemporada.com.br', 'aluguetemporada.com.br'],
            'public_path_regex' => '~\A/(?:pt-br/)?(?:imovel/[^/?#]+/)?(\d+|p\d+)(?:ha|vb)?\z~i',
            'public_placeholder' => 'https://www.vrbo.com/1234567',
            'ical_placeholder' => 'https://www.vrbo.com/icalendar/1234567.ics',
            'requires_admin_review' => true,
        ],
    ];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return self::PLATFORMS;
    }

    /** @return array<string, mixed>|null */
    public static function get(string $platform): ?array
    {
        $platform = strtolower(trim($platform));
        return self::PLATFORMS[$platform] ?? null;
    }

    public static function exists(string $platform): bool
    {
        return self::get($platform) !== null;
    }

    public static function label(string $platform): string
    {
        return (string) (self::get($platform)['label'] ?? '');
    }

    public static function requiresAdminReview(string $platform): bool
    {
        return (bool) (self::get($platform)['requires_admin_review'] ?? true);
    }

    public static function publicHosts(string $platform): array
    {
        return (array) (self::get($platform)['public_hosts'] ?? []);
    }

    public static function icalHosts(string $platform): array
    {
        return (array) (self::get($platform)['ical_hosts'] ?? []);
    }

    public static function shareHosts(string $platform): array
    {
        return (array) (self::get($platform)['share_hosts'] ?? []);
    }

    public static function publicPathRegex(string $platform): string
    {
        return (string) (self::get($platform)['public_path_regex'] ?? '');
    }

    public static function publicPlaceholder(string $platform): string
    {
        return (string) (self::get($platform)['public_placeholder'] ?? '');
    }

    public static function icalPlaceholder(string $platform): string
    {
        return (string) (self::get($platform)['ical_placeholder'] ?? '');
    }

    public static function isPublicHost(string $platform, string $host): bool
    {
        return in_array(strtolower($host), self::publicHosts($platform), true);
    }

    public static function isIcalHost(string $platform, string $host): bool
    {
        return in_array(strtolower($host), self::icalHosts($platform), true);
    }

    /** Share URLs: abnb.me for Airbnb; booking.com/Share-* or /share/* for Booking. */
    public static function isShareUrl(string $platform, string $host, string $path): bool
    {
        $host = strtolower($host);
        if ($platform === 'airbnb') {
            return $host === 'abnb.me';
        }
        if ($platform === 'booking') {
            return in_array($host, ['www.booking.com', 'booking.com'], true)
                && preg_match('#\A/(?:Share-[A-Za-z0-9_-]+|share/)#', $path) === 1;
        }
        return false;
    }

    /** Metadata consumed by calendar-3.js (placeholders / review requirement). */
    public static function uiMeta(): array
    {
        $out = [];
        foreach (self::PLATFORMS as $key => $platform) {
            $out[$key] = [
                'label' => $platform['label'],
                'public_placeholder' => $platform['public_placeholder'],
                'ical_placeholder' => $platform['ical_placeholder'],
                'requires_admin_review' => $platform['requires_admin_review'],
            ];
        }
        return $out;
    }
}

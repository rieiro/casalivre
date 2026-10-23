<?php

declare(strict_types=1);

/** UUIDv7 (RFC 9562) generated in PHP: 48-bit Unix ms + version 7 + 12-bit counter + variant + 62 random bits. */
final class Uuid
{
    private static int $lastMs = -1;
    private static int $seq = 0;

    /** Time-ordered within the process: the 12-bit rand_a field is a counter inside the same millisecond. */
    public static function v7(): string
    {
        $ms = (int) floor(microtime(true) * 1000);
        if ($ms <= self::$lastMs) {
            $ms = self::$lastMs;
            if (++self::$seq > 0xFFF) {
                $ms = ++self::$lastMs;
                self::$seq = random_int(0, 0x7FF);
            }
        } else {
            self::$lastMs = $ms;
            self::$seq = random_int(0, 0x7FF);
        }
        $rand = random_bytes(8);
        $rand[0] = chr((ord($rand[0]) & 0x3F) | 0x80); // variant 10
        $hex = str_pad(dechex($ms), 12, '0', STR_PAD_LEFT) . dechex(0x7000 | self::$seq) . bin2hex($rand);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}

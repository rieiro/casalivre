<?php

declare(strict_types=1);

final class QuoteGenerator
{
    /**
     * @return array{subtotal: int, cleaning: int, taxes: int, total: int, special_offer: ?int}
     */
    public static function calculate(
        int $nightlyPrice,
        int $cleaningFee,
        int $nights,
        int $taxPercent = 0,
        ?int $specialOffer = null
    ): array {
        $subtotal = $nightlyPrice * max(1, $nights);
        $cleaning = max(0, $cleaningFee);
        $taxes = (int) round(($subtotal * max(0, $taxPercent)) / 100);
        $calculatedTotal = $subtotal + $cleaning + $taxes;

        $total = ($specialOffer !== null && $specialOffer >= 0) ? $specialOffer : $calculatedTotal;

        return [
            "subtotal" => $subtotal,
            "cleaning" => $cleaning,
            "taxes" => $taxes,
            "total" => $total,
            "special_offer" => $specialOffer,
        ];
    }
}

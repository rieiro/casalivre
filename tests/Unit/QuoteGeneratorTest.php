<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . "/src/QuoteGenerator.php";
require_once dirname(__DIR__, 2) . "/src/PixPayload.php";

final class QuoteGeneratorTest extends TestCase
{
    public function testCalculatesStandardQuoteWithoutSpecialOffer(): void
    {
        $quote = QuoteGenerator::calculate(
            nightlyPrice: 200,
            cleaningFee: 50,
            nights: 3,
            taxPercent: 5,
            specialOffer: null
        );

        self::assertSame(600, $quote["subtotal"]);
        self::assertSame(50, $quote["cleaning"]);
        self::assertSame(30, $quote["taxes"]);
        self::assertSame(680, $quote["total"]);
        self::assertNull($quote["special_offer"]);
    }

    public function testSpecialOfferOverridesTotalCalculation(): void
    {
        $quote = QuoteGenerator::calculate(
            nightlyPrice: 200,
            cleaningFee: 50,
            nights: 3,
            taxPercent: 5,
            specialOffer: 500
        );

        self::assertSame(600, $quote["subtotal"]);
        self::assertSame(50, $quote["cleaning"]);
        self::assertSame(30, $quote["taxes"]);
        self::assertSame(500, $quote["total"]);
        self::assertSame(500, $quote["special_offer"]);
    }

    public function testGeneratesValidPixPayloadString(): void
    {
        $payload = PixPayload::generate(
            key: "rafaelrieiro@gmail.com",
            name: "Rafael Rieiro",
            city: "Saquarema",
            amount: 500.00,
            txid: "CL123456"
        );

        self::assertStringStartsWith("000201", $payload);
        self::assertStringContainsString("rafaelrieiro@gmail.com", $payload);
        self::assertStringContainsString("500.00", $payload);
        self::assertStringContainsString("Saquarema", $payload);
        self::assertStringContainsString("6304", $payload);
    }
}

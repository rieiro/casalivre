<?php

declare(strict_types=1);

final class PixPayload
{
    private const ID_PAYLOAD_FORMAT_INDICATOR = "00";
    private const ID_MERCHANT_ACCOUNT_INFORMATION = "26";
    private const ID_MERCHANT_ACCOUNT_INFORMATION_GUI = "00";
    private const ID_MERCHANT_ACCOUNT_INFORMATION_KEY = "01";
    private const ID_MERCHANT_CATEGORY_CODE = "52";
    private const ID_TRANSACTION_CURRENCY = "53";
    private const ID_TRANSACTION_AMOUNT = "54";
    private const ID_COUNTRY_CODE = "58";
    private const ID_MERCHANT_NAME = "59";
    private const ID_MERCHANT_CITY = "60";
    private const ID_ADDITIONAL_DATA_FIELD_TEMPLATE = "62";
    private const ID_ADDITIONAL_DATA_FIELD_TEMPLATE_TXID = "05";
    private const ID_CRC16 = "63";

    public static function generate(
        string $key,
        string $name,
        string $city,
        float $amount,
        string $txid = "***"
    ): string {
        $cleanName = mb_substr(preg_replace("/[^a-zA-Z0-9 ]/", "", self::stripAccents($name)) ?: "Host", 0, 25);
        $cleanCity = mb_substr(preg_replace("/[^a-zA-Z0-9 ]/", "", self::stripAccents($city)) ?: "Cidade", 0, 15);
        $cleanTxid = preg_replace("/[^a-zA-Z0-9]/", "", $txid) ?: "***";

        $accountInfo = self::format(self::ID_MERCHANT_ACCOUNT_INFORMATION_GUI, "br.gov.bcb.pix")
            . self::format(self::ID_MERCHANT_ACCOUNT_INFORMATION_KEY, $key);

        $additionalData = self::format(self::ID_ADDITIONAL_DATA_FIELD_TEMPLATE_TXID, $cleanTxid);

        $payload = self::format(self::ID_PAYLOAD_FORMAT_INDICATOR, "01")
            . self::format(self::ID_MERCHANT_ACCOUNT_INFORMATION, $accountInfo)
            . self::format(self::ID_MERCHANT_CATEGORY_CODE, "0000")
            . self::format(self::ID_TRANSACTION_CURRENCY, "986")
            . self::format(self::ID_TRANSACTION_AMOUNT, number_format($amount, 2, ".", ""))
            . self::format(self::ID_COUNTRY_CODE, "BR")
            . self::format(self::ID_MERCHANT_NAME, $cleanName)
            . self::format(self::ID_MERCHANT_CITY, $cleanCity)
            . self::format(self::ID_ADDITIONAL_DATA_FIELD_TEMPLATE, $additionalData)
            . self::ID_CRC16 . "04";

        $crc = self::crc16($payload);
        return $payload . $crc;
    }

    private static function stripAccents(string $str): string
    {
        return strtr($str, [
            "á"=>"a", "à"=>"a", "ã"=>"a", "â"=>"a", "ä"=>"a",
            "é"=>"e", "è"=>"e", "ê"=>"e", "ë"=>"e",
            "í"=>"i", "ì"=>"i", "î"=>"i", "ï"=>"i",
            "ó"=>"o", "ò"=>"o", "õ"=>"o", "ô"=>"o", "ö"=>"o",
            "ú"=>"u", "ù"=>"u", "û"=>"u", "ü"=>"u",
            "ç"=>"c", "ñ"=>"n",
            "Á"=>"A", "À"=>"A", "Ã"=>"A", "Â"=>"A", "Ä"=>"A",
            "É"=>"E", "È"=>"E", "Ê"=>"E", "Ë"=>"E",
            "Í"=>"I", "Ì"=>"I", "Î"=>"I", "Ï"=>"I",
            "Ó"=>"O", "Ò"=>"O", "Õ"=>"O", "Ô"=>"O", "Ö"=>"O",
            "Ú"=>"U", "Ù"=>"U", "Û"=>"U", "Ü"=>"U",
            "Ç"=>"C", "Ñ"=>"N",
        ]);
    }

    private static function format(string $id, string $value): string
    {
        $len = str_pad((string) strlen($value), 2, "0", STR_PAD_LEFT);
        return $id . $len . $value;
    }

    /** CRC-16/CCITT-FALSE (poly 0x1021, init 0xFFFF) */
    private static function crc16(string $payload): string
    {
        $crc = 0xFFFF;
        $length = strlen($payload);

        for ($i = 0; $i < $length; $i++) {
            $crc ^= (ord($payload[$i]) << 8);
            for ($j = 0; $j < 8; $j++) {
                if (($crc & 0x8000) !== 0) {
                    $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFF;
                }
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, "0", STR_PAD_LEFT));
    }
}

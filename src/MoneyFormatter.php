<?php

declare(strict_types=1);

namespace App;

final class MoneyFormatter
{
    /**
     * Normalize a positive decimal money value to an integer rupiah string using half-up rounding.
     * Examples: 1231832.90 -> 1231833, 953430.33 -> 953430, 10.50 -> 11.
     */
    public static function roundedInteger(string $amount): string
    {
        $amount = trim($amount);
        if (preg_match('/^(\d{1,20})(?:\.(\d{1,9}))?$/D', $amount, $matches) !== 1) {
            return '0';
        }

        $whole = ltrim($matches[1], '0');
        if ($whole === '') {
            $whole = '0';
        }

        $fraction = $matches[2] ?? '';
        if ($fraction !== '' && (int) $fraction[0] >= 5) {
            $whole = self::incrementDigits($whole);
        }

        return $whole;
    }

    public static function formatInteger(string $amount): string
    {
        $whole = self::roundedInteger($amount);
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);

        return is_string($grouped) ? $grouped : $whole;
    }

    public static function formatIdr(string $amount): string
    {
        return 'IDR ' . self::formatInteger($amount);
    }

    private static function incrementDigits(string $digits): string
    {
        $carry = 1;
        $result = '';

        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            $sum = ((int) $digits[$index]) + $carry;
            $result = (string) ($sum % 10) . $result;
            $carry = intdiv($sum, 10);
        }

        if ($carry > 0) {
            $result = '1' . $result;
        }

        return $result;
    }
}

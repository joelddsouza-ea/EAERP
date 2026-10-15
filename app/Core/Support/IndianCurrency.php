<?php

namespace App\Core\Support;

/**
 * Rupee amounts in words with the Indian grouping (crore, lakh, thousand), as
 * printed on estimates: 12270 → "Rupees Twelve Thousand Two Hundred Seventy Only".
 */
class IndianCurrency
{
    private const ONES = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen',
    ];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    public static function inWords(float|int $amount): string
    {
        $rupees = (int) round($amount);

        if ($rupees <= 0) {
            return 'Rupees Zero Only';
        }

        $parts = [];
        foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand'], [100, 'Hundred']] as [$unit, $name]) {
            if ($rupees >= $unit) {
                $parts[] = self::belowHundredCrore(intdiv($rupees, $unit)) . ' ' . $name;
                $rupees %= $unit;
            }
        }
        if ($rupees > 0) {
            $parts[] = self::twoDigits($rupees);
        }

        return 'Rupees ' . implode(' ', $parts) . ' Only';
    }

    /**
     * Count of crores/lakhs/thousands/hundreds (crores may exceed 99).
     */
    private static function belowHundredCrore(int $n): string
    {
        if ($n < 100) {
            return self::twoDigits($n);
        }

        return trim(substr(self::inWords($n), strlen('Rupees '), -strlen(' Only')));
    }

    private static function twoDigits(int $n): string
    {
        if ($n < 20) {
            return self::ONES[$n];
        }

        return trim(self::TENS[intdiv($n, 10)] . ' ' . self::ONES[$n % 10]);
    }
}

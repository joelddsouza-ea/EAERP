<?php

namespace App\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A mobile number: exactly 10 digits (spaces, dashes, a leading 0 or +91 are allowed while typing
 * and removed by IndianMobile::clean, which forms call before validating).
 */
class IndianMobile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (!preg_match('/^[6-9][0-9]{9}$/', (string) $value)) {
            $fail('Enter a 10-digit mobile number.');
        }
    }

    /**
     * "+91 98200 55500", "098200-55500" → "9820055500"; anything else is returned as typed
     * (so the rule can reject it).
     */
    public static function clean(mixed $value): mixed
    {
        if (!is_string($value) || trim($value) === '') {
            return $value === '' ? null : $value;
        }
        $digits = preg_replace('/\D+/', '', $value);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return strlen($digits) === 10 ? $digits : $value;
    }
}

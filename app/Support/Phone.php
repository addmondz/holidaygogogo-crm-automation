<?php

namespace App\Support;

class Phone
{
    /**
     * Normalise a phone number to digits with country code, e.g.
     * "+60 12-345 6789", "012-345 6789" and "0060123456789" all become
     * "60123456789". Returns null when it can't be a valid number.
     */
    public static function normalize(?string $raw, ?string $defaultCountryCode = null): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        $international = str_starts_with($raw, '+') || str_starts_with($raw, '00');
        $digits = preg_replace('/\D/', '', $raw);

        if (str_starts_with($raw, '00')) {
            $digits = substr($digits, 2);
        }

        if (! $international && str_starts_with($digits, '0')) {
            $countryCode = $defaultCountryCode ?? (string) config('crm.default_country_code');
            $digits = $countryCode.ltrim($digits, '0');
        }

        $length = strlen($digits);

        return $length >= 8 && $length <= 15 ? $digits : null;
    }
}

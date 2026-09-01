<?php

namespace App\Support;

final class PhoneNumber
{
    /**
     * Customer identity is stored in one readable Arabic-market form. The
     * project previously kept 059… in `customers` but stripped the leading
     * zero in `loyalty_customers`, creating two identities for one diner.
     */
    public static function normalize(?string $phone): string
    {
        $phone = strtr(trim((string) $phone), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '00970')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '970') && strlen($digits) === 12) {
            return '0'.substr($digits, 3);
        }

        return $digits;
    }

    public static function isValid(?string $phone): bool
    {
        $length = strlen(self::normalize($phone));

        return $length >= 7 && $length <= 15;
    }

    /**
     * Legacy variants are read-only compatibility. New writes always use
     * normalize(); keeping the old no-leading-zero form here prevents a
     * duplicate loyalty account when the first post-migration visit lands.
     *
     * @return list<string>
     */
    public static function lookupVariants(?string $phone): array
    {
        $rawDigits = preg_replace('/\D+/', '', strtr(trim((string) $phone), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ])) ?? '';
        $canonical = self::normalize($phone);
        $variants = [$canonical, $rawDigits, ltrim($canonical, '0')];

        if (str_starts_with($canonical, '0') && strlen($canonical) === 10) {
            $variants[] = '970'.substr($canonical, 1);
        }

        return array_values(array_unique(array_filter($variants)));
    }

    public static function masked(?string $phone): string
    {
        $normalized = self::normalize($phone);
        if ($normalized === '') {
            return '—';
        }

        $visible = min(4, strlen($normalized));

        return str_repeat('•', max(3, strlen($normalized) - $visible)).substr($normalized, -$visible);
    }
}

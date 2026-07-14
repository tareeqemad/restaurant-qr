<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Human-readable Arabic durations for operational screens.
 *
 * The boards used to render raw minutes ("منذ 9950 د") which becomes
 * unreadable past a couple of hours and destroys trust in the numbers.
 * Every surface that shows elapsed time (tables board, waiter board,
 * cashier, KDS) should format through here so the whole app tells time
 * the same way.
 */
class Duration
{
    /**
     * Compact Arabic duration from a minute count:
     *   0        → "الآن"
     *   45       → "45 د"
     *   90       → "ساعة و30 د"
     *   150      → "ساعتين و30 د"
     *   300      → "5 ساعات"
     *   1500     → "يوم وساعة"
     *   4320     → "3 أيام"
     */
    public static function short(int $minutes): string
    {
        if ($minutes <= 0) {
            return 'الآن';
        }

        if ($minutes < 60) {
            return $minutes.' د';
        }

        $hours = intdiv($minutes, 60);
        $rem = $minutes % 60;

        if ($hours < 24) {
            $label = match (true) {
                $hours === 1 => 'ساعة',
                $hours === 2 => 'ساعتين',
                $hours <= 10 => $hours.' ساعات',
                default => $hours.' ساعة',
            };

            // Past ~6 hours the minutes remainder is noise, drop it.
            if ($rem > 0 && $hours < 6) {
                $label .= ' و'.$rem.' د';
            }

            return $label;
        }

        $days = intdiv($hours, 24);
        $remHours = $hours % 24;

        $label = match (true) {
            $days === 1 => 'يوم',
            $days === 2 => 'يومين',
            $days <= 10 => $days.' أيام',
            default => $days.' يوم',
        };

        if ($remHours > 0 && $days < 3) {
            $label .= ' و'.match (true) {
                $remHours === 1 => 'ساعة',
                $remHours === 2 => 'ساعتين',
                default => $remHours.' ساعات',
            };
        }

        return $label;
    }

    /** "منذ 25 د" / "منذ 3 أيام" / "الآن" from a timestamp. */
    public static function since($time): string
    {
        if (! $time) {
            return '—';
        }

        $minutes = (int) Carbon::parse($time)->diffInMinutes(now());
        $short = self::short($minutes);

        return $short === 'الآن' ? $short : 'منذ '.$short;
    }
}

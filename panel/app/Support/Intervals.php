<?php

namespace App\Support;

/**
 * How often a scheduled sync runs, in minutes: a few presets, or any number of minutes, hours
 * or days.
 */
final class Intervals
{
    /** One year: anything longer is not a schedule. */
    public const MAX_MINUTES = 525600;

    public const PRESETS = [
        1 => 'Every minute',
        5 => 'Every 5 minutes',
        15 => 'Every 15 minutes',
        30 => 'Every 30 minutes',
        60 => 'Every hour',
        360 => 'Every 6 hours',
        720 => 'Every 12 hours',
        1440 => 'Every day',
        10080 => 'Every week',
    ];

    public const UNITS = [
        'minutes' => 1,
        'hours' => 60,
        'days' => 1440,
    ];

    public static function label(int $minutes): string
    {
        if (isset(self::PRESETS[$minutes])) {
            return self::PRESETS[$minutes];
        }

        [$every, $unit] = self::split($minutes);

        return "Every {$every} {$unit}";
    }

    /**
     * The form value of an interval: a preset in minutes, or "custom".
     */
    public static function preset(int $minutes): string
    {
        return isset(self::PRESETS[$minutes]) ? (string) $minutes : 'custom';
    }

    /**
     * An interval as the largest unit that divides it: 90 → [90, "minutes"], 2880 → [2, "days"].
     *
     * @return array{0: int, 1: string}
     */
    public static function split(int $minutes): array
    {
        foreach (array_reverse(self::UNITS, true) as $unit => $size) {
            if ($minutes >= $size && $minutes % $size === 0) {
                return [intdiv($minutes, $size), $unit];
            }
        }

        return [$minutes, 'minutes'];
    }

    /**
     * Minutes for the interval fields of a form: `interval` is a preset or "custom", in which
     * case `every` and `unit` say how long. Null when they do not make a valid interval.
     */
    public static function fromInput(mixed $interval, mixed $every = null, mixed $unit = null): ?int
    {
        if ($interval === 'custom') {
            $size = self::UNITS[is_string($unit) ? $unit : ''] ?? null;

            if ($size === null || ! is_numeric($every) || (int) $every != $every) {
                return null;
            }

            $minutes = (int) $every * $size;
        } elseif (is_numeric($interval) && isset(self::PRESETS[(int) $interval])) {
            $minutes = (int) $interval;
        } else {
            return null;
        }

        return $minutes >= 1 && $minutes <= self::MAX_MINUTES ? $minutes : null;
    }
}

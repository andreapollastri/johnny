<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class Format
{
    /**
     * 1536 → "1.50 KB" (binary units, as most S3 tools display them).
     */
    public static function bytes(int|float $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = min((int) floor(log($bytes, 1024)), count($units) - 1);
        $value = $bytes / (1024 ** $i);

        return ($i === 0 ? (string) (int) $value : number_format($value, 2)).' '.$units[$i];
    }

    /**
     * 3725 → "1h 2m"; 95 → "1m 35s"; 12 → "12s".
     */
    public static function duration(int|float $seconds): string
    {
        $seconds = max(0, (int) round($seconds));

        if ($seconds >= 3600) {
            return intdiv($seconds, 3600).'h '.intdiv($seconds % 3600, 60).'m';
        }

        if ($seconds >= 60) {
            return intdiv($seconds, 60).'m '.($seconds % 60).'s';
        }

        return $seconds.'s';
    }

    /**
     * "10 GB" / "512MiB" / "1048576" → bytes, in binary units like bytes(); null when it is not a size.
     */
    public static function parseBytes(string $value): ?int
    {
        if (! preg_match('/^\s*(\d+(?:[.,]\d+)?)\s*([KMGTP]?)(?:i?B)?\s*$/i', $value, $m)) {
            return null;
        }

        $power = (int) strpos(' KMGTP', strtoupper($m[2]) ?: ' ');

        return (int) round((float) str_replace(',', '.', $m[1]) * (1024 ** $power));
    }

    /**
     * ISO-8601 / "Y-m-d" → "Oct 7, 2026 14:05" in the app timezone; "—" when empty or unparsable.
     */
    public static function date(?string $value, bool $withTime = true): string
    {
        if (! $value) {
            return '—';
        }

        try {
            $date = Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return $value;
        }

        return $date->format($withTime ? 'M j, Y H:i' : 'M j, Y');
    }
}

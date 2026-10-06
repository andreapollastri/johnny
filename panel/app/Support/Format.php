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

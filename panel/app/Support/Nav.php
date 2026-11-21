<?php

namespace App\Support;

use App\Models\Transfer;

/**
 * Small facts the navigation shows on every page.
 */
class Nav
{
    /**
     * Transfers waiting or running; 0 when the count cannot be read (it must never break a page).
     */
    public static function activeTransfers(): int
    {
        try {
            return Transfer::query()->active()->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}

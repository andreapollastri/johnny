<?php

namespace App\Http\Controllers;

use App\Services\GarageS3;
use App\Services\JohnnyCliService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Settings tab of a bucket: quotas (Garage CLI), expiry rules and CORS (S3 API).
 */
class BucketSettingsController extends Controller
{
    public const SIZE_UNITS = ['MB' => 1024 ** 2, 'GB' => 1024 ** 3, 'TB' => 1024 ** 4];

    public const CORS_METHODS = ['GET', 'HEAD', 'PUT', 'POST', 'DELETE'];

    /** An origin is a scheme and a host (wildcards allowed), without a path. */
    public const CORS_ORIGIN = '/^https?:\/\/[A-Za-z0-9.*:\-\[\]]+$/';

    public function __construct(
        private GarageS3 $garage,
        private JohnnyCliService $johnny
    ) {}

    /**
     * Largest size and number of objects the bucket may hold; an empty field removes that limit.
     */
    public function quotas(Request $request, string $bucket): RedirectResponse
    {
        $validated = $request->validate([
            'max_size' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'max_size_unit' => ['nullable', Rule::in(array_keys(self::SIZE_UNITS))],
            'max_objects' => ['nullable', 'integer', 'min:1', 'max:1000000000000'],
        ]);

        $maxSize = isset($validated['max_size'])
            ? (int) round((float) $validated['max_size'] * self::SIZE_UNITS[$validated['max_size_unit'] ?? 'GB'])
            : null;
        $maxObjects = isset($validated['max_objects']) ? (int) $validated['max_objects'] : null;

        $result = $this->johnny->setBucketQuotas($bucket, $maxSize, $maxObjects);

        if (! $result->successful()) {
            return $this->backToSettings($bucket)
                ->withErrors(['quotas' => $this->johnny->errorDetail($result, 'The quotas could not be saved.')])
                ->withInput();
        }

        return $this->backToSettings($bucket)
            ->with('status', $maxSize === null && $maxObjects === null ? 'Quotas removed.' : 'Quotas saved.');
    }

    /**
     * Delete objects a number of days after their upload (optionally under one folder only), and
     * clean up uploads that were started but never finished. Garage applies the rules once a day.
     */
    public function lifecycle(Request $request, string $bucket): RedirectResponse
    {
        $validated = $request->validate([
            'expire_days' => ['nullable', 'integer', 'min:1', 'max:36500'],
            'expire_prefix' => ['nullable', 'string', 'max:1024'],
            'abort_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $expireDays = isset($validated['expire_days']) ? (int) $validated['expire_days'] : null;
        $abortDays = isset($validated['abort_days']) ? (int) $validated['abort_days'] : null;

        try {
            $this->garage->putLifecycle($bucket, $expireDays, ltrim((string) ($validated['expire_prefix'] ?? ''), '/'), $abortDays);
        } catch (\Throwable $e) {
            return $this->backToSettings($bucket)->withErrors(['lifecycle' => $e->getMessage()])->withInput();
        }

        return $this->backToSettings($bucket)
            ->with('status', $expireDays === null && $abortDays === null ? 'Expiry rules removed.' : 'Expiry rules saved.');
    }

    /**
     * Which websites may read or write the bucket from a browser. No origins removes the rule.
     */
    public function cors(Request $request, string $bucket): RedirectResponse
    {
        $validated = $request->validate([
            'origins' => ['nullable', 'string', 'max:4000'],
            'methods' => ['nullable', 'array'],
            'methods.*' => [Rule::in(self::CORS_METHODS)],
            'max_age' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ]);

        $origins = array_values(array_unique(array_filter(
            preg_split('/[\s,]+/', (string) ($validated['origins'] ?? '')) ?: [],
            fn (string $origin) => $origin !== '',
        )));

        foreach ($origins as $origin) {
            if ($origin !== '*' && ! preg_match(self::CORS_ORIGIN, $origin)) {
                return $this->backToSettings($bucket)
                    ->withErrors(['origins' => "\"{$origin}\" is not an origin. Use https://app.example.com (no path), or * for any website."])
                    ->withInput();
            }
        }

        $methods = array_values(array_intersect(self::CORS_METHODS, $validated['methods'] ?? []));

        if ($origins !== [] && $methods === []) {
            return $this->backToSettings($bucket)->withErrors(['methods' => 'Select at least one method.'])->withInput();
        }

        try {
            $this->garage->putCors($bucket, $origins, $methods, ['*'], (int) ($validated['max_age'] ?? 3600));
        } catch (\Throwable $e) {
            return $this->backToSettings($bucket)->withErrors(['origins' => $e->getMessage()])->withInput();
        }

        return $this->backToSettings($bucket)->with('status', $origins === [] ? 'CORS rule removed.' : 'CORS rule saved.');
    }

    private function backToSettings(string $bucket): RedirectResponse
    {
        return redirect()->route('buckets.show', ['bucket' => $bucket, 'tab' => 'settings']);
    }
}

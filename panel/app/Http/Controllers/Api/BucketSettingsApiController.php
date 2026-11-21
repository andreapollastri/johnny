<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BucketSettingsController;
use App\Http\Controllers\Controller;
use App\Services\GarageS3;
use App\Services\JohnnyCliService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Settings tab of a bucket over the API: quotas, CORS and expiry rules.
 */
class BucketSettingsApiController extends Controller
{
    public function __construct(
        private GarageS3 $garage,
        private JohnnyCliService $johnny
    ) {}

    public function show(string $bucket): JsonResponse
    {
        if ($missing = $this->missing($bucket)) {
            return $missing;
        }

        $usage = $this->johnny->bucketUsage($bucket);

        try {
            $cors = $this->garage->getCors($bucket);
            $lifecycle = $this->garage->getLifecycle($bucket);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not read the bucket settings.', 'detail' => $e->getMessage()], 502);
        }

        return response()->json(['data' => [
            'bucket' => $bucket,
            'usage' => ['size' => $usage['size'], 'objects' => $usage['objects']],
            'quotas' => ['max_size' => $usage['max_size'], 'max_objects' => $usage['max_objects']],
            'cors' => $cors,
            'lifecycle' => $lifecycle,
        ]]);
    }

    /**
     * Largest size (bytes) and number of objects the bucket may hold; null removes that limit.
     */
    public function quotas(Request $request, string $bucket): JsonResponse
    {
        $validated = $request->validate([
            'max_size' => ['present', 'nullable', 'integer', 'min:1'],
            'max_objects' => ['present', 'nullable', 'integer', 'min:1'],
        ]);

        if ($missing = $this->missing($bucket)) {
            return $missing;
        }

        $maxSize = isset($validated['max_size']) ? (int) $validated['max_size'] : null;
        $maxObjects = isset($validated['max_objects']) ? (int) $validated['max_objects'] : null;

        $result = $this->johnny->setBucketQuotas($bucket, $maxSize, $maxObjects);

        if (! $result->successful()) {
            return response()->json([
                'message' => 'Failed to save the quotas.',
                'detail' => $this->johnny->errorDetail($result, 'Unknown error from johnny bucket set-quotas.'),
            ], 422);
        }

        return response()->json(['data' => ['bucket' => $bucket, 'max_size' => $maxSize, 'max_objects' => $maxObjects]]);
    }

    /**
     * Which websites may use the bucket from a browser. An empty `origins` list removes the rule.
     */
    public function cors(Request $request, string $bucket): JsonResponse
    {
        $validated = $request->validate([
            'origins' => ['present', 'array', 'max:100'],
            'origins.*' => ['string', 'max:255', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== '*' && ! preg_match(BucketSettingsController::CORS_ORIGIN, (string) $value)) {
                    $fail("\"{$value}\" is not an origin. Use https://app.example.com (no path), or * for any website.");
                }
            }],
            'methods' => ['nullable', 'array'],
            'methods.*' => [Rule::in(BucketSettingsController::CORS_METHODS)],
            'headers' => ['nullable', 'array', 'max:50'],
            'headers.*' => ['string', 'max:255'],
            'max_age' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ]);

        if ($missing = $this->missing($bucket)) {
            return $missing;
        }

        $origins = array_values(array_unique($validated['origins']));
        $methods = array_values(array_intersect(BucketSettingsController::CORS_METHODS, $validated['methods'] ?? []));

        if ($origins !== [] && $methods === []) {
            return response()->json(['message' => 'List at least one method.'], 422);
        }

        try {
            $this->garage->putCors($bucket, $origins, $methods, array_values($validated['headers'] ?? ['*']), (int) ($validated['max_age'] ?? 3600));

            return response()->json(['data' => $this->garage->getCors($bucket)]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to save the CORS rule.', 'detail' => $e->getMessage()], 502);
        }
    }

    /**
     * Delete objects some days after their upload, and clean up unfinished multipart uploads.
     * Null turns a rule off.
     */
    public function lifecycle(Request $request, string $bucket): JsonResponse
    {
        $validated = $request->validate([
            'expire_days' => ['present', 'nullable', 'integer', 'min:1', 'max:36500'],
            'expire_prefix' => ['nullable', 'string', 'max:1024'],
            'abort_days' => ['present', 'nullable', 'integer', 'min:1', 'max:365'],
        ]);

        if ($missing = $this->missing($bucket)) {
            return $missing;
        }

        $expireDays = isset($validated['expire_days']) ? (int) $validated['expire_days'] : null;
        $abortDays = isset($validated['abort_days']) ? (int) $validated['abort_days'] : null;

        try {
            $this->garage->putLifecycle($bucket, $expireDays, ltrim((string) ($validated['expire_prefix'] ?? ''), '/'), $abortDays);

            return response()->json(['data' => $this->garage->getLifecycle($bucket)]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to save the expiry rules.', 'detail' => $e->getMessage()], 502);
        }
    }

    private function missing(string $bucket): ?JsonResponse
    {
        return in_array($bucket, $this->johnny->listAllBucketNamesFromGarage(), true)
            ? null
            : response()->json(['message' => 'Bucket not found.'], 404);
    }
}

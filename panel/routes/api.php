<?php

use App\Http\Controllers\Api\ApiDocsController;
use App\Http\Controllers\Api\BucketApiController;
use App\Http\Controllers\Api\BucketKeyApiController;
use App\Http\Controllers\Api\KeyApiController;
use App\Http\Controllers\Api\ProvisionBucketController;
use Illuminate\Support\Facades\Route;

Route::get('/docs', [ApiDocsController::class, 'index'])->name('api.docs');
Route::get('/openapi.yaml', [ApiDocsController::class, 'spec'])->name('api.openapi');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/buckets/provision', ProvisionBucketController::class)->name('api.buckets.provision');

    Route::post('/buckets/{bucket}/keys', [BucketKeyApiController::class, 'store'])
        ->where('bucket', '[a-z0-9][a-z0-9._-]{1,254}')
        ->name('api.buckets.keys.store');
    Route::delete('/buckets/{bucket}/keys/{keyId}', [BucketKeyApiController::class, 'destroy'])
        ->where('bucket', '[a-z0-9][a-z0-9._-]{1,254}')
        ->where('keyId', 'GK[0-9a-fA-F]+')
        ->name('api.buckets.keys.destroy');

    // Prefix route names with api.* — apiResource() would otherwise collide with web routes (buckets.index, keys.store, …).
    Route::name('api.')->group(function () {
        Route::apiResource('buckets', BucketApiController::class)->only(['index', 'store', 'show', 'destroy'])
            ->where(['bucket' => '[a-z0-9][a-z0-9._-]{1,254}']);
        Route::apiResource('keys', KeyApiController::class)->only(['index', 'store', 'show', 'destroy'])
            ->parameters(['keys' => 'keyId'])
            ->where(['keyId' => 'GK[0-9a-fA-F]+']);
    });
});

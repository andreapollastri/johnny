<?php

use App\Http\Controllers\Api\ApiDocsController;
use App\Http\Controllers\Api\BucketApiController;
use App\Http\Controllers\Api\BucketKeyApiController;
use App\Http\Controllers\Api\BucketSettingsApiController;
use App\Http\Controllers\Api\ConnectionApiController;
use App\Http\Controllers\Api\KeyApiController;
use App\Http\Controllers\Api\ProvisionBucketController;
use App\Http\Controllers\Api\ScopeApiController;
use App\Http\Controllers\Api\SyncApiController;
use App\Http\Controllers\Api\TransferApiController;
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

    // Bucket settings: quotas, CORS, expiry rules.
    Route::controller(BucketSettingsApiController::class)->prefix('/buckets/{bucket}')->where(['bucket' => '[a-z0-9][a-z0-9._-]{1,254}'])->group(function () {
        Route::get('/settings', 'show')->name('api.buckets.settings');
        Route::put('/quotas', 'quotas')->name('api.buckets.quotas');
        Route::put('/cors', 'cors')->name('api.buckets.cors');
        Route::put('/lifecycle', 'lifecycle')->name('api.buckets.lifecycle');
    });

    // Scopes, and keys that reach all buckets.
    Route::get('/scopes', ScopeApiController::class)->name('api.scopes');
    Route::put('/keys/{keyId}/all-buckets', [KeyApiController::class, 'allBuckets'])->where('keyId', 'GK[0-9a-fA-F]+')->name('api.keys.all-buckets');
    Route::delete('/keys/{keyId}/all-buckets', [KeyApiController::class, 'forgetAllBuckets'])->where('keyId', 'GK[0-9a-fA-F]+')->name('api.keys.all-buckets.destroy');

    // Other S3 providers, one-off transfers and scheduled syncs.
    Route::get('/providers', [ConnectionApiController::class, 'providers'])->name('api.providers');
    Route::post('/connections/{connection}/check', [ConnectionApiController::class, 'check'])->whereNumber('connection')->name('api.connections.check');
    Route::get('/connections/{connection}/buckets', [ConnectionApiController::class, 'buckets'])->whereNumber('connection')->name('api.connections.buckets');
    Route::post('/transfers/{transfer}/cancel', [TransferApiController::class, 'cancel'])->whereNumber('transfer')->name('api.transfers.cancel');
    Route::post('/transfers/{transfer}/retry', [TransferApiController::class, 'retry'])->whereNumber('transfer')->name('api.transfers.retry');
    Route::post('/syncs/{sync}/run', [SyncApiController::class, 'run'])->whereNumber('sync')->name('api.syncs.run');

    // Prefix route names with api.* — apiResource() would otherwise collide with web routes (buckets.index, keys.store, …).
    Route::name('api.')->group(function () {
        Route::apiResource('buckets', BucketApiController::class)->only(['index', 'store', 'show', 'destroy'])
            ->where(['bucket' => '[a-z0-9][a-z0-9._-]{1,254}']);
        Route::apiResource('keys', KeyApiController::class)->only(['index', 'store', 'show', 'destroy'])
            ->parameters(['keys' => 'keyId'])
            ->where(['keyId' => 'GK[0-9a-fA-F]+']);
        Route::apiResource('connections', ConnectionApiController::class)->whereNumber('connection');
        Route::apiResource('transfers', TransferApiController::class)->only(['index', 'store', 'show', 'destroy'])->whereNumber('transfer');
        Route::apiResource('syncs', SyncApiController::class)->whereNumber('sync');
    });
});

<?php

use App\Http\Controllers\BucketController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\KeyController;
use App\Http\Controllers\ObjectController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::permanentRedirect('/security', '/settings');
    Route::get('/settings', [SettingsController::class, 'show'])->name('settings.show');
    Route::post('/settings/tokens', [SettingsController::class, 'storeToken'])->name('settings.tokens.store');
    Route::delete('/settings/tokens/{tokenId}', [SettingsController::class, 'destroyToken'])->whereNumber('tokenId')->name('settings.tokens.destroy');

    Route::get('/buckets', [BucketController::class, 'index'])->name('buckets.index');
    Route::post('/buckets', [BucketController::class, 'store'])->name('buckets.store');
    Route::get('/buckets/{bucket}', [BucketController::class, 'show'])->name('buckets.show');
    Route::delete('/buckets/{bucket}', [BucketController::class, 'destroy'])->name('buckets.destroy');
    Route::post('/buckets/{bucket}/allow', [BucketController::class, 'allow'])->name('buckets.allow');
    Route::post('/buckets/{bucket}/deny', [BucketController::class, 'deny'])->name('buckets.deny');

    Route::post('/buckets/{bucket}/objects', [ObjectController::class, 'store'])->name('objects.store');
    Route::get('/buckets/{bucket}/objects/download', [ObjectController::class, 'download'])->name('objects.download');
    Route::delete('/buckets/{bucket}/objects', [ObjectController::class, 'destroy'])->name('objects.destroy');

    Route::get('/keys', [KeyController::class, 'index'])->name('keys.index');
    Route::post('/keys', [KeyController::class, 'store'])->name('keys.store');
    Route::delete('/keys', [KeyController::class, 'destroy'])->name('keys.destroy');
});

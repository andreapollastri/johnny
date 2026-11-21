<?php

use App\Http\Controllers\BucketController;
use App\Http\Controllers\BucketSettingsController;
use App\Http\Controllers\ConnectionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\KeyController;
use App\Http\Controllers\ObjectController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\UpdateController;
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
    Route::post('/buckets/{bucket}/keys', [BucketController::class, 'storeKey'])->name('buckets.keys.store');

    Route::post('/buckets/{bucket}/settings/quotas', [BucketSettingsController::class, 'quotas'])->name('buckets.quotas');
    Route::post('/buckets/{bucket}/settings/lifecycle', [BucketSettingsController::class, 'lifecycle'])->name('buckets.lifecycle');
    Route::post('/buckets/{bucket}/settings/cors', [BucketSettingsController::class, 'cors'])->name('buckets.cors');

    Route::post('/buckets/{bucket}/objects', [ObjectController::class, 'store'])->name('objects.store');
    Route::post('/buckets/{bucket}/objects/chunks', [ObjectController::class, 'chunk'])->name('objects.chunk');
    Route::post('/buckets/{bucket}/folders', [ObjectController::class, 'storeFolder'])->name('objects.folder');
    Route::get('/buckets/{bucket}/objects/download', [ObjectController::class, 'download'])->name('objects.download');
    Route::get('/buckets/{bucket}/objects/info', [ObjectController::class, 'info'])->name('objects.info');
    Route::get('/buckets/{bucket}/objects/preview', [ObjectController::class, 'preview'])->name('objects.preview');
    Route::post('/buckets/{bucket}/objects/share', [ObjectController::class, 'share'])->name('objects.share');
    Route::post('/buckets/{bucket}/objects/move', [ObjectController::class, 'move'])->name('objects.move');
    Route::post('/buckets/{bucket}/objects/delete', [ObjectController::class, 'destroyMany'])->name('objects.destroy-many');
    Route::delete('/buckets/{bucket}/objects', [ObjectController::class, 'destroy'])->name('objects.destroy');

    Route::get('/keys', [KeyController::class, 'index'])->name('keys.index');
    Route::post('/keys', [KeyController::class, 'store'])->name('keys.store');
    Route::delete('/keys', [KeyController::class, 'destroy'])->name('keys.destroy');
    Route::get('/keys/{keyId}', [KeyController::class, 'show'])->where('keyId', 'GK[0-9A-Fa-f]+')->name('keys.show');
    Route::post('/keys/{keyId}/all-buckets', [KeyController::class, 'allBuckets'])->where('keyId', 'GK[0-9A-Fa-f]+')->name('keys.all-buckets');
    Route::post('/keys/{keyId}/grant', [KeyController::class, 'grant'])->where('keyId', 'GK[0-9A-Fa-f]+')->name('keys.grant');

    // Imports from and exports to other S3 providers (rclone, in the background).
    Route::get('/transfers', [TransferController::class, 'index'])->name('transfers.index');
    Route::get('/transfers/new', [TransferController::class, 'create'])->name('transfers.create');
    Route::get('/transfers/status', [TransferController::class, 'status'])->name('transfers.status');
    Route::post('/transfers', [TransferController::class, 'store'])->name('transfers.store');
    Route::get('/transfers/{transfer}', [TransferController::class, 'show'])->whereNumber('transfer')->name('transfers.show');
    Route::get('/transfers/{transfer}/status', [TransferController::class, 'statusOf'])->whereNumber('transfer')->name('transfers.status-of');
    Route::post('/transfers/{transfer}/cancel', [TransferController::class, 'cancel'])->whereNumber('transfer')->name('transfers.cancel');
    Route::post('/transfers/{transfer}/retry', [TransferController::class, 'retry'])->whereNumber('transfer')->name('transfers.retry');
    Route::delete('/transfers/{transfer}', [TransferController::class, 'destroy'])->whereNumber('transfer')->name('transfers.destroy');

    // Transfers that repeat on an interval. They are created by the new transfer form.
    Route::get('/syncs/{sync}', [SyncController::class, 'show'])->whereNumber('sync')->name('syncs.show');
    Route::put('/syncs/{sync}', [SyncController::class, 'update'])->whereNumber('sync')->name('syncs.update');
    Route::post('/syncs/{sync}/toggle', [SyncController::class, 'toggle'])->whereNumber('sync')->name('syncs.toggle');
    Route::post('/syncs/{sync}/run', [SyncController::class, 'run'])->whereNumber('sync')->name('syncs.run');
    Route::delete('/syncs/{sync}', [SyncController::class, 'destroy'])->whereNumber('sync')->name('syncs.destroy');

    Route::post('/connections', [ConnectionController::class, 'store'])->name('connections.store');
    Route::get('/connections/{connection}', [ConnectionController::class, 'show'])->whereNumber('connection')->name('connections.show');
    Route::put('/connections/{connection}', [ConnectionController::class, 'update'])->whereNumber('connection')->name('connections.update');
    Route::delete('/connections/{connection}', [ConnectionController::class, 'destroy'])->whereNumber('connection')->name('connections.destroy');
    Route::post('/connections/{connection}/check', [ConnectionController::class, 'check'])->whereNumber('connection')->name('connections.check');
    Route::post('/connections/{connection}/import', [TransferController::class, 'storeMany'])->whereNumber('connection')->name('connections.import');

    // Updates are for administrators. The panel has no roles: every signed-in user is one,
    // so `auth` is the whole check (add one here if the panel ever gets roles).
    Route::get('/updates', [UpdateController::class, 'show'])->name('updates.show');
    Route::get('/updates/status', [UpdateController::class, 'status'])->name('updates.status');
    Route::post('/updates/check', [UpdateController::class, 'check'])->name('updates.check');
    Route::post('/updates/start', [UpdateController::class, 'start'])->name('updates.start');
    Route::post('/updates/channel', [UpdateController::class, 'channel'])->name('updates.channel');
    Route::post('/updates/auto', [UpdateController::class, 'auto'])->name('updates.auto');
    Route::post('/updates/system/start', [UpdateController::class, 'systemStart'])->name('updates.system.start');
    Route::post('/updates/system/auto', [UpdateController::class, 'systemAuto'])->name('updates.system.auto');
});

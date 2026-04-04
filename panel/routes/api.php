<?php

use App\Http\Controllers\Api\ProvisionBucketController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/buckets/provision', ProvisionBucketController::class)->name('api.buckets.provision');
});

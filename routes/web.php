<?php

use App\Http\Controllers\CreditsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GenerationQuoteController;
use App\Http\Controllers\GenerationsController;
use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Controllers\PurchaseCreditsController;
use App\Http\Controllers\StoreGenerationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('generations', GenerationsController::class)->name('generations.index');
    Route::post('generations', StoreGenerationController::class)
        // Distinct prefixes: unprefixed throttles share one per-user counter across routes.
        ->middleware('throttle:10,1,generation-submissions:')
        ->name('generations.store');

    Route::post('generations/quote', GenerationQuoteController::class)
        ->middleware('throttle:120,1,generation-quotes:')
        ->name('generations.quote');

    Route::get('credits', CreditsController::class)->name('credits.index');
    Route::post('credits/purchases', PurchaseCreditsController::class)
        ->middleware('throttle:10,1,credit-purchases:')
        ->name('credits.purchases.store');
});

Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');

require __DIR__.'/settings.php';

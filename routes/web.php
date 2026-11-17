<?php

use App\Http\Controllers\GenerationQuoteController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::inertia('generations', 'Generations')->name('generations.index');

    Route::post('generations/quote', GenerationQuoteController::class)
        ->middleware('throttle:120,1')
        ->name('generations.quote');
});

require __DIR__.'/settings.php';

<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Render the generation workspace.
 *
 * The page learns whether generation is configured, never the configuration
 * itself. A missing provider key disables submission honestly while leaving
 * the read-only price preview available.
 */
final class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard', [
            'generation_enabled' => self::generationEnabled(),
        ]);
    }

    private static function generationEnabled(): bool
    {
        $apiKey = config('services.pixelforge.api_key');

        return is_string($apiKey) && trim($apiKey) !== '';
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Integrations\Pixelforge\PixelforgeConnector;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard', [
            'generation_enabled' => PixelforgeConnector::configuredKey() !== null,
        ]);
    }
}

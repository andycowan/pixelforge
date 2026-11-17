<?php

namespace App\Http\Controllers;

use App\Actions\Generations\ReceivePixelforgeWebhook;
use App\Services\PixelforgeWebhookVerifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, PixelforgeWebhookVerifier $verifier, ReceivePixelforgeWebhook $receive): Response
    {
        $payload = $verifier->verify($request);
        $receive->execute($payload, hash('sha256', config('services.pixelforge.api_key')));

        return response()->noContent();
    }
}

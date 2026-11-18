<?php

namespace App\Http\Controllers;

use App\Actions\Generations\DownloadGenerationImage;
use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;

final class DownloadGenerationImageController extends Controller
{
    public function __invoke(Request $request, string $generation, DownloadGenerationImage $download): Response
    {
        // Owner-scoped lookup: another user's id is indistinguishable from a missing one.
        $owned = Generation::query()->forUser($request->user())->findOrFail($generation);
        $image = $download->execute($owned);

        return response($image['body'], 200, [
            'Content-Type' => $image['content_type'],
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $image['filename']),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

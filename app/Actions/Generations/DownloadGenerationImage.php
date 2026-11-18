<?php

namespace App\Actions\Generations;

use App\Http\Integrations\Pixelforge\PixelforgeConnector;
use App\Http\Integrations\Pixelforge\Requests\GetGenerationImage;
use App\Models\Generation;
use InvalidArgumentException;
use RuntimeException;
use Saloon\Exceptions\Request\FatalRequestException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Fetch an owned generation's image so the browser can save it as a file.
 *
 * The provider sends no CORS headers, and browsers ignore `download` on
 * cross-origin links, so the image is relayed through this origin.
 */
final class DownloadGenerationImage
{
    /** Generated PNGs are a few MB; the cap bounds memory per request. */
    private const MAX_BYTES = 20 * 1024 * 1024;

    private const EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    /** @return array{body: string, content_type: string, filename: string} */
    public function execute(Generation $generation): array
    {
        if ($generation->image_url === null) {
            throw new NotFoundHttpException('This generation has no image.');
        }
        if ($generation->image_url_expires_at === null || ! $generation->image_url_expires_at->isFuture()) {
            throw new GoneHttpException('This image link has expired.');
        }

        try {
            $response = (new PixelforgeConnector)->send(new GetGenerationImage($generation->image_url));
        } catch (InvalidArgumentException $exception) {
            report($exception);

            throw new HttpException(502, 'The image is not available.');
        } catch (FatalRequestException) {
            throw new HttpException(502, 'The image could not be fetched. Try again shortly.');
        }

        $header = $response->header('Content-Type');
        $type = strtolower(trim(explode(';', is_string($header) ? $header : '')[0]));
        $body = $response->body();
        if (! $response->successful() || ! isset(self::EXTENSIONS[$type]) || $body === '' || strlen($body) > self::MAX_BYTES) {
            report(new RuntimeException("Unusable image response for generation {$generation->id}: HTTP {$response->status()}, {$type}, ".strlen($body).' bytes'));

            throw new HttpException(502, 'The image is not available.');
        }

        return ['body' => $body, 'content_type' => $type, 'filename' => $generation->imageDownloadName(self::EXTENSIONS[$type])];
    }
}

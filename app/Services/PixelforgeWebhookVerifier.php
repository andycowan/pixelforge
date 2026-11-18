<?php

namespace App\Services;

use App\Http\Integrations\Pixelforge\PixelforgeConnector;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class PixelforgeWebhookVerifier
{
    /** @return array<string, mixed> */
    public function verify(Request $request): array
    {
        $secret = config('services.pixelforge.webhook_secret');
        if (! is_string($secret) || trim($secret) === '' || PixelforgeConnector::configuredKey() === null) {
            throw new HttpException(503, 'Webhook receiver is not configured.');
        }

        $stream = $request->getContent(true);
        $raw = stream_get_contents($stream, 1048577);
        if ($raw === false) {
            throw new HttpException(400, 'Cannot read webhook body.');
        }
        if (strlen($raw) > 1048576) {
            throw new HttpException(413, 'Webhook body is too large.');
        }
        $signature = (string) $request->header('Pixelforge-Signature');
        if (! preg_match('/\At=([0-9]{1,12}),v1=([a-f0-9]{64})\z/D', $signature, $parts)
            || abs(now()->getTimestamp() - (int) $parts[1]) > 300
            || ! hash_equals(hash_hmac('sha256', $parts[1].'.'.$raw, $secret), $parts[2])) {
            throw new HttpException(400, 'Invalid webhook signature.');
        }

        try {
            $decoded = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
            $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException(400, 'Invalid webhook envelope.');
        }
        if (! $decoded instanceof \stdClass || ! is_array($payload)
            || ($payload['object'] ?? null) !== 'event'
            || ! is_string($payload['id'] ?? null) || ! preg_match('/\Aevt_[A-Za-z0-9]{1,96}\z/D', $payload['id'])
            || ! is_string($payload['type'] ?? null) || ! preg_match('/\A[a-z][a-z0-9_.]{0,99}\z/D', $payload['type'])
            || ! is_string($payload['created_at'] ?? null)
            || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z\z/D', $payload['created_at'])
            || date_parse($payload['created_at'])['warning_count'] !== 0
            || date_parse($payload['created_at'])['error_count'] !== 0
            || $request->header('Pixelforge-Event-Id') !== $payload['id']
            || $request->header('Pixelforge-Event-Type') !== $payload['type']) {
            throw new HttpException(400, 'Invalid webhook envelope.');
        }
        if (in_array($payload['type'], ['generation.queued', 'generation.processing', 'generation.succeeded', 'generation.failed'], true)
            && (! isset($decoded->data) || ! $decoded->data instanceof \stdClass
                || ! isset($decoded->data->object) || ! $decoded->data->object instanceof \stdClass
                || ($payload['data']['object']['object'] ?? null) !== 'generation'
                || ($payload['data']['object']['status'] ?? null) !== substr($payload['type'], 11))) {
            throw new HttpException(400, 'Invalid webhook generation envelope.');
        }

        return $payload;
    }
}

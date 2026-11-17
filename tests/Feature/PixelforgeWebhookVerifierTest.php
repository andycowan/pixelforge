<?php

namespace Tests\Feature;

use App\Services\PixelforgeWebhookVerifier;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class PixelforgeWebhookVerifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.pixelforge.api_key' => 'workflow-test-key', 'services.pixelforge.webhook_secret' => 'workflow-test-secret']);
        $this->freezeTime();
    }

    public function test_signature_authenticates_original_bytes_before_decoding_or_trimming(): void
    {
        $raw = '{ "id":"evt_test1", "object":"event", "type":"ping", "created_at":"2026-11-17T12:00:00Z", "text":"  keep spaces  " }';
        $verified = app(PixelforgeWebhookVerifier::class)->verify($this->request($raw));
        $this->assertSame('  keep spaces  ', $verified['text']);
        $request = $this->request(trim($raw));
        $request->headers->set('Pixelforge-Signature', $this->signature($raw.' '));
        $this->assertRejected($request, 400);
    }

    #[DataProvider('invalidSignatures')]
    public function test_strict_signature_parser_rejects_ambiguous_or_malformed_headers(string $signature): void
    {
        $request = $this->request($this->ping());
        $request->headers->set('Pixelforge-Signature', str_replace(['TIMESTAMP', 'DIGEST'], [(string) now()->timestamp, hash_hmac('sha256', now()->timestamp.'.'.$this->ping(), 'workflow-test-secret')], $signature));
        $this->assertRejected($request, 400);
    }

    public static function invalidSignatures(): array
    {
        return array_map(fn ($value) => [$value], ['', 't=TIMESTAMP,v1=DIGEST,t=TIMESTAMP', 't=TIMESTAMP,v1=DIGEST,v1=DIGEST', 'v1=DIGEST,t=TIMESTAMP', 't=+TIMESTAMP,v1=DIGEST', 't=TIMESTAMP, v1=DIGEST', 't=TIMESTAMP,v1=abc', 't=TIMESTAMP,v1=DIGEST,unknown=1']);
    }

    public function test_replay_tolerance_is_exactly_300_seconds_in_both_directions(): void
    {
        foreach ([-300, 300] as $offset) {
            $request = $this->request($this->ping());
            $request->headers->set('Pixelforge-Signature', $this->signature($this->ping(), now()->timestamp + $offset));
            $this->assertSame('evt_test1', app(PixelforgeWebhookVerifier::class)->verify($request)['id']);
        }
        foreach ([-301, 301] as $offset) {
            $request = $this->request($this->ping());
            $request->headers->set('Pixelforge-Signature', $this->signature($this->ping(), now()->timestamp + $offset));
            $this->assertRejected($request, 400);
        }
    }

    public function test_body_limit_accepts_exactly_one_mebibyte_and_rejects_invalid_calendar_dates(): void
    {
        $raw = $this->ping().str_repeat(' ', 1048576 - strlen($this->ping()));
        $this->assertSame('evt_test1', app(PixelforgeWebhookVerifier::class)->verify($this->request($raw))['id']);
        $raw = str_replace('2026-11-17', '2026-02-31', $this->ping());
        $this->assertRejected($this->request($raw), 400);
    }

    public function test_missing_configuration_fails_closed_and_large_body_is_rejected(): void
    {
        $this->assertRejected($this->request(str_repeat('x', 1048577)), 413);
        foreach (['api_key', 'webhook_secret'] as $field) {
            $old = config('services.pixelforge.'.$field);
            config(['services.pixelforge.'.$field => '']);
            $this->assertRejected($this->request($this->ping()), 503);
            config(['services.pixelforge.'.$field => $old]);
        }
    }

    public function test_valid_signature_does_not_excuse_invalid_json_or_mismatched_headers(): void
    {
        foreach (['{', '[]', 'null', '{}'] as $raw) {
            $this->assertRejected($this->request($raw), 400);
        }
        foreach (['Pixelforge-Event-Id', 'Pixelforge-Event-Type'] as $header) {
            $request = $this->request($this->ping());
            $request->headers->set($header, 'different');
            $this->assertRejected($request, 400);
        }
        $payload = json_decode($this->ping(), true);
        $payload['type'] = 'generation.succeeded';
        $payload['data'] = ['object' => ['object' => 'generation', 'status' => 'processing']];
        $request = $this->request(json_encode($payload));
        $request->headers->set('Pixelforge-Event-Type', 'generation.succeeded');
        $this->assertRejected($request, 400);
    }

    private function ping(): string
    {
        return '{"id":"evt_test1","object":"event","type":"ping","created_at":"2026-11-17T12:00:00Z"}';
    }

    private function signature(string $raw, ?int $timestamp = null): string
    {
        $timestamp ??= now()->timestamp;

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$raw, 'workflow-test-secret');
    }

    private function request(string $raw): Request
    {
        return Request::create('/webhooks/pixelforge', 'POST', server: [
            'HTTP_PIXELFORGE_SIGNATURE' => $this->signature($raw), 'HTTP_PIXELFORGE_EVENT_ID' => 'evt_test1', 'HTTP_PIXELFORGE_EVENT_TYPE' => 'ping',
        ], content: $raw);
    }

    private function assertRejected(Request $request, int $status): void
    {
        try {
            app(PixelforgeWebhookVerifier::class)->verify($request);
            $this->fail('Expected rejection.');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }
}

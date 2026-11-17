<?php

namespace Tests\Feature;

use App\Actions\Generations\ApplyProviderGeneration;
use App\Actions\Generations\RecoverGenerationWork;
use App\Jobs\PublishOutboxJob;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Services\Mercure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Fixtures\GenerationEvidence;
use Tests\TestCase;

final class LiveUpdatesTest extends TestCase
{
    use GenerationEvidence, RefreshDatabase;

    private const PUBLISHER_KEY = 'publisher-test-key-with-at-least-32-bytes!';

    private const SUBSCRIBER_KEY = 'subscriber-test-key-with-at-least-32-bytes';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://pixelforge.test',
            'services.mercure.publisher_key' => self::PUBLISHER_KEY,
            'services.mercure.subscriber_key' => self::SUBSCRIBER_KEY,
            'services.mercure.internal_url' => 'http://hub.internal/.well-known/mercure',
        ]);
        Queue::fake();
        $this->freezeSecond();
    }

    public function test_subscriber_cookie_grants_only_the_users_own_topic(): void
    {
        $user = User::factory()->create();
        $cookie = app(Mercure::class)->subscriberCookie($user);

        $this->assertSame('__Secure-mercure_access_token', $cookie->getName());
        $this->assertSame('/.well-known/mercure', $cookie->getPath());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());

        [$header, $claims] = $this->verified($cookie->getValue(), self::SUBSCRIBER_KEY);
        $this->assertSame(['alg' => 'HS256', 'typ' => 'at+jwt'], $header);
        $this->assertSame('https://pixelforge.test', $claims['iss']);
        $this->assertSame('https://pixelforge.test/.well-known/mercure', $claims['aud']);
        $this->assertSame(now()->getTimestamp() + 3600, $claims['exp']);
        $this->assertSame([[
            'type' => 'https://mercure.rocks/authorization-detail',
            'actions' => ['subscribe'],
            'topics' => [['match' => "https://pixelforge.test/users/{$user->id}/generations"]],
        ]], $claims['authorization_details']);

        // Signed with the subscriber key only: the publisher key must not verify it.
        $this->assertFalse($this->signatureMatches($cookie->getValue(), self::PUBLISHER_KEY));
    }

    public function test_history_page_issues_a_plain_cookie_and_the_users_topic(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('generations.index'));

        $response->assertOk()
            ->assertPlainCookie(Mercure::COOKIE)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('live.hub', '/.well-known/mercure')
                ->where('live.topic', "https://pixelforge.test/users/{$user->id}/generations"));
        // Laravel must not encrypt it: the hub reads a raw JWT.
        $this->verified($response->getCookie(Mercure::COOKIE, decrypt: false)->getValue(), self::SUBSCRIBER_KEY);
    }

    public function test_logout_expires_the_stream_cookie(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('logout'))
            ->assertCookieExpired(Mercure::COOKIE);
    }

    public function test_committed_change_is_published_as_private_invalidation_once(): void
    {
        $generation = $this->preparedGeneration();
        app(ApplyProviderGeneration::class)->execute($generation->id, $this->evidence($generation), $this->fingerprint());
        Queue::assertPushed(PublishOutboxJob::class, 1);
        Http::fake(['hub.internal/*' => Http::response('urn:uuid:1')]);

        app(PublishOutboxJob::class)->handle(app(Mercure::class));
        app(PublishOutboxJob::class)->handle(app(Mercure::class));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($generation): bool {
            $topic = "https://pixelforge.test/users/{$generation->user_id}/generations";
            [, $claims] = $this->verified(substr($request->header('Authorization')[0], 7), self::PUBLISHER_KEY);

            return $request->url() === 'http://hub.internal/.well-known/mercure'
                && $request['topic'] === $topic
                && $request['private'] === 'on'
                // JSONB does not preserve key order, so compare as a dictionary.
                && json_decode($request['data'], true) == ['generation_id' => $generation->id, 'revision' => 1]
                && $claims['authorization_details'][0]['actions'] === ['publish']
                && $claims['authorization_details'][0]['topics'] === [['match' => $topic]];
        });
        $message = OutboxMessage::query()->sole();
        $this->assertNotNull($message->published_at);
        $this->assertSame(1, $message->publication_attempts);
    }

    public function test_hub_failure_keeps_intent_counts_the_attempt_and_stops_the_batch(): void
    {
        foreach ([$this->preparedGeneration(), $this->preparedGeneration()] as $generation) {
            app(ApplyProviderGeneration::class)->execute($generation->id, $this->evidence($generation), $this->fingerprint());
        }
        Http::fake(['hub.internal/*' => Http::response('down', 503)]);

        app(PublishOutboxJob::class)->handle(app(Mercure::class));

        Http::assertSentCount(1);
        $this->assertSame([0, 1], OutboxMessage::query()->whereNull('published_at')->orderBy('publication_attempts')->pluck('publication_attempts')->all());

        app(RecoverGenerationWork::class)->execute();
        Queue::assertPushed(PublishOutboxJob::class, 3); // two commits plus one recovery
    }

    public function test_ownerless_intent_is_retired_without_publishing(): void
    {
        $generation = $this->preparedGeneration();
        app(ApplyProviderGeneration::class)->execute($generation->id, $this->evidence($generation), $this->fingerprint());
        DB::table('outbox_messages')->update(['user_id' => null]);
        Http::fake();

        app(PublishOutboxJob::class)->handle(app(Mercure::class));

        Http::assertNothingSent();
        $this->assertNotNull(OutboxMessage::query()->sole()->published_at);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function verified(string $token, string $key): array
    {
        $this->assertTrue($this->signatureMatches($token, $key), 'Token signature does not verify.');
        [$header, $claims] = explode('.', $token);

        return [$this->decode($header), $this->decode($claims)];
    }

    private function signatureMatches(string $token, string $key): bool
    {
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $parts[0].'.'.$parts[1], $key, true)), '+/', '-_'), '=');

        return hash_equals($expected, $parts[2]);
    }

    /** @return array<string, mixed> */
    private function decode(string $segment): array
    {
        return json_decode(base64_decode(strtr($segment, '-_', '+/')), true, 512, JSON_THROW_ON_ERROR);
    }
}

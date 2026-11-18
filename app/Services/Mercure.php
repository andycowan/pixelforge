<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;

final class Mercure
{
    public const COOKIE = '__Secure-mercure_access_token';

    public const PATH = '/.well-known/mercure';

    private const SUBSCRIBER_TTL = 3600;

    private const PUBLISHER_TTL = 60;

    public function topicFor(int $userId): string
    {
        return config('app.url').'/users/'.$userId.'/generations';
    }

    public function subscriberCookie(User $user): Cookie
    {
        $token = $this->token('subscriber_key', 'subscribe', $this->topicFor($user->id), self::SUBSCRIBER_TTL, 'users/'.$user->id);

        return $this->cookie($token, now()->addSeconds(self::SUBSCRIBER_TTL)->getTimestamp());
    }

    public function forgetCookie(): Cookie
    {
        return $this->cookie('', 1);
    }

    /** @param  array<string, mixed>  $data */
    public function publish(string $topic, array $data): void
    {
        $token = $this->token('publisher_key', 'publish', $topic, self::PUBLISHER_TTL, 'pixelforge');

        Http::withToken($token)
            ->asForm()
            ->connectTimeout(2)
            ->timeout(5)
            ->post(config('services.mercure.internal_url'), [
                'topic' => $topic,
                'data' => json_encode($data, JSON_THROW_ON_ERROR),
                'private' => 'on',
            ])
            ->throw();
    }

    private function token(string $keyName, string $action, string $topic, int $ttl, string $subject): string
    {
        $key = config('services.mercure.'.$keyName);
        if (! is_string($key) || trim($key) === '') {
            throw new RuntimeException('Mercure '.$keyName.' is not configured.');
        }
        $issuer = config('app.url');
        $now = now()->getTimestamp();
        $segments = array_map(self::base64Url(...), [
            json_encode(['alg' => 'HS256', 'typ' => 'at+jwt'], JSON_THROW_ON_ERROR),
            json_encode([
                'iss' => $issuer,
                'sub' => $issuer.'/'.$subject,
                'client_id' => $issuer,
                'aud' => $issuer.self::PATH,
                'iat' => $now,
                'exp' => $now + $ttl,
                'jti' => 'urn:uuid:'.Str::uuid(),
                'authorization_details' => [[
                    'type' => 'https://mercure.rocks/authorization-detail',
                    'actions' => [$action],
                    'topics' => [['match' => $topic]],
                ]],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
        $signingInput = implode('.', $segments);

        return $signingInput.'.'.self::base64Url(hash_hmac('sha256', $signingInput, $key, true));
    }

    private function cookie(string $value, int $expires): Cookie
    {
        return Cookie::create(self::COOKIE, $value, $expires, self::PATH, null, true, true, false, Cookie::SAMESITE_STRICT);
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

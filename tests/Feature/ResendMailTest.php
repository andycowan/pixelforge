<?php

namespace Tests\Feature;

use App\Models\User;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\Mail;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Resend\Client;
use Resend\Transporters\HttpTransporter;
use Resend\ValueObjects\ApiKey;
use Resend\ValueObjects\Transporter\BaseUri;
use Resend\ValueObjects\Transporter\Headers;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class ResendMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_resend_mailer_resolves_without_changing_the_test_default(): void
    {
        config(['services.resend.key' => 're_test_key']);

        $this->assertInstanceOf(ResendTransport::class, Mail::mailer('resend')->getSymfonyTransport());
        $this->assertSame('array', config('mail.default'));
    }

    public function test_registration_sends_verification_mail_through_resend(): void
    {
        $this->mockResendResponse(200, '{"id":"verification-email"}', 'test@example.com', 'Verify your email address', '/email/verify/');

        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_password_reset_sends_mail_through_resend(): void
    {
        $user = User::factory()->create();
        $this->mockResendResponse(200, '{"id":"reset-email"}', $user->email, 'Reset your password', '/reset-password/');

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_resend_failure_is_not_silently_treated_as_delivery(): void
    {
        $user = User::factory()->unverified()->create();
        $this->mockResendResponse(403, '{"name":"validation_error","message":"Sender domain is not verified"}', $user->email, 'Verify your email address', '/email/verify/');

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Sender domain is not verified');

        $user->sendEmailVerificationNotification();
    }

    private function mockResendResponse(int $status, string $body, string $recipient, string $subject, string $actionPath): void
    {
        config([
            'mail.default' => 'resend',
            'mail.from.address' => 'hello@example.com',
            'mail.from.name' => 'Pixelforge',
            'services.resend.key' => 're_test_key',
        ]);

        $http = $this->createMock(ClientInterface::class);
        $http->expects($this->once())->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use ($status, $body, $recipient, $subject, $actionPath): ResponseInterface {
                $this->assertSame('POST', $request->getMethod());
                $this->assertSame('https://api.resend.com/emails', (string) $request->getUri());
                $this->assertSame('Bearer re_test_key', $request->getHeaderLine('Authorization'));

                $payload = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('"Pixelforge" <hello@example.com>', $payload['from']);
                $this->assertCount(1, $payload['to']);
                $this->assertStringContainsString($recipient, $payload['to'][0]);
                $this->assertSame($subject, $payload['subject']);
                $this->assertStringContainsString($actionPath, $payload['html']);
                $this->assertStringContainsString($actionPath, $payload['text']);

                return new Response($status, ['Content-Type' => 'application/json'], $body);
            });

        // Laravel's native factory creates its own Guzzle client; replace only the network boundary.
        Mail::mailer()->setSymfonyTransport(new ResendTransport(new Client(new HttpTransporter(
            $http,
            BaseUri::from('api.resend.com'),
            Headers::withAuthorization(ApiKey::from('re_test_key')),
        ))));
    }
}

<?php

namespace Tests\Feature;

use App\Http\Integrations\Pixelforge\Requests\GetGenerationImage;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Response;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class GenerationImageDownloadTest extends TestCase
{
    use RefreshDatabase;

    private const IMAGE_URL = 'https://pixelforge.promptedit.dev/images/gen_abc.png?expires=1&signature=s';

    private const PNG = "\x89PNG\r\n\x1a\nfake-image-bytes";

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1', 'services.pixelforge.api_key' => 'never-sent-to-images']);
    }

    public function test_the_owner_downloads_the_image_as_a_named_attachment(): void
    {
        $user = User::factory()->create();
        $generation = $this->withImage($user, prompt: 'A misty harbour at dawn, with gulls circling');
        Saloon::fake([GetGenerationImage::class => MockResponse::make(self::PNG, 200, ['Content-Type' => 'image/png'])]);

        $response = $this->actingAs($user)->get(route('generations.download', $generation->id));

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(self::PNG, $response->getContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        // Cut at a word boundary within 40 characters, never mid-word.
        $expected = 'pixelforge-a-misty-harbour-at-dawn-with-gulls-'.strtolower(substr($generation->id, -6)).'.png';
        $this->assertSame('attachment; filename='.$expected, $response->headers->get('Content-Disposition'));
        // Fetched from the stored signed URL, without our API key.
        Saloon::assertSent(fn (GetGenerationImage $request, Response $sent): bool => $sent->getPendingRequest()->getUrl() === self::IMAGE_URL
            && $sent->getPendingRequest()->headers()->get('Authorization') === null);
    }

    public function test_only_the_owner_can_download_and_guests_must_sign_in(): void
    {
        $generation = $this->withImage(User::factory()->create());
        Saloon::fake([]);

        $this->actingAs(User::factory()->create())->get(route('generations.download', $generation->id))->assertNotFound();
        auth()->logout();
        $this->get(route('generations.download', $generation->id))->assertRedirect(route('login'));
        Saloon::assertNothingSent();
    }

    public function test_expired_or_missing_images_are_not_fetched(): void
    {
        $user = User::factory()->create();
        $expired = $this->withImage($user, expiresAt: now()->subMinute());
        $none = Generation::factory()->for($user)->create();
        Saloon::fake([]);

        $this->actingAs($user)->get(route('generations.download', $expired->id))->assertStatus(410);
        $this->actingAs($user)->get(route('generations.download', $none->id))->assertNotFound();
        Saloon::assertNothingSent();
    }

    public function test_a_stored_url_on_another_host_is_refused_before_any_request(): void
    {
        $user = User::factory()->create();
        $generation = $this->withImage($user, url: 'https://attacker.example/images/x.png');
        Saloon::fake([]);

        $this->actingAs($user)->get(route('generations.download', $generation->id))->assertStatus(502);
        Saloon::assertNothingSent();
    }

    /** @return array<string, array{MockResponse}> */
    public static function unusableResponses(): array
    {
        return [
            'provider error' => [MockResponse::make('missing', 404, ['Content-Type' => 'text/plain'])],
            'not an image' => [MockResponse::make('<html>', 200, ['Content-Type' => 'text/html'])],
            'empty body' => [MockResponse::make('', 200, ['Content-Type' => 'image/png'])],
            'too large' => [MockResponse::make(str_repeat('x', 20 * 1024 * 1024 + 1), 200, ['Content-Type' => 'image/png'])],
        ];
    }

    #[DataProvider('unusableResponses')]
    public function test_unusable_provider_responses_are_a_bad_gateway(MockResponse $mock): void
    {
        $user = User::factory()->create();
        $generation = $this->withImage($user);
        Saloon::fake([GetGenerationImage::class => $mock]);

        $this->actingAs($user)->get(route('generations.download', $generation->id))->assertStatus(502);
    }

    public function test_downloads_are_rate_limited_separately(): void
    {
        $this->assertContains('throttle:30,1,generation-downloads:', Route::getRoutes()->getByName('generations.download')->gatherMiddleware());
    }

    private function withImage(User $user, string $url = self::IMAGE_URL, mixed $expiresAt = null, string $prompt = 'A lighthouse'): Generation
    {
        $generation = Generation::factory()->for($user)->create(['prompt' => $prompt]);
        // Test fixture only: provider evidence is normally written by the settlement workflow.
        DB::table('generations')->where('id', $generation->id)->update(['image_url' => $url, 'image_url_expires_at' => $expiresAt ?? now()->addHour()]);

        return $generation->fresh();
    }
}

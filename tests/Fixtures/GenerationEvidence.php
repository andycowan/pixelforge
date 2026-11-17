<?php

namespace Tests\Fixtures;

use App\Http\Integrations\Pixelforge\Data\ProviderGeneration;
use App\Models\Generation;
use Illuminate\Support\Facades\DB;

trait GenerationEvidence
{
    private function fingerprint(): string
    {
        return hash('sha256', 'test-key-never-live');
    }

    private function preparedGeneration(): Generation
    {
        $generation = Generation::factory()->create();
        DB::transaction(function () use ($generation): void {
            Generation::query()->lockForUpdate()->findOrFail($generation->id)->prepareSubmission($this->fingerprint());
        });

        return $generation->refresh();
    }

    /** @param array<string, mixed> $changes */
    private function evidence(Generation $generation, string $status = 'succeeded', array $changes = []): ProviderGeneration
    {
        $terminal = in_array($status, ['succeeded', 'failed'], true);

        return ProviderGeneration::fromArray(array_replace([
            'id' => 'gen_'.$generation->id,
            'object' => 'generation',
            'status' => $status,
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'reference_images' => $generation->reference_images,
            'metadata' => ['generation_id' => $generation->id, 'key_fingerprint' => $this->fingerprint()],
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'usage' => $status === 'succeeded' ? [
                'input_text_tokens' => 3,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 1100,
                'total_tokens' => 2203,
            ] : null,
            'image_url' => $status === 'succeeded' ? 'https://example.com/result.png?signature=first' : null,
            'image_url_expires_at' => $status === 'succeeded' ? '2026-11-18T12:00:20Z' : null,
            'error' => $status === 'failed' ? ['code' => 'generation_failed', 'message' => 'Private provider diagnostic'] : null,
            'created_at' => '2026-11-17T12:00:00Z',
            'started_at' => $status !== 'queued' ? '2026-11-17T12:00:02Z' : null,
            'completed_at' => $terminal ? '2026-11-17T12:00:20Z' : null,
        ], $changes));
    }
}

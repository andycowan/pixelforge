<?php

namespace Tests\Support;

use App\Models\Generation;

trait GenerationWorkflowFixture
{
    private function providerPayload(Generation $generation, string $status = 'queued', array $changes = []): array
    {
        return array_replace([
            'id' => 'gen_'.strtolower($generation->id),
            'object' => 'generation',
            'status' => $status,
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'reference_images' => $generation->reference_images,
            'metadata' => ['generation_id' => $generation->id, 'key_fingerprint' => hash('sha256', 'workflow-test-key')],
            'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
            'usage' => $status === 'succeeded' ? ['input_text_tokens' => 3, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1100, 'total_tokens' => 2203] : null,
            'image_url' => $status === 'succeeded' ? 'https://example.com/result.png' : null,
            'image_url_expires_at' => $status === 'succeeded' ? now()->addDay()->toIso8601ZuluString() : null,
            'error' => $status === 'failed' ? ['code' => 'generation_failed', 'message' => 'Generation failed.'] : null,
            'created_at' => now()->subSeconds(2)->toIso8601ZuluString(),
            'started_at' => $status === 'queued' ? null : now()->subSecond()->toIso8601ZuluString(),
            'completed_at' => in_array($status, ['succeeded', 'failed'], true) ? now()->toIso8601ZuluString() : null,
        ], $changes);
    }

    private function eventPayload(array $object, string $id = 'evt_workflow1'): array
    {
        return ['id' => $id, 'object' => 'event', 'type' => 'generation.'.$object['status'], 'created_at' => now()->toIso8601ZuluString(), 'data' => ['object' => $object]];
    }
}

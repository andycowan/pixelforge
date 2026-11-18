<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * @property string $id
 * @property array<string, mixed> $payload
 * @property string $provider_key_fingerprint
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 * @property string|null $outcome
 * @property array<string, mixed>|null $error
 */
#[Fillable(['id', 'payload', 'provider_key_fingerprint', 'received_at'])]
final class WebhookEvent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    private bool $processing = false;

    protected function casts(): array
    {
        return ['payload' => 'array', 'received_at' => 'datetime', 'processed_at' => 'datetime', 'error' => 'array'];
    }

    /** @param array<string, mixed> $options */
    public function save(array $options = [])
    {
        if ($this->exists && (! $this->processing || $this->isDirty(['id', 'payload', 'provider_key_fingerprint', 'received_at', 'created_at']))) {
            throw new LogicException('Webhook evidence is immutable.');
        }

        return parent::save($options);
    }

    public function delete()
    {
        throw new LogicException('Webhook evidence is retained.');
    }

    /** @param  array<string, mixed>|null  $diagnostic */
    public function markProcessed(string $outcome, ?array $diagnostic = null): void
    {
        if (! $this->exists || $this->isDirty() || DB::transactionLevel() === 0 || ! in_array($outcome, ['advance', 'duplicate', 'stale', 'conflict', 'ignored', 'unmatched', 'invalid'], true)) {
            throw new LogicException('Webhook processing requires a transaction and a supported outcome.');
        }
        if ($this->processed_at !== null) {
            return;
        }
        $this->processing = true;
        try {
            if (! $this->forceFill(['outcome' => $outcome, 'error' => $diagnostic, 'processed_at' => now()])->saveOrFail()) {
                throw new LogicException('Webhook processing marker was not persisted.');
            }
        } finally {
            $this->processing = false;
        }
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * @property string $id
 * @property string $generation_id
 * @property int|null $user_id
 * @property int $revision
 * @property string $type
 * @property array<string, mixed> $payload
 */
final class OutboxMessage extends Model
{
    use HasUlids;

    private bool $recording = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'revision' => 'integer',
            'publication_attempts' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public static function recordGenerationChange(Generation $generation): self
    {
        if (! $generation->exists || $generation->getConnection()->transactionLevel() < 1) {
            throw new LogicException('Outbox intent requires a persisted generation in a transaction.');
        }
        $message = new self;
        $message->recording = true;
        try {
            $message->generation_id = $generation->id;
            $message->user_id = $generation->user_id;
            $message->revision = $generation->revision;
            $message->type = 'generation.updated';
            $message->payload = ['generation_id' => $generation->id, 'revision' => $generation->revision];
            if (! $message->saveOrFail()) {
                throw new LogicException('Generation notification intent was not persisted.');
            }
        } finally {
            $message->recording = false;
        }

        return $message;
    }

    /** @return Collection<int, self> */
    public static function nextUnpublished(int $limit): Collection
    {
        return self::query()->whereNull('published_at')->orderBy('created_at')->orderBy('id')->limit($limit)->get();
    }

    public function markPublished(): void
    {
        self::query()->whereKey($this->id)->whereNull('published_at')->update([
            'published_at' => now(),
            'publication_attempts' => DB::raw('publication_attempts + 1'),
            'updated_at' => now(),
        ]);
    }

    public function recordPublicationFailure(): void
    {
        self::query()->whereKey($this->id)->whereNull('published_at')->update([
            'publication_attempts' => DB::raw('publication_attempts + 1'),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $options */
    public function save(array $options = [])
    {
        if ($this->exists || ! $this->recording || $this->getConnection()->transactionLevel() < 1) {
            throw new LogicException('Outbox intent is immutable and recorded only by a generation transaction.');
        }

        return parent::save($options);
    }

    public function delete()
    {
        throw new LogicException('Outbox intent is retained.');
    }
}

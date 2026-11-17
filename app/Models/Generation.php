<?php

namespace App\Models;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationSubmissionStatus;
use App\Enums\ProviderGenerationStatus;
use App\StateMachines\ProviderGenerationStateMachine;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * @property string $id
 * @property int|null $user_id
 * @property string $submission_token
 * @property string $prompt
 * @property GenerationSize $size
 * @property GenerationQuality $quality
 * @property list<string> $reference_images
 * @property array<string, mixed> $quote_snapshot
 * @property int $estimated_cost_nanodollars
 * @property int $maximum_cost_nanodollars
 * @property GenerationSubmissionStatus $submission_status
 * @property int $submission_attempts
 * @property Carbon|null $last_attempt_at
 * @property Carbon|null $next_attempt_at
 * @property array<string, mixed>|null $submission_error
 * @property string|null $provider_key_fingerprint
 * @property string|null $provider_id
 * @property ProviderGenerationStatus|null $provider_status
 * @property array<string, mixed>|null $rates_snapshot
 * @property array<string, mixed>|null $usage
 * @property string|null $image_url
 * @property Carbon|null $image_url_expires_at
 * @property array<string, mixed>|null $provider_error
 * @property Carbon|null $provider_created_at
 * @property Carbon|null $provider_started_at
 * @property Carbon|null $provider_completed_at
 * @property int $revision
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
#[Fillable([
    'user_id',
    'submission_token',
    'prompt',
    'size',
    'quality',
    'reference_images',
    'quote_snapshot',
    'estimated_cost_nanodollars',
    'maximum_cost_nanodollars',
])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUlids;

    /**
     * New requests begin pending and unobserved. Provider evidence is never
     * a caller-provided default.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'submission_status' => 'pending',
        'submission_attempts' => 0,
        'reference_images' => '[]',
        'revision' => 0,
    ];

    /**
     * Request/identity evidence is frozen once the row exists.
     *
     * @var list<string>
     */
    private const IMMUTABLE_ATTRIBUTES = [
        'id',
        'user_id',
        'submission_token',
        'prompt',
        'size',
        'quality',
        'reference_images',
        'quote_snapshot',
        'estimated_cost_nanodollars',
        'maximum_cost_nanodollars',
        'created_at',
    ];

    /**
     * Submission bookkeeping and provider evidence are reserved for the future
     * apply/worker paths. They are read-only in this slice.
     *
     * @var list<string>
     */
    private const RESERVED_ATTRIBUTES = [
        'submission_status',
        'submission_attempts',
        'last_attempt_at',
        'next_attempt_at',
        'submission_error',
        'provider_key_fingerprint',
        'provider_id',
        'provider_status',
        'rates_snapshot',
        'usage',
        'image_url',
        'image_url_expires_at',
        'provider_error',
        'provider_created_at',
        'provider_started_at',
        'provider_completed_at',
        'revision',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => GenerationSize::class,
            'quality' => GenerationQuality::class,
            'reference_images' => 'array',
            'quote_snapshot' => 'array',
            'estimated_cost_nanodollars' => 'integer',
            'maximum_cost_nanodollars' => 'integer',
            'submission_status' => GenerationSubmissionStatus::class,
            'submission_attempts' => 'integer',
            'last_attempt_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'submission_error' => 'array',
            'provider_status' => ProviderGenerationStatus::class,
            'rates_snapshot' => 'array',
            'usage' => 'array',
            'image_url_expires_at' => 'datetime',
            'provider_error' => 'array',
            'provider_created_at' => 'datetime',
            'provider_started_at' => 'datetime',
            'provider_completed_at' => 'datetime',
            'revision' => 'integer',
        ];
    }

    /**
     * Reject reserved attributes loudly rather than silently dropping them.
     * Direct property assignment followed by save is guarded by model events.
     *
     * @param  array<string, mixed>  $attributes
     * @return $this
     */
    public function fill(array $attributes)
    {
        foreach (self::RESERVED_ATTRIBUTES as $attribute) {
            if (array_key_exists($attribute, $attributes)) {
                throw new LogicException("Generation attribute [{$attribute}] is read-only.");
            }
        }

        return parent::fill($attributes);
    }

    protected static function booted(): void
    {
        static::creating(function (Generation $generation): void {
            $generation->guardCreation();
        });

        static::updating(function (Generation $generation): void {
            $generation->guardUpdate();
        });

        static::deleting(function (): void {
            throw new LogicException('Generation records are retained as evidence; deletion is not supported.');
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to generations owned by the given user.
     *
     * @param  Builder<Generation>  $query
     */
    #[Scope]
    protected function forUser(Builder $query, User $user): void
    {
        if (! $user->exists || $user->getKey() === null) {
            throw new InvalidArgumentException('An owner scope requires a persisted user.');
        }

        $query->where('user_id', $user->getKey());
    }

    /**
     * A fresh machine for guards only. It does not persist its own mutations.
     */
    public function stateMachine(): ProviderGenerationStateMachine
    {
        return new ProviderGenerationStateMachine($this->provider_status);
    }

    /**
     * Conservative public projection. Internal keys, quotes, errors, usage and
     * provider identifiers stay out of the browser-facing shape.
     *
     * @return array<string, mixed>
     */
    public function toHistoryArray(): array
    {
        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
            'reference_images' => $this->reference_images,
            'submission_status' => $this->submission_status->value,
            'provider_status' => $this->provider_status?->value,
            'estimated_cost_usd' => self::formatNanodollars($this->estimated_cost_nanodollars),
            'maximum_cost_usd' => self::formatNanodollars($this->maximum_cost_nanodollars),
            'image_url' => $this->image_url,
            'image_url_expires_at' => $this->image_url_expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        // Implicit JSON/Inertia serialization must use the same safe public shape.
        return $this->toHistoryArray();
    }

    private function guardCreation(): void
    {
        $this->assertProviderSurfaceUntouchedOnCreate();
        $this->assertValidRequest();
    }

    private function assertProviderSurfaceUntouchedOnCreate(): void
    {
        foreach (self::RESERVED_ATTRIBUTES as $attribute) {
            if ($this->isDirty($attribute)) {
                throw new LogicException("Generation attribute [{$attribute}] cannot be set when creating a request.");
            }
        }
    }

    private function assertValidRequest(): void
    {
        $attributes = $this->getAttributes();

        $ownerId = $attributes['user_id'] ?? null;

        if (! is_int($ownerId)
            || $ownerId < 1
            || ! User::query()->whereKey($ownerId)->exists()) {
            throw new InvalidArgumentException('A generation requires an existing owner.');
        }

        $submissionToken = $attributes['submission_token'] ?? null;

        if (! is_string($submissionToken) || ! Str::isUuid($submissionToken)) {
            throw new InvalidArgumentException('A generation requires a valid UUID submission token.');
        }

        $prompt = $attributes['prompt'] ?? null;

        if (! is_string($prompt)
            || ! mb_check_encoding($prompt, 'UTF-8')
            || preg_match('/\S/u', $prompt) !== 1
            || mb_strlen($prompt, 'UTF-8') > 4000) {
            throw new InvalidArgumentException('A generation prompt must be 1 to 4000 Unicode characters and not blank.');
        }

        $size = $attributes['size'] ?? null;

        if (! is_string($size) || GenerationSize::tryFrom($size) === null) {
            throw new InvalidArgumentException('Unsupported generation size.');
        }

        $quality = $attributes['quality'] ?? null;

        if (! is_string($quality) || GenerationQuality::tryFrom($quality) === null) {
            throw new InvalidArgumentException('Unsupported generation quality.');
        }

        $referenceImages = $this->getAttribute('reference_images');

        if (! is_array($referenceImages)
            || ! array_is_list($referenceImages)
            || count($referenceImages) > 4) {
            throw new InvalidArgumentException('Reference images must be an array of at most four URLs.');
        }

        foreach ($referenceImages as $referenceImage) {
            if (! is_string($referenceImage) || ! Str::isUrl($referenceImage, ['http', 'https'])) {
                throw new InvalidArgumentException('Reference images must be http(s) URLs.');
            }
        }

        $quoteSnapshot = $this->getAttribute('quote_snapshot');

        if (! is_array($quoteSnapshot)
            || $quoteSnapshot === []
            || array_is_list($quoteSnapshot)) {
            throw new InvalidArgumentException('A generation requires a nonempty quote snapshot object.');
        }

        $estimated = self::assertNanodollars($attributes['estimated_cost_nanodollars'] ?? null, 'estimated_cost_nanodollars');
        $maximum = self::assertNanodollars($attributes['maximum_cost_nanodollars'] ?? null, 'maximum_cost_nanodollars');

        if ($maximum < $estimated) {
            throw new InvalidArgumentException('The maximum generation cost cannot be below the estimate.');
        }
    }

    private function guardUpdate(): void
    {
        foreach ([...self::IMMUTABLE_ATTRIBUTES, ...self::RESERVED_ATTRIBUTES] as $attribute) {
            if ($this->isDirty($attribute)) {
                throw new LogicException("Generation attribute [{$attribute}] is read-only after creation.");
            }
        }
    }

    private static function assertNanodollars(mixed $value, string $attribute): int
    {
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException("Generation attribute [{$attribute}] must be a nonnegative integer number of nanodollars.");
        }

        return $value;
    }

    private static function formatNanodollars(int $nanodollars): string
    {
        return sprintf(
            '%d.%09d',
            intdiv($nanodollars, 1_000_000_000),
            $nanodollars % 1_000_000_000,
        );
    }
}

<?php

namespace App\Models;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationSubmissionStatus;
use App\Enums\ProviderGenerationStatus;
use App\Enums\ProviderTransitionOutcome;
use App\Exceptions\ProviderEvidenceConflict;
use App\Http\Integrations\Pixelforge\Data\ProviderGeneration;
use App\StateMachines\ProviderGenerationStateMachine;
use Carbon\CarbonInterface;
use Database\Factories\GenerationFactory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @var list<string> */
    private array $authorizedMutation = [];

    /**
     * Submission bookkeeping and provider evidence can only be changed by
     * the named transaction-bound mutations, never raw assignment.
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
     * Direct property assignment is guarded by save as well as model events.
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

    /** @return HasOne<GenerationCharge, $this> */
    public function charge(): HasOne
    {
        return $this->hasOne(GenerationCharge::class);
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public static function historyFor(User $user): LengthAwarePaginator
    {
        return self::query()->forUser($user)->with('charge')->latest()->orderByDesc('id')
            ->paginate(20)->through(fn (Generation $generation): array => $generation->toHistoryArray());
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

    public function prepareSubmission(string $keyFingerprint): void
    {
        $this->assertMutationContext();
        self::assertFingerprint($keyFingerprint);
        if ($this->provider_key_fingerprint !== null) {
            if (! hash_equals($this->provider_key_fingerprint, $keyFingerprint)) {
                throw new ProviderEvidenceConflict('Generation provider key cannot be rebound.');
            }

            return;
        }
        if ($this->submission_status !== GenerationSubmissionStatus::Pending || $this->submission_attempts !== 0 || $this->provider_id !== null) {
            throw new LogicException('Prepare submission before the first provider attempt.');
        }
        $this->persistMutation(['provider_key_fingerprint' => $keyFingerprint]);
    }

    public function claimSubmissionAttempt(CarbonInterface $leaseUntil): bool
    {
        $this->assertMutationContext();
        if (! in_array($this->submission_status, [GenerationSubmissionStatus::Pending, GenerationSubmissionStatus::Uncertain], true)
            || $this->provider_id !== null || ! $this->isDue()) {
            return false;
        }
        self::assertFingerprint($this->provider_key_fingerprint ?? '');
        if ($leaseUntil->lessThanOrEqualTo(now())) {
            throw new InvalidArgumentException('A submission lease must be in the future.');
        }
        $this->persistMutation([
            'submission_status' => GenerationSubmissionStatus::Uncertain,
            'submission_attempts' => $this->submission_attempts + 1,
            'last_attempt_at' => now(),
            'next_attempt_at' => $leaseUntil,
        ]);

        return true;
    }

    /** @param array<string, mixed> $error */
    public function recordSubmissionProblem(array $error, ?CarbonInterface $retryAt, GenerationSubmissionStatus $status): void
    {
        $this->assertMutationContext();
        if ($this->submission_status === GenerationSubmissionStatus::Accepted || $this->provider_id !== null) {
            return;
        }
        if (! in_array($status, [GenerationSubmissionStatus::Pending, GenerationSubmissionStatus::Uncertain, GenerationSubmissionStatus::Rejected], true)
            || ! is_string($error['code'] ?? null) || $error['code'] === ''
            || ! is_string($error['message'] ?? null) || $error['message'] === ''
            || ! is_bool($error['retryable'] ?? null)) {
            throw new InvalidArgumentException('Invalid submission problem.');
        }
        if ($this->submission_status === GenerationSubmissionStatus::Rejected) {
            return;
        }
        // The worker knows whether uncertainty preceded this particular claim.
        // Pending/rejected is allowed only when it can prove no prior acceptance.
        $this->persistMutation([
            'submission_status' => $status,
            'submission_error' => $error,
            'next_attempt_at' => $status === GenerationSubmissionStatus::Rejected ? null : $retryAt,
            'revision' => $this->revision + 1,
        ]);
    }

    public function claimProviderCheck(CarbonInterface $leaseUntil): bool
    {
        $this->assertMutationContext();
        if ($this->submission_status !== GenerationSubmissionStatus::Accepted || $this->stateMachine()->isTerminal() || ! $this->isDue()) {
            return false;
        }
        if ($leaseUntil->lessThanOrEqualTo(now())) {
            throw new InvalidArgumentException('A provider check lease must be in the future.');
        }
        $this->persistMutation(['next_attempt_at' => $leaseUntil]);

        return true;
    }

    public function deferProviderCheck(?CarbonInterface $when): void
    {
        $this->assertMutationContext();
        if ($this->submission_status === GenerationSubmissionStatus::Accepted && ! $this->stateMachine()->isTerminal()) {
            $this->persistMutation(['next_attempt_at' => $when]);
        }
    }

    public function applyObservation(ProviderGeneration $provider, string $fingerprint): ProviderTransitionOutcome
    {
        $this->assertMutationContext();
        $this->assertObservationIdentity($provider, $fingerprint);
        if ($this->submission_status === GenerationSubmissionStatus::Rejected) {
            return ProviderTransitionOutcome::Conflict;
        }
        $machine = $this->stateMachine();
        $outcome = match ($provider->status) {
            ProviderGenerationStatus::Queued => $machine->applyQueued(),
            ProviderGenerationStatus::Processing => $machine->applyProcessing(),
            ProviderGenerationStatus::Succeeded => $machine->applySucceeded(),
            ProviderGenerationStatus::Failed => $machine->applyFailed(),
        };
        if ($outcome === ProviderTransitionOutcome::Stale || $outcome === ProviderTransitionOutcome::Conflict) {
            return $outcome;
        }
        if ($this->provider_started_at !== null
            && (($provider->startedAt !== null && ! $this->provider_started_at->equalTo($provider->startedAt))
                || ($provider->completedAt !== null && $provider->completedAt->lessThan($this->provider_started_at)))) {
            throw new ProviderEvidenceConflict('Provider start time changed.');
        }
        if ($outcome === ProviderTransitionOutcome::Duplicate && $machine->isTerminal()) {
            if (! self::sameDictionary($this->usage, $provider->usage)
                || ! $this->provider_completed_at?->equalTo($provider->completedAt)
                || ($machine->isSucceeded() && ! $this->image_url_expires_at?->equalTo($provider->imageUrlExpiresAt))
                || ($machine->isFailed() && ! self::sameDictionary($this->provider_error, $provider->error))) {
                throw new ProviderEvidenceConflict('Provider terminal evidence changed.');
            }
        }
        $changes = [
            'submission_status' => GenerationSubmissionStatus::Accepted,
            'submission_error' => null,
            'provider_id' => $provider->id,
            'provider_status' => $machine->status(),
            'rates_snapshot' => $provider->rates,
            'provider_created_at' => $provider->createdAt,
            'provider_started_at' => $provider->startedAt ?? $this->provider_started_at,
            'provider_completed_at' => $provider->completedAt,
            'usage' => $provider->usage,
            'image_url' => $provider->imageUrl,
            'image_url_expires_at' => $provider->imageUrlExpiresAt,
            'provider_error' => $provider->error,
        ];
        // JSONB reorders object keys; compare typed evidence, not JSON text.
        foreach ($changes as $field => $value) {
            $current = $this->getAttribute($field);
            $same = match (true) {
                $current instanceof CarbonInterface && $value instanceof CarbonInterface => $current->equalTo($value),
                is_array($current) && is_array($value) => self::sameDictionary($current, $value),
                default => $current === $value,
            };
            if ($same) {
                unset($changes[$field]);
            }
        }
        if ($changes !== []) {
            $changes['revision'] = $this->revision + 1;
        }
        $changes['next_attempt_at'] = $machine->isTerminal() ? null : now()->addSeconds(15);
        $this->persistMutation($changes);

        return $outcome;
    }

    private function assertObservationIdentity(ProviderGeneration $provider, string $fingerprint): void
    {
        self::assertFingerprint($fingerprint);
        if ($this->provider_key_fingerprint === null || ! hash_equals($this->provider_key_fingerprint, $fingerprint)
            || ! self::sameDictionary($provider->metadata, ['generation_id' => $this->id, 'key_fingerprint' => $fingerprint])
            || ($this->provider_id !== null && $this->provider_id !== $provider->id)
            || $provider->prompt !== $this->prompt || $provider->size !== $this->size || $provider->quality !== $this->quality
            || $provider->referenceImages !== $this->reference_images
            || ($this->rates_snapshot !== null && ! self::sameDictionary($this->rates_snapshot, $provider->rates))
            || self::query()->where('provider_id', $provider->id)->whereKeyNot($this->id)->exists()
            || ($this->provider_created_at !== null && ! $this->provider_created_at->equalTo($provider->createdAt))) {
            throw new ProviderEvidenceConflict('Provider identity or immutable request evidence does not agree.');
        }
    }

    /** @param array<string, mixed>|null $left
     * @param  array<string, mixed>|null  $right
     */
    private static function sameDictionary(?array $left, ?array $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }
        ksort($left);
        ksort($right);

        return $left === $right;
    }

    private static function assertFingerprint(string $fingerprint): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1) {
            throw new ProviderEvidenceConflict('Invalid provider key fingerprint.');
        }
    }

    private function isDue(): bool
    {
        return $this->next_attempt_at === null || $this->next_attempt_at->lessThanOrEqualTo(now());
    }

    private function assertMutationContext(): void
    {
        if (! $this->exists || $this->getConnection()->transactionLevel() < 1 || $this->isDirty()) {
            throw new LogicException('A generation mutation requires a clean persisted row locked in an active transaction.');
        }
    }

    /** @param array<string, mixed> $changes */
    private function persistMutation(array $changes): void
    {
        $this->assertMutationContext();
        $this->authorizedMutation = array_keys($changes);
        try {
            foreach ($changes as $field => $value) {
                $this->setAttribute($field, $value);
            }
            if (! $this->saveOrFail()) {
                throw new LogicException('Generation mutation was not persisted.');
            }
        } finally {
            $this->authorizedMutation = [];
        }
    }

    public function delete()
    {
        throw new LogicException('Generation records are retained as evidence; deletion is not supported.');
    }

    /** @param array<string, mixed> $options */
    public function save(array $options = [])
    {
        // Enforce invariants even when Eloquent events are deliberately muted.
        $this->exists ? $this->guardUpdate() : $this->guardCreation();

        return parent::save($options);
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
            'actual_cost_usd' => match (true) {
                $this->provider_status === ProviderGenerationStatus::Succeeded => $this->charge?->customerCostUsd(),
                $this->submission_status === GenerationSubmissionStatus::Rejected,
                $this->provider_status === ProviderGenerationStatus::Failed => '0.000000000',
                default => null,
            },
            'submission_message' => $this->submissionMessage(),
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

    private function submissionMessage(): ?string
    {
        if ($this->submission_status === GenerationSubmissionStatus::Accepted) {
            return ! $this->stateMachine()->isTerminal() && $this->next_attempt_at === null
                ? 'Automatic status checks are paused and need attention. The result has not been confirmed.'
                : null;
        }
        if ($this->submission_error === null) {
            return null;
        }

        if ($this->submission_status === GenerationSubmissionStatus::Uncertain) {
            return ($this->submission_error['retryable'] ?? true)
                ? 'Awaiting provider confirmation. Please do not submit the same request again.'
                : 'Automatic retries are paused and need attention. The outcome is unconfirmed; do not start a duplicate request.';
        }
        if ($this->submission_status !== GenerationSubmissionStatus::Rejected && ($this->submission_error['retryable'] ?? true) === false) {
            return 'Submission is paused and needs attention. No generation result has been confirmed.';
        }

        return match ($this->submission_error['code'] ?? null) {
            'content_policy_violation' => 'The provider could not accept this prompt. You were not charged.',
            'validation_failed' => 'The provider could not accept this request. You were not charged.',
            'daily_limit_exceeded' => 'The provider daily limit has been reached. Please try again later.',
            'rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded' => 'The provider is busy. Your request is waiting to retry.',
            default => 'Submission is temporarily unavailable. Please try again later.',
        };
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
            || str_contains($prompt, "\0")
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
        if ($this->authorizedMutation !== [] && $this->getConnection()->transactionLevel() < 1) {
            throw new LogicException('A generation mutation requires an active transaction.');
        }
        foreach (array_keys($this->getDirty()) as $attribute) {
            if ($attribute !== 'updated_at' && ! in_array($attribute, $this->authorizedMutation, true)) {
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

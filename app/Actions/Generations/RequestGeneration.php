<?php

namespace App\Actions\Generations;

use App\Data\GenerationQuote;
use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Exceptions\InsufficientCredit;
use App\Jobs\SubmitGenerationJob;
use App\Models\CreditLedgerEntry;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

/**
 * Accept one owned generation request.
 *
 * The browser never supplies prices, quote snapshots or ownership. The server
 * re-quotes the validated options, freezes the immutable request and only then
 * hands the durable row to submission work. Repeated submissions of the same
 * options for the same user are served from the existing row, even when the
 * current prices have moved.
 *
 * A new request must be covered by available credit at its quoted maximum,
 * which is held in the same owner-locked transaction (D-B02). An unaffordable
 * request creates, holds and queues nothing.
 */
final class RequestGeneration
{
    private const UNAVAILABLE_MESSAGE = 'Image generation is currently unavailable. Nothing was created or charged.';

    private const CONFLICT_MESSAGE = 'This submission was already used with different options. Change an option to start a new request.';

    public function __construct(
        private readonly QuoteGeneration $quoteGeneration,
    ) {}

    /**
     * @param  array<string, mixed>  $options  Validated options: prompt, size, quality and optional reference_images
     *
     * @throws ValidationException when the options contradict an existing submission token or credit is insufficient
     * @throws ServiceUnavailableHttpException when generation is not configured
     */
    public function execute(User $user, array $options, string $submissionToken): Generation
    {
        [$prompt, $size, $quality, $referenceImages] = $this->validateOptions($options);
        $token = $this->validateSubmissionToken($submissionToken);

        if (! $user->exists || $user->getKey() === null) {
            throw new InvalidArgumentException('A generation request requires a persisted owner.');
        }

        // A retried browser submission must be answered from the durable row
        // before quoting or checking configuration: the frozen request is the
        // authority, not today's price or today's key.
        $existing = $this->findOwned($user, $token);

        if ($existing !== null) {
            return $this->matchOrConflict($existing, $prompt, $size, $quality, $referenceImages);
        }

        $fingerprint = $this->configuredKeyFingerprint();

        // Quoting may contact the pricing endpoint, so it stays outside the
        // transaction that holds the owner lock.
        $quote = $this->quoteGeneration->execute($prompt, $size, $quality, $referenceImages);

        try {
            [$generation, $created] = $this->persist($user, $token, $prompt, $size, $quality, $referenceImages, $quote, $fingerprint);
        } catch (InsufficientCredit $exception) {
            throw ValidationException::withMessages([
                'credits' => sprintf(
                    'Not enough credit: this request can cost up to %s and your available balance is %s. Buy credits to continue. Nothing was created or charged.',
                    CreditLedgerEntry::formatDollars($exception->requiredNanodollars),
                    CreditLedgerEntry::formatDollars($exception->availableNanodollars),
                ),
            ]);
        }

        if ($created) {
            // The row is already durable. Queue dispatch is only an
            // accelerator; scheduled recovery picks up a lost job.
            $this->dispatchSubmission($generation);
        }

        return $generation;
    }

    /**
     * @param  list<string>  $referenceImages
     * @return array{0: Generation, 1: bool}
     *
     * @throws InsufficientCredit
     */
    private function persist(User $user, string $token, string $prompt, GenerationSize $size, GenerationQuality $quality, array $referenceImages, GenerationQuote $quote, string $fingerprint): array
    {
        return DB::transaction(function () use ($user, $token, $prompt, $size, $quality, $referenceImages, $quote, $fingerprint): array {
            // Serialise request creation per owner. Two tabs submitting at the
            // same time cannot both insert the same submission token or both
            // spend the same credit.
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            $existing = $this->findOwned($user, $token);

            if ($existing !== null) {
                return [$this->matchOrConflict($existing, $prompt, $size, $quality, $referenceImages), false];
            }

            $generation = Generation::query()->create([
                'user_id' => $user->getKey(),
                'submission_token' => $token,
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_images' => $referenceImages,
                'quote_snapshot' => $quote->snapshot(),
                'estimated_cost_nanodollars' => $quote->estimatedCostNanodollars,
                'maximum_cost_nanodollars' => $quote->maximumCostNanodollars,
            ]);

            // Freeze the key identity before the row can be handed to the
            // worker. The submission request itself stays immutable.
            $generation->prepareSubmission($fingerprint);

            // Throws before commit when credit is short, rolling back the row.
            CreditLedgerEntry::holdFor($generation);

            return [$generation, true];
        });
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: string, 1: GenerationSize, 2: GenerationQuality, 3: list<string>}
     */
    private function validateOptions(array $options): array
    {
        $errors = [];

        $prompt = $options['prompt'] ?? null;

        if (! is_string($prompt)
            || $prompt === ''
            || ! mb_check_encoding($prompt, 'UTF-8')
            || str_contains($prompt, "\0")
            || preg_match('/\S/u', $prompt) !== 1) {
            $errors['prompt'] = 'The prompt must contain at least one visible character.';
        } elseif (mb_strlen($prompt, 'UTF-8') > 4000) {
            $errors['prompt'] = 'The prompt must be at most 4000 characters.';
        }

        $size = is_string($options['size'] ?? null)
            ? GenerationSize::tryFrom($options['size'])
            : null;

        if ($size === null) {
            $errors['size'] = 'Choose one of the supported output sizes.';
        }

        $quality = is_string($options['quality'] ?? null)
            ? GenerationQuality::tryFrom($options['quality'])
            : null;

        if ($quality === null) {
            $errors['quality'] = 'Choose one of the supported quality levels.';
        }

        $referenceImages = $options['reference_images'] ?? [];

        if (! is_array($referenceImages)
            || ! array_is_list($referenceImages)
            || count($referenceImages) > 4) {
            $errors['reference_images'] = 'Reference images must be a list of at most four URLs.';
            $referenceImages = [];
        } else {
            foreach ($referenceImages as $referenceImage) {
                if (! is_string($referenceImage) || ! Str::isUrl($referenceImage, ['http', 'https'])) {
                    $errors['reference_images'] = 'Reference images must be full http(s) URLs.';

                    break;
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        /** @var string $prompt */
        /** @var GenerationSize $size */
        /** @var GenerationQuality $quality */
        /** @var list<string> $referenceImages */
        return [$prompt, $size, $quality, $referenceImages];
    }

    private function validateSubmissionToken(string $submissionToken): string
    {
        if (! Str::isUuid($submissionToken)) {
            throw ValidationException::withMessages([
                'submission_token' => 'The submission token must be a UUID.',
            ]);
        }

        return $submissionToken;
    }

    /**
     * The configured key's identity is a one-way fingerprint. The key itself
     * never leaves configuration and is never stored with the request.
     */
    private function configuredKeyFingerprint(): string
    {
        $apiKey = config('services.pixelforge.api_key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new ServiceUnavailableHttpException(null, self::UNAVAILABLE_MESSAGE);
        }

        return hash('sha256', $apiKey);
    }

    private function findOwned(User $user, string $token): ?Generation
    {
        return Generation::query()
            ->forUser($user)
            ->where('submission_token', $token)
            ->first();
    }

    /**
     * Same token and exactly the same request returns the original row. The
     * same token with different options is a client conflict, not a new
     * generation and not a silent edit.
     *
     * @param  list<string>  $referenceImages
     */
    private function matchOrConflict(
        Generation $existing,
        string $prompt,
        GenerationSize $size,
        GenerationQuality $quality,
        array $referenceImages,
    ): Generation {
        if ($existing->prompt !== $prompt
            || $existing->size !== $size
            || $existing->quality !== $quality
            || $existing->reference_images !== $referenceImages) {
            throw ValidationException::withMessages([
                'submission_token' => self::CONFLICT_MESSAGE,
            ]);
        }

        return $existing;
    }

    private function dispatchSubmission(Generation $generation): void
    {
        try {
            SubmitGenerationJob::dispatch($generation->id);
        } catch (Throwable $exception) {
            // The committed request stays valid; the scheduled recovery sweep
            // finds submissions whose dispatch never landed.
            report($exception);
        }
    }
}

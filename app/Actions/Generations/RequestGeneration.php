<?php

namespace App\Actions\Generations;

use App\Data\GenerationQuote;
use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Exceptions\InsufficientCredit;
use App\Http\Integrations\Pixelforge\PixelforgeConnector;
use App\Jobs\SubmitGenerationJob;
use App\Models\CreditLedgerEntry;
use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationPricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

final class RequestGeneration
{
    private const UNAVAILABLE_MESSAGE = 'Image generation is currently unavailable. Nothing was created or charged.';

    private const CONFLICT_MESSAGE = 'This submission was already used with different options. Change an option to start a new request.';

    public function __construct(
        private readonly QuoteGeneration $quoteGeneration,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws ValidationException
     * @throws ServiceUnavailableHttpException
     */
    public function execute(User $user, array $options, string $submissionToken): Generation
    {
        [$prompt, $size, $quality, $referenceImages] = $this->validateOptions($options);
        $token = $this->validateSubmissionToken($submissionToken);

        if (! $user->exists || $user->getKey() === null) {
            throw new InvalidArgumentException('A generation request requires a persisted owner.');
        }

        $existing = $this->findOwned($user, $token);

        if ($existing !== null) {
            return $this->matchOrConflict($existing, $prompt, $size, $quality, $referenceImages);
        }

        $fingerprint = $this->configuredKeyFingerprint();

        $quote = $this->quoteGeneration->execute($prompt, $size, $quality, $referenceImages);

        try {
            [$generation, $created] = $this->persist($user, $token, $prompt, $size, $quality, $referenceImages, $quote, $fingerprint);
        } catch (InsufficientCredit $exception) {
            throw ValidationException::withMessages([
                'credits' => sprintf(
                    'Not enough credit: this request can cost up to %s and your available balance is %s. Buy credits to continue. Nothing was created or charged.',
                    GenerationPricing::formatDollars($exception->requiredNanodollars),
                    GenerationPricing::formatDollars($exception->availableNanodollars),
                ),
            ]);
        }

        if ($created) {
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

            $generation->prepareSubmission($fingerprint);

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

    private function configuredKeyFingerprint(): string
    {
        $apiKey = PixelforgeConnector::configuredKey()
            ?? throw new ServiceUnavailableHttpException(null, self::UNAVAILABLE_MESSAGE);

        return hash('sha256', $apiKey);
    }

    private function findOwned(User $user, string $token): ?Generation
    {
        return Generation::query()
            ->forUser($user)
            ->where('submission_token', $token)
            ->first();
    }

    /** @param  list<string>  $referenceImages */
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
            report($exception);
        }
    }
}

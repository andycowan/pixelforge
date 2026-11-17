<?php

namespace App\Actions\Generations;

use App\Jobs\ProcessWebhookEventJob;
use App\Jobs\PublishOutboxJob;
use App\Jobs\ReconcileGenerationJob;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\OutboxMessage;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Throwable;

final class RecoverGenerationWork
{
    /** Redispatch at most twenty durable items, fairly across the three work types. */
    public function execute(): int
    {
        $inbox = WebhookEvent::query()->whereNull('processed_at')->orderBy('received_at')->limit(20)->pluck('id')->all();
        $submissions = Generation::query()->whereNull('provider_id')->whereIn('submission_status', ['pending', 'uncertain'])
            ->where(function (Builder $query): void {
                $query->where('submission_error->retryable', true)->orWhereNull('submission_error');
            })->where(function (Builder $query): void {
                $query->where('next_attempt_at', '<=', now())->orWhere(function (Builder $query): void {
                    $query->where('submission_attempts', 0)->whereNull('next_attempt_at');
                });
            })->orderBy('created_at')->limit(20)->pluck('id')->all();
        $accepted = Generation::query()->where('submission_status', 'accepted')->whereIn('provider_status', ['queued', 'processing'])
            ->whereNotNull('provider_id')->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit(20)->pluck('id')->all();

        $count = 0;
        $considered = 0;
        for ($i = 0; $i < 20 && $considered < 20; $i++) {
            foreach ([[ProcessWebhookEventJob::class, $inbox], [SubmitGenerationJob::class, $submissions], [ReconcileGenerationJob::class, $accepted]] as [$job, $ids]) {
                if (! isset($ids[$i]) || $considered >= 20) {
                    continue;
                }
                $considered++;
                try {
                    $job::dispatch($ids[$i]);
                    $count++;
                } catch (Throwable) {
                    report(new RuntimeException('Durable generation work dispatch failed for '.$ids[$i]));
                }
            }
        }

        if (OutboxMessage::query()->whereNull('published_at')->exists()) {
            rescue(fn () => PublishOutboxJob::dispatch());
        }

        return $count;
    }
}

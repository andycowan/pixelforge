<?php

namespace App\Jobs\Middleware;

use App\Jobs\ReconcileGenerationJob;
use App\Jobs\SubmitGenerationJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/** Shared across both authenticated HTTP operations and all configured keys. */
final class ProviderRequestBudget
{
    /** @param SubmitGenerationJob|ReconcileGenerationJob $job */
    public function handle(object $job, callable $next): mixed
    {
        $lock = Cache::lock('pixelforge:provider-budget-lock', 5);
        if (! $lock->get()) {
            $job->release(1);

            return null;
        }
        try {
            $delay = RateLimiter::tooManyAttempts('pixelforge:provider-budget', 45)
                ? max(1, RateLimiter::availableIn('pixelforge:provider-budget')) : 0;
            if ($delay === 0) {
                RateLimiter::hit('pixelforge:provider-budget', 60);
            }
        } finally {
            $lock->release();
        }

        // Releasing queue work must not consume a generation's network-attempt budget.
        if ($delay > 0) {
            $job->release($delay);

            return null;
        }

        return $next($job);
    }
}

<?php

namespace App\Jobs;

use App\Models\OutboxMessage;
use App\Services\Mercure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Publish committed generation-change intent as private invalidation updates.
 *
 * Payloads carry only identity and revision: browsers refetch the owner-scoped
 * page props, so duplicate or out-of-order delivery is harmless. Failures stay
 * unpublished for the scheduled recovery sweep.
 */
final class PublishOutboxJob implements ShouldQueue
{
    use Queueable;

    private const BATCH = 50;

    public int $tries = 1;

    public int $timeout = 30;

    public function handle(Mercure $mercure): void
    {
        foreach (OutboxMessage::nextUnpublished(self::BATCH) as $message) {
            if ($message->user_id === null) {
                $message->markPublished(); // No subscriber can exist for an ownerless record.

                continue;
            }
            try {
                $mercure->publish($mercure->topicFor($message->user_id), $message->payload);
            } catch (Throwable $exception) {
                $message->recordPublicationFailure();
                // The hub is likely unavailable; leave the rest for the recovery sweep.
                report(new RuntimeException('Outbox publication failed for '.$message->id, previous: $exception));

                return;
            }
            $message->markPublished();
        }
    }
}

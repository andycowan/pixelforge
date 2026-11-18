<?php

namespace App\Jobs;

use App\Models\OutboxMessage;
use App\Services\Mercure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

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
                $message->markPublished();

                continue;
            }
            try {
                $mercure->publish($mercure->topicFor($message->user_id), $message->payload);
            } catch (Throwable $exception) {
                $message->recordPublicationFailure();
                report(new RuntimeException('Outbox publication failed for '.$message->id, previous: $exception));

                return;
            }
            $message->markPublished();
        }
    }
}

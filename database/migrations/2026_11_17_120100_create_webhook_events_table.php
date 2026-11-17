<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->string('id', 100)->primary();
            $table->jsonb('payload');
            $table->string('provider_key_fingerprint', 64);
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->string('outcome', 20)->nullable();
            $table->jsonb('error')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE webhook_events ADD CONSTRAINT webhook_events_evidence_check CHECK (id ~ '^evt_[A-Za-z0-9]+$' AND provider_key_fingerprint ~ '^[a-f0-9]{64}$' AND jsonb_typeof(payload) = 'object')");
        DB::statement("ALTER TABLE webhook_events ADD CONSTRAINT webhook_events_outcome_check CHECK ((processed_at IS NULL AND outcome IS NULL) OR (processed_at IS NOT NULL AND outcome IS NOT NULL AND outcome IN ('advance','duplicate','stale','conflict','ignored','unmatched','invalid')))");
        DB::statement('CREATE INDEX webhook_events_unprocessed_index ON webhook_events (received_at) WHERE processed_at IS NULL');
        DB::statement('CREATE INDEX generations_submission_recovery_index ON generations (next_attempt_at, created_at) WHERE provider_id IS NULL');
        DB::statement("CREATE INDEX generations_provider_recovery_index ON generations (next_attempt_at) WHERE submission_status = 'accepted' AND provider_status IN ('queued', 'processing')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS generations_provider_recovery_index');
        DB::statement('DROP INDEX IF EXISTS generations_submission_recovery_index');
        Schema::dropIfExists('webhook_events');
    }
};

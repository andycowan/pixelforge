<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('submission_token');

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->jsonb('reference_images')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('quote_snapshot');
            $table->bigInteger('estimated_cost_nanodollars');
            $table->bigInteger('maximum_cost_nanodollars');

            $table->string('submission_status')->default('pending');
            $table->integer('submission_attempts')->default(0);
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->jsonb('submission_error')->nullable();

            $table->string('provider_key_fingerprint', 64)->nullable();
            $table->string('provider_id')->nullable()->unique();
            $table->string('provider_status')->nullable();
            $table->jsonb('rates_snapshot')->nullable();
            $table->jsonb('usage')->nullable();

            $table->text('image_url')->nullable();
            $table->timestampTz('image_url_expires_at')->nullable();
            $table->jsonb('provider_error')->nullable();
            $table->timestampTz('provider_created_at')->nullable();
            $table->timestampTz('provider_started_at')->nullable();
            $table->timestampTz('provider_completed_at')->nullable();

            $table->bigInteger('revision')->default(0);
            $table->timestampsTz();

            $table->unique(['user_id', 'submission_token']);
            $table->index(['user_id', 'created_at']);
        });

        // Freeze the supported values in the migration. Historical migrations
        // must not follow later changes to the application enum cases.
        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_size_check CHECK (size IN ('1024x1024', '1536x1024', '1024x1536'))");
        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_quality_check CHECK (quality IN ('low', 'medium', 'high'))");
        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_submission_status_check CHECK (submission_status IN ('pending', 'uncertain', 'accepted', 'rejected'))");
        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_provider_status_check CHECK (provider_status IS NULL OR provider_status IN ('queued', 'processing', 'succeeded', 'failed'))");

        DB::statement('ALTER TABLE generations ADD CONSTRAINT generations_prompt_length_check CHECK (char_length(prompt) BETWEEN 1 AND 4000)');

        DB::statement('ALTER TABLE generations ADD CONSTRAINT generations_estimated_cost_nonnegative_check CHECK (estimated_cost_nanodollars >= 0)');
        DB::statement('ALTER TABLE generations ADD CONSTRAINT generations_maximum_cost_nonnegative_check CHECK (maximum_cost_nanodollars >= 0)');
        DB::statement('ALTER TABLE generations ADD CONSTRAINT generations_maximum_cost_not_below_estimated_check CHECK (maximum_cost_nanodollars >= estimated_cost_nanodollars)');
        DB::statement('ALTER TABLE generations ADD CONSTRAINT generations_submission_attempts_nonnegative_check CHECK (submission_attempts >= 0)');
        DB::statement('ALTER TABLE generations ADD CONSTRAINT generations_revision_nonnegative_check CHECK (revision >= 0)');

        // CASE keeps jsonb_array_length from evaluating a non-array value.
        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_reference_images_shape_check CHECK (CASE WHEN jsonb_typeof(reference_images) = 'array' THEN jsonb_array_length(reference_images) <= 4 ELSE FALSE END)");
        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_quote_snapshot_object_check CHECK (jsonb_typeof(quote_snapshot) = 'object' AND quote_snapshot <> '{}'::jsonb)");

        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_submission_error_object_check CHECK (submission_error IS NULL OR jsonb_typeof(submission_error) = 'object')");
        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_rates_snapshot_object_check CHECK (rates_snapshot IS NULL OR jsonb_typeof(rates_snapshot) = 'object')");
        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_usage_object_check CHECK (usage IS NULL OR jsonb_typeof(usage) = 'object')");
        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_provider_error_object_check CHECK (provider_error IS NULL OR jsonb_typeof(provider_error) = 'object')");

        // Accepted work must have a known provider identity. This does not
        // validate provider payloads or substitute for the future apply action.
        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_accepted_requires_provider_identity_check CHECK (submission_status <> 'accepted' OR (provider_id IS NOT NULL AND provider_status IS NOT NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};

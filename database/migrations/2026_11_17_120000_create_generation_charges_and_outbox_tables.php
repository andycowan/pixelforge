<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->timestampTz('provider_created_at', 6)->nullable()->change();
            $table->timestampTz('provider_started_at', 6)->nullable()->change();
            $table->timestampTz('provider_completed_at', 6)->nullable()->change();
            $table->timestampTz('image_url_expires_at', 6)->nullable()->change();
        });

        Schema::create('generation_charges', function (Blueprint $table): void {
            $table->ulid('generation_id')->primary();
            $table->foreign('generation_id')->references('id')->on('generations')->restrictOnDelete();
            $table->bigInteger('provider_cost_nanodollars');
            $table->bigInteger('customer_cost_nanodollars');
            $table->smallInteger('markup_numerator');
            $table->smallInteger('markup_denominator');
            $table->string('rounding');
            $table->smallInteger('scale');
            $table->timestampTz('provider_completed_at', 6);
            $table->timestampTz('created_at', 6);
            $table->index('provider_completed_at');
        });
        DB::statement('ALTER TABLE generation_charges ADD CONSTRAINT generation_charges_amounts_check CHECK (provider_cost_nanodollars >= 0 AND customer_cost_nanodollars >= 0)');
        DB::statement("ALTER TABLE generation_charges ADD CONSTRAINT generation_charges_policy_check CHECK (markup_numerator = 5 AND markup_denominator = 4 AND rounding = 'half_up' AND scale = 9)");
        DB::statement('ALTER TABLE generation_charges ADD CONSTRAINT generation_charges_customer_cost_check CHECK (customer_cost_nanodollars = floor((provider_cost_nanodollars::numeric * 5 + 2) / 4))');

        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('generation_id')->constrained('generations')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->bigInteger('revision');
            $table->string('type');
            $table->jsonb('payload');
            $table->integer('publication_attempts')->default(0);
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->unique(['generation_id', 'revision']);
            $table->index(['published_at', 'created_at']);
        });
        DB::statement('ALTER TABLE outbox_messages ADD CONSTRAINT outbox_messages_counters_check CHECK (revision >= 0 AND publication_attempts >= 0)');
        DB::statement("ALTER TABLE outbox_messages ADD CONSTRAINT outbox_messages_payload_check CHECK (jsonb_typeof(payload) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
        Schema::dropIfExists('generation_charges');
        // Keep widened timestamps: rollback must not round retained provider evidence.
    }
};

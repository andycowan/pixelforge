<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only customer credit ledger.
 *
 * Additive only: existing users start with no entries (a zero balance) and
 * already-settled generations get no retroactive hold or debit. Balances are
 * always derived from these rows, never stored as an editable number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_ledger_entries', function (Blueprint $table): void {
            $table->id();
            // Matches generations: deliberate operator deletion keeps evidence and nulls ownership.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->bigInteger('amount_nanodollars');
            $table->foreignUlid('generation_id')->nullable()->constrained('generations')->restrictOnDelete();
            $table->uuid('idempotency_key')->nullable();
            $table->timestampTz('created_at', 6);

            $table->unique(['generation_id', 'type']);
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'id']);
        });

        DB::statement("ALTER TABLE credit_ledger_entries ADD CONSTRAINT credit_ledger_entries_type_check CHECK (type IN ('purchase', 'hold', 'release', 'debit'))");
        DB::statement('ALTER TABLE credit_ledger_entries ADD CONSTRAINT credit_ledger_entries_whole_cents_check CHECK (amount_nanodollars >= 0 AND amount_nanodollars % 10000000 = 0)');
        DB::statement("ALTER TABLE credit_ledger_entries ADD CONSTRAINT credit_ledger_entries_purchase_check CHECK (
            (type = 'purchase' AND generation_id IS NULL AND idempotency_key IS NOT NULL AND amount_nanodollars > 0)
            OR (type <> 'purchase' AND generation_id IS NOT NULL AND idempotency_key IS NULL)
        )");

        // Entries are evidence: no edits or deletes. The only permitted update is
        // the ON DELETE SET NULL of ownership during deliberate operator deletion.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION credit_ledger_entries_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND NEW.user_id IS NULL
                    AND (to_jsonb(NEW) - 'user_id') = (to_jsonb(OLD) - 'user_id') THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'credit ledger entries are append-only';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER credit_ledger_entries_append_only
                BEFORE UPDATE OR DELETE ON credit_ledger_entries
                FOR EACH ROW EXECUTE FUNCTION credit_ledger_entries_append_only();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger_entries');
        DB::unprepared('DROP FUNCTION IF EXISTS credit_ledger_entries_append_only()');
    }
};

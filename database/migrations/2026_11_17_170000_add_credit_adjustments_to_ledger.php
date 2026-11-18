<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            Schema::table('credit_ledger_entries', function (Blueprint $table): void {
                $table->text('reason')->nullable();
            });
            DB::statement('ALTER TABLE credit_ledger_entries DROP CONSTRAINT credit_ledger_entries_type_check');
            DB::statement('ALTER TABLE credit_ledger_entries DROP CONSTRAINT credit_ledger_entries_purchase_check');
            DB::statement("ALTER TABLE credit_ledger_entries ADD CONSTRAINT credit_ledger_entries_type_check CHECK (type IN ('purchase', 'hold', 'release', 'debit', 'credit_adjustment', 'debit_adjustment'))");
            DB::statement("ALTER TABLE credit_ledger_entries ADD CONSTRAINT credit_ledger_entries_fields_check CHECK (
                (type = 'purchase' AND generation_id IS NULL AND idempotency_key IS NOT NULL AND amount_nanodollars > 0 AND reason IS NULL)
                OR (type IN ('hold', 'release', 'debit') AND generation_id IS NOT NULL AND idempotency_key IS NULL AND reason IS NULL)
                OR (type IN ('credit_adjustment', 'debit_adjustment') AND generation_id IS NULL AND idempotency_key IS NULL
                    -- Explicit IS NOT NULL: a NULL comparison would make the CHECK pass.
                    AND amount_nanodollars > 0 AND reason IS NOT NULL AND btrim(reason) <> '' AND char_length(reason) <= 500)
            )");
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            if (DB::table('credit_ledger_entries')->whereIn('type', ['credit_adjustment', 'debit_adjustment'])->exists()) {
                throw new RuntimeException('Credit adjustments exist; this migration cannot be rolled back without discarding evidence.');
            }
            DB::statement('ALTER TABLE credit_ledger_entries DROP CONSTRAINT credit_ledger_entries_fields_check');
            DB::statement('ALTER TABLE credit_ledger_entries DROP CONSTRAINT credit_ledger_entries_type_check');
            DB::statement("ALTER TABLE credit_ledger_entries ADD CONSTRAINT credit_ledger_entries_type_check CHECK (type IN ('purchase', 'hold', 'release', 'debit'))");
            DB::statement("ALTER TABLE credit_ledger_entries ADD CONSTRAINT credit_ledger_entries_purchase_check CHECK (
                (type = 'purchase' AND generation_id IS NULL AND idempotency_key IS NOT NULL AND amount_nanodollars > 0)
                OR (type <> 'purchase' AND generation_id IS NOT NULL AND idempotency_key IS NULL)
            )");
            Schema::table('credit_ledger_entries', function (Blueprint $table): void {
                $table->dropColumn('reason');
            });
        });
    }
};

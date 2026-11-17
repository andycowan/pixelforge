<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Customer prices round up once to whole cents (D-054).
 *
 * Existing records are recomputed deterministically from their stored provider
 * cost or quote snapshot, so both directions are exact and repeatable. Provider
 * costs are never changed. On an empty database this only replaces constraints.
 */
return new class extends Migration
{
    private const TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement('ALTER TABLE generation_charges DROP CONSTRAINT generation_charges_policy_check');
            DB::statement('ALTER TABLE generation_charges DROP CONSTRAINT generation_charges_customer_cost_check');
            DB::statement("UPDATE generation_charges SET rounding = 'ceiling', scale = 2, customer_cost_nanodollars = ".self::ceilingCents('provider_cost_nanodollars'));
            DB::statement("ALTER TABLE generation_charges ADD CONSTRAINT generation_charges_policy_check CHECK (markup_numerator = 5 AND markup_denominator = 4 AND rounding = 'ceiling' AND scale = 2)");
            DB::statement('ALTER TABLE generation_charges ADD CONSTRAINT generation_charges_customer_cost_check CHECK (customer_cost_nanodollars = '.self::ceilingCents('provider_cost_nanodollars').')');

            DB::statement('UPDATE generations SET estimated_cost_nanodollars = '.self::ceilingCents(self::providerCost('tokens'))
                .', maximum_cost_nanodollars = '.self::ceilingCents(self::providerCost('bounds'))
                .", quote_snapshot = jsonb_set(jsonb_set(quote_snapshot, '{policy,rounding}', '\"ceiling\"'), '{policy,precision}', '2')");
            DB::statement('ALTER TABLE generations ADD CONSTRAINT generations_whole_cent_prices_check CHECK (estimated_cost_nanodollars % 10000000 = 0 AND maximum_cost_nanodollars % 10000000 = 0)');
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('ALTER TABLE generations DROP CONSTRAINT generations_whole_cent_prices_check');
            DB::statement('UPDATE generations SET estimated_cost_nanodollars = '.self::halfUp(self::providerCost('tokens'))
                .', maximum_cost_nanodollars = '.self::halfUp(self::providerCost('bounds'))
                .", quote_snapshot = jsonb_set(jsonb_set(quote_snapshot, '{policy,rounding}', '\"half_up\"'), '{policy,precision}', '9')");

            DB::statement('ALTER TABLE generation_charges DROP CONSTRAINT generation_charges_policy_check');
            DB::statement('ALTER TABLE generation_charges DROP CONSTRAINT generation_charges_customer_cost_check');
            DB::statement("UPDATE generation_charges SET rounding = 'half_up', scale = 9, customer_cost_nanodollars = ".self::halfUp('provider_cost_nanodollars'));
            DB::statement("ALTER TABLE generation_charges ADD CONSTRAINT generation_charges_policy_check CHECK (markup_numerator = 5 AND markup_denominator = 4 AND rounding = 'half_up' AND scale = 9)");
            DB::statement('ALTER TABLE generation_charges ADD CONSTRAINT generation_charges_customer_cost_check CHECK (customer_cost_nanodollars = floor((provider_cost_nanodollars::numeric * 5 + 2) / 4))');
        });
    }

    /** Exact provider nanodollars from a snapshot token map and its per-million USD rates; NULL (and so a failed update) if any input is missing. */
    private static function providerCost(string $tokens): string
    {
        $terms = array_map(
            fn (string $type): string => "(quote_snapshot->'{$tokens}'->>'{$type}')::numeric * (quote_snapshot->'rates'->>'{$type}')::numeric",
            self::TYPES,
        );

        return '(('.implode(' + ', $terms).') * 1000)';
    }

    private static function ceilingCents(string $providerCost): string
    {
        return "(ceil({$providerCost}::numeric * 5 / 40000000) * 10000000)::bigint";
    }

    private static function halfUp(string $providerCost): string
    {
        return "floor({$providerCost}::numeric * 5 / 4 + 0.5)::bigint";
    }
};

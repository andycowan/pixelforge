<?php

namespace App\Actions\Admin;

use App\Data\PixelforgePricing;
use App\Exceptions\ProviderPayloadException;
use App\Http\Integrations\Pixelforge\Data\ProviderUsage;
use App\Http\Integrations\Pixelforge\PixelforgeConnector;
use App\Http\Integrations\Pixelforge\Requests\GetUsage;
use App\Models\CreditLedgerEntry;
use App\Models\GenerationCharge;
use App\Models\User;
use App\Services\GenerationPricing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;
use Saloon\Exceptions\Request\FatalRequestException;

final class BuildOperatorReport
{
    private const TOKENS = PixelforgePricing::TOKEN_TYPES;

    /** @return array<string, mixed> */
    public function execute(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $key = PixelforgeConnector::configuredKey();
        $fingerprint = $key === null ? null : hash('sha256', $key);

        $rows = $fingerprint === null ? [] : GenerationCharge::dailyTotalsByUser($start, $end, $fingerprint);
        [$usage, $unavailable] = $this->providerUsage($key, $start, $end);
        $owners = User::query()->whereIn('id', array_filter(array_column($rows, 'user_id')))->get(['id', 'name', 'email'])->keyBy('id');

        $days = [];
        $people = [];
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $date = $day->toDateString();
            $dayRows = array_values(array_filter($rows, fn (array $row): bool => $row['date'] === $date));
            foreach ($dayRows as $row) {
                $id = $row['user_id'] ?? 'deleted';
                $people[$id] = ['user_id' => $row['user_id'], 'figures' => self::sum([$people[$id]['figures'] ?? [], $row])];
            }
            $local = self::sum($dayRows);
            $days[] = [
                'date' => $date,
                ...self::money($local),
                'users' => array_map(fn (array $row): array => self::userFigures($row['user_id'], $row, $owners), $dayRows),
                'pixelforge' => $usage === null ? null : self::reconcile($local, $usage->days[$date]),
            ];
        }

        usort($people, fn (array $a, array $b): int => $b['figures']['customer'] <=> $a['figures']['customer']);
        $totals = self::sum($rows);
        $bank = CreditLedgerEntry::bank();

        return [
            'range' => ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()],
            'days' => $days,
            'users' => array_map(fn (array $person): array => self::userFigures($person['user_id'], $person['figures'], $owners), $people),
            'totals' => self::money($totals),
            'reconciliation' => [
                'available' => $usage !== null,
                'reason' => $unavailable,
                'mismatched_days' => count(array_filter($days, fn (array $day): bool => ($day['pixelforge']['status'] ?? null) === 'mismatch')),
                'pixelforge_total' => $usage === null ? null : self::reconcile($totals, $usage->total),
            ],
            'other_key_charges' => GenerationCharge::otherKeyCountBetween($start, $end, $fingerprint),
            'bank' => ['unspent_usd' => GenerationPricing::formatUsd($bank['available'] + $bank['held']), 'held_usd' => GenerationPricing::formatUsd($bank['held'])],
            'key_configured' => $fingerprint !== null,
        ];
    }

    /** @return array{0: ?ProviderUsage, 1: ?string} */
    private function providerUsage(?string $key, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($key === null) {
            return [null, 'No Pixelforge API key is configured, so there is nothing to reconcile.'];
        }
        try {
            $response = (new PixelforgeConnector($key))->send(new GetUsage($start, $end));
            if (! $response->successful()) {
                report(new RuntimeException("Pixelforge usage returned HTTP {$response->status()}."));

                return [null, "Pixelforge usage could not be fetched (HTTP {$response->status()})."];
            }

            return [$response->dtoOrFail(), null];
        } catch (FatalRequestException) {
            return [null, 'Pixelforge usage could not be reached. Try again shortly.'];
        } catch (ProviderPayloadException $exception) {
            report($exception);

            return [null, 'Pixelforge returned usage in an unexpected format.'];
        }
    }

    /**
     * @param  array<string, int>  $local
     * @param  array<string, int>  $provider
     * @return array<string, mixed>
     */
    private static function reconcile(array $local, array $provider): array
    {
        $checks = ['generations' => [$local['generations'], $provider['generations']], 'cost' => [$local['provider'], $provider['cost_nanodollars']]];
        foreach (self::TOKENS as $type) {
            $checks[$type] = [$local[$type], $provider[$type]];
        }
        $mismatches = array_keys(array_filter($checks, fn (array $pair): bool => $pair[0] !== $pair[1]));

        return [
            'status' => $mismatches === [] ? 'match' : 'mismatch',
            'mismatches' => $mismatches,
            'generations' => $provider['generations'],
            'cost_usd' => GenerationPricing::formatUsd($provider['cost_nanodollars']),
            'cost_difference_usd' => GenerationPricing::formatUsd($local['provider'] - $provider['cost_nanodollars']),
            'generations_difference' => $local['generations'] - $provider['generations'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private static function sum(array $rows): array
    {
        $sum = array_fill_keys(['generations', 'customer', 'provider', ...self::TOKENS], 0);
        foreach ($rows as $row) {
            foreach ($sum as $field => $value) {
                $sum[$field] = $value + (int) ($row[$field] ?? 0);
            }
        }

        return $sum;
    }

    /**
     * @param  array<string, mixed>  $figures
     * @return array<string, mixed>
     */
    private static function money(array $figures): array
    {
        $figures = self::sum([$figures]);

        return [
            'generations' => $figures['generations'],
            'credit_spend_usd' => GenerationPricing::formatUsd($figures['customer']),
            'provider_cost_usd' => GenerationPricing::formatUsd($figures['provider']),
            'profit_usd' => GenerationPricing::formatUsd($figures['customer'] - $figures['provider']),
            'tokens' => array_intersect_key($figures, array_flip(self::TOKENS)),
        ];
    }

    /**
     * @param  array<string, mixed>  $figures
     * @param  Collection<int, User>  $owners
     * @return array<string, mixed>
     */
    private static function userFigures(?int $userId, array $figures, Collection $owners): array
    {
        $owner = $userId === null ? null : $owners->get($userId);

        return [
            'user_id' => $userId,
            'name' => $owner === null ? 'Deleted user' : $owner->name,
            'email' => $owner?->email,
            ...self::money($figures),
        ];
    }
}

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
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;
use Saloon\Exceptions\Request\FatalRequestException;

/**
 * Operator report for an inclusive UTC date range (D-060).
 *
 * Local figures come only from immutable charges made with the configured API
 * key, grouped by provider completion day. Each day is compared with what
 * Pixelforge says it billed that key; differences are reported, never fixed.
 */
final class BuildOperatorReport
{
    private const TOKENS = PixelforgePricing::TOKEN_TYPES;

    /** @return array<string, mixed> */
    public function execute(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $key = config('services.pixelforge.api_key');
        $key = is_string($key) && trim($key) !== '' ? $key : null;
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
                'users' => array_map(fn (array $row): array => [...self::owner($row['user_id'], $owners), ...self::money($row)], $dayRows),
                'pixelforge' => $usage === null ? null : self::reconcile($local, $usage->days[$date]),
            ];
        }

        $users = array_values(array_map(fn (array $person): array => [...self::owner($person['user_id'], $owners), ...self::money($person['figures'])], $people));
        usort($users, fn (array $a, array $b): int => $b['raw']['customer'] <=> $a['raw']['customer']);
        $totals = self::sum($rows);
        $bank = CreditLedgerEntry::bank();
        $billed = null;
        if ($usage !== null) {
            $billed = array_fill_keys(ProviderUsage::FIELDS, 0);
            foreach ($usage->days as $figures) {
                foreach ($figures as $field => $value) {
                    $billed[$field] += $value;
                }
            }
        }

        return [
            'range' => ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()],
            'days' => array_map(fn (array $day): array => self::strip($day), $days),
            'users' => array_map(fn (array $user): array => self::strip($user), $users),
            'totals' => self::strip(self::money($totals)),
            'reconciliation' => [
                'available' => $usage !== null,
                'reason' => $unavailable,
                'mismatched_days' => count(array_filter($days, fn (array $day): bool => ($day['pixelforge']['status'] ?? null) === 'mismatch')),
                'pixelforge_total' => $billed === null ? null : self::reconcile($totals, $billed),
            ],
            'other_key_charges' => GenerationCharge::otherKeyCountBetween($start, $end, $fingerprint),
            // Point in time, not range-bound: credit customers hold with us now.
            'bank' => ['unspent_usd' => CreditLedgerEntry::format($bank['available'] + $bank['held']), 'held_usd' => CreditLedgerEntry::format($bank['held'])],
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
     * @param  array<string, int>  $local  summed local figures
     * @param  array<string, int>  $provider  ProviderUsage day figures
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
            'cost_usd' => CreditLedgerEntry::format($provider['cost_nanodollars']),
            // Ours minus theirs: positive means we recorded more than Pixelforge billed.
            'cost_difference_usd' => CreditLedgerEntry::format($local['provider'] - $provider['cost_nanodollars']),
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
     * Exact strings for the browser, plus the raw integers used for sorting/totals (stripped before output).
     *
     * @param  array<string, mixed>  $figures
     * @return array<string, mixed>
     */
    private static function money(array $figures): array
    {
        $figures = self::sum([$figures]);

        return [
            'generations' => $figures['generations'],
            'credit_spend_usd' => CreditLedgerEntry::format($figures['customer']),
            'provider_cost_usd' => CreditLedgerEntry::format($figures['provider']),
            'profit_usd' => CreditLedgerEntry::format($figures['customer'] - $figures['provider']),
            'tokens' => array_intersect_key($figures, array_flip(self::TOKENS)),
            'raw' => $figures,
        ];
    }

    /**
     * @param  Collection<int, User>  $owners
     * @return array{user_id: int|null, name: string, email: string|null}
     */
    private static function owner(?int $userId, $owners): array
    {
        $owner = $userId === null ? null : $owners->get($userId);

        return $owner === null
            ? ['user_id' => $userId, 'name' => 'Deleted user', 'email' => null]
            : ['user_id' => $userId, 'name' => $owner->name, 'email' => $owner->email];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function strip(array $row): array
    {
        unset($row['raw']);
        if (isset($row['users'])) {
            $row['users'] = array_map(fn (array $user): array => self::strip($user), $row['users']);
        }

        return $row;
    }
}

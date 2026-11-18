<?php

namespace Tests\Feature;

use App\Http\Integrations\Pixelforge\Requests\GetUsage;
use App\Models\CreditLedgerEntry;
use App\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Response;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class OperatorReportTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'report-test-key';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.pixelforge.api_key' => self::KEY]);
        $this->travelTo('2026-11-12 12:00:00');
        $this->admin = User::factory()->create(['name' => 'Operator']);
        $this->admin->forceFill(['is_admin' => true])->save();
    }

    public function test_only_operators_can_open_the_report(): void
    {
        Saloon::fake([GetUsage::class => $this->usage('2026-10-14', '2026-11-12', [])]);

        $this->get(route('admin.report'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('admin.report'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.report'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/Report')
                // Default: the last 30 days ending today (UTC), like Pixelforge's endpoint.
                ->where('report.range', ['start_date' => '2026-10-14', 'end_date' => '2026-11-12'])
                ->has('report.days', 30));
        Saloon::assertSent(fn (GetUsage $request, Response $response): bool => $response->getPendingRequest()->query()->all() === ['start_date' => '2026-10-14', 'end_date' => '2026-11-12']
            && $response->getPendingRequest()->headers()->get('Authorization') === 'Bearer '.self::KEY);
    }

    public function test_the_admin_command_grants_and_revokes_operator_access(): void
    {
        $user = User::factory()->create(['email' => 'ops@example.com']);

        $this->artisan('users:admin', ['email' => 'ops@example.com'])->expectsOutput('ops@example.com is now an operator.')->assertSuccessful();
        $this->assertTrue($user->fresh()->is_admin);
        $this->artisan('users:admin', ['email' => 'ops@example.com', '--revoke' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->is_admin);
        $this->artisan('users:admin', ['email' => 'nobody@example.com'])->assertFailed();
        // Not mass assignable through ordinary user updates.
        $this->assertNotContains('is_admin', (new User)->getFillable());
    }

    public function test_charges_group_by_utc_completion_day_and_user_with_exact_totals_and_zero_days(): void
    {
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);
        $gone = User::factory()->create(['name' => 'Gone']);
        $this->charge($alice, '2026-11-10 23:59:59.999999', 347_647_500, [7, 0, 6450, 4500]);
        $this->charge($bob, '2026-11-10 08:00:00', 17_265_000, [18, 0, 280, 478]);
        $this->charge($alice, '2026-11-11 00:00:00', 281_927_500, [12, 0, 6450, 1592]);
        $this->charge($gone, '2026-11-11 09:00:00', 63_935_000, [7, 0, 1100, 1592]);
        $this->charge($bob, '2026-11-10 10:00:00', 50_000_000, [1, 0, 1, 1], key: 'another-key');
        DB::table('generations')->where('user_id', $gone->id)->update(['user_id' => null]); // operator deletion keeps evidence
        Saloon::fake([GetUsage::class => $this->usage('2026-11-09', '2026-11-11', [
            '2026-11-10' => [2, 25, 0, 6730, 4978, '0.364912500'],
            '2026-11-11' => [2, 19, 0, 7550, 3184, '0.345862500'],
        ])]);

        $this->report('2026-11-09', '2026-11-11')->assertInertia(fn (Assert $page) => $page
            ->has('report.days', 3)
            ->where('report.days.0', fn ($day) => $day['date'] === '2026-11-09' && $day['generations'] === 0 && $day['credit_spend_usd'] === '0.000000000' && $day['users'] === [] && $day['pixelforge']['status'] === 'match')
            ->where('report.days.1.generations', 2)
            // $0.44 + $0.03 charged; 0.3476475 + 0.017265 provider.
            ->where('report.days.1.credit_spend_usd', '0.470000000')
            ->where('report.days.1.provider_cost_usd', '0.364912500')
            ->where('report.days.1.profit_usd', '0.105087500')
            ->where('report.days.1.users.0.name', 'Alice')
            ->where('report.days.1.users.1.name', 'Bob')
            ->where('report.days.2.users.1.name', 'Deleted user')
            ->where('report.days.2.credit_spend_usd', '0.440000000')
            ->where('report.totals.generations', 4)
            ->where('report.totals.credit_spend_usd', '0.910000000')
            ->where('report.totals.provider_cost_usd', '0.710775000')
            ->where('report.totals.profit_usd', '0.199225000')
            ->where('report.users.0.name', 'Alice')
            ->where('report.users.0.credit_spend_usd', '0.800000000')
            ->where('report.reconciliation.available', true)
            ->where('report.reconciliation.mismatched_days', 0)
            ->where('report.reconciliation.pixelforge_total.status', 'match')
            ->where('report.reconciliation.pixelforge_total.cost_usd', '0.710775000')
            ->where('report.other_key_charges', 1));
    }

    public function test_the_bank_is_all_unspent_customer_credit_now_including_holds(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $settled = Generation::factory()->for($alice)->create();
        $running = Generation::factory()->for($bob)->create();
        $entry = fn (array $row): bool => DB::table('credit_ledger_entries')->insert($row + ['generation_id' => null, 'idempotency_key' => null, 'reason' => null, 'created_at' => now()]);
        $entry(['user_id' => $alice->id, 'type' => 'purchase', 'amount_nanodollars' => 10_000_000_000, 'idempotency_key' => (string) Str::uuid()]);
        $entry(['user_id' => $alice->id, 'type' => 'hold', 'amount_nanodollars' => 480_000_000, 'generation_id' => $settled->id]);
        $entry(['user_id' => $alice->id, 'type' => 'release', 'amount_nanodollars' => 480_000_000, 'generation_id' => $settled->id]);
        $entry(['user_id' => $alice->id, 'type' => 'debit', 'amount_nanodollars' => 440_000_000, 'generation_id' => $settled->id]);
        $entry(['user_id' => $bob->id, 'type' => 'purchase', 'amount_nanodollars' => 5_000_000_000, 'idempotency_key' => (string) Str::uuid()]);
        $entry(['user_id' => $bob->id, 'type' => 'hold', 'amount_nanodollars' => 460_000_000, 'generation_id' => $running->id]);
        $entry(['user_id' => $bob->id, 'type' => 'debit_adjustment', 'amount_nanodollars' => 1_000_000_000, 'reason' => 'Correction']);
        // Credit left behind by a deleted user is still unspent.
        $entry(['user_id' => null, 'type' => 'purchase', 'amount_nanodollars' => 2_000_000_000, 'idempotency_key' => (string) Str::uuid()]);
        Saloon::fake([GetUsage::class => $this->usage('2026-11-11', '2026-11-11', [])]);

        // Available 9.56 + 3.54 + 2.00 = 15.10, plus 0.46 held for Bob's running generation.
        $this->report('2026-11-11', '2026-11-11')->assertInertia(fn (Assert $page) => $page
            ->where('report.bank', ['unspent_usd' => '15.560000000', 'held_usd' => '0.460000000']));
        $perUser = array_sum(array_map(fn (User $user): int => CreditLedgerEntry::availableFor($user) + CreditLedgerEntry::heldFor($user), [$alice, $bob, $this->admin]));
        $this->assertSame($perUser + 2_000_000_000, array_sum(CreditLedgerEntry::bank()));
    }

    public function test_differences_from_pixelforge_are_reported_not_adjusted(): void
    {
        $user = User::factory()->create();
        $this->charge($user, '2026-11-11 10:00:00', 17_265_000, [18, 0, 280, 478]);
        Saloon::fake([GetUsage::class => $this->usage('2026-11-11', '2026-11-11', [
            '2026-11-11' => [2, 18, 0, 280, 500, '0.017265001'],
        ])]);

        $this->report('2026-11-11', '2026-11-11')->assertInertia(fn (Assert $page) => $page
            ->where('report.reconciliation.mismatched_days', 1)
            ->where('report.days.0.pixelforge.status', 'mismatch')
            ->where('report.days.0.pixelforge.mismatches', ['generations', 'cost', 'reasoning_tokens'])
            ->where('report.days.0.pixelforge.cost_difference_usd', '-0.000000001')
            ->where('report.days.0.pixelforge.generations_difference', -1)
            // Our own figures are unchanged.
            ->where('report.days.0.provider_cost_usd', '0.017265000'));
    }

    /** @return array<string, array{MockResponse}> */
    public static function unusableUsage(): array
    {
        $day = ['date' => '2026-11-11', 'generations' => 0, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_usd' => '0.000000000'];
        $envelope = fn (array $days, array $total): array => ['object' => 'usage', 'start_date' => '2026-11-10', 'end_date' => '2026-11-11', 'data' => $days, 'total' => $total];
        $zero = array_diff_key($day, ['date' => true]);

        return [
            'server error' => [MockResponse::make(['error' => ['code' => 'internal']], 500)],
            'missing day' => [MockResponse::make($envelope([$day], $zero))],
            'total disagrees with days' => [MockResponse::make($envelope([[...$day, 'date' => '2026-11-10'], $day], [...$zero, 'generations' => 1]))],
            'cost not nine decimals' => [MockResponse::make($envelope([[...$day, 'date' => '2026-11-10', 'cost_usd' => '0.10'], $day], $zero))],
        ];
    }

    #[DataProvider('unusableUsage')]
    public function test_unusable_pixelforge_usage_leaves_local_figures_and_says_why(MockResponse $mock): void
    {
        $this->charge(User::factory()->create(), '2026-11-11 10:00:00', 17_265_000, [18, 0, 280, 478]);
        Saloon::fake([GetUsage::class => $mock]);

        $this->report('2026-11-10', '2026-11-11')->assertInertia(fn (Assert $page) => $page
            ->where('report.reconciliation.available', false)
            ->where('report.reconciliation.reason', fn (string $reason) => $reason !== '')
            ->where('report.days.1.pixelforge', null)
            ->where('report.totals.provider_cost_usd', '0.017265000'));
    }

    public function test_without_an_api_key_nothing_is_fetched_or_attributed(): void
    {
        config(['services.pixelforge.api_key' => null]);
        Saloon::fake([]);

        $this->report('2026-11-11', '2026-11-11')->assertInertia(fn (Assert $page) => $page
            ->where('report.key_configured', false)
            ->where('report.reconciliation.available', false));
        Saloon::assertNothingSent();
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function invalidRanges(): array
    {
        return [
            'future end' => [['start_date' => '2026-11-10', 'end_date' => '2026-11-13'], 'end_date'],
            'start after end' => [['start_date' => '2026-11-11', 'end_date' => '2026-11-10'], 'start_date'],
            'more than 92 days' => [['start_date' => '2026-08-11', 'end_date' => '2026-11-11'], 'start_date'],
            'not a date' => [['start_date' => '11/10/2026'], 'start_date'],
        ];
    }

    /** @param array<string, string> $query */
    #[DataProvider('invalidRanges')]
    public function test_invalid_ranges_are_rejected_before_fetching(array $query, string $field): void
    {
        Saloon::fake([]);

        $this->actingAs($this->admin)->get(route('admin.report', $query))->assertSessionHasErrors($field);
        Saloon::assertNothingSent();
    }

    public function test_exactly_92_days_is_allowed(): void
    {
        Saloon::fake([GetUsage::class => $this->usage('2026-08-12', '2026-11-11', [])]);

        $this->report('2026-08-12', '2026-11-11')->assertOk()->assertInertia(fn (Assert $page) => $page->has('report.days', 92));
    }

    private function report(string $start, string $end): TestResponse
    {
        return $this->actingAs($this->admin)->get(route('admin.report', ['start_date' => $start, 'end_date' => $end]))->assertOk();
    }

    /** @param array{int, int, int, int} $tokens text, image, output, reasoning */
    private function charge(User $user, string $completedAt, int $provider, array $tokens, string $key = self::KEY): void
    {
        $generation = Generation::factory()->for($user)->create();
        $usage = array_combine(['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'], $tokens);
        // Fixture only: settled evidence is normally written by the settlement workflow.
        DB::table('generations')->where('id', $generation->id)->update([
            'submission_status' => 'accepted', 'provider_id' => 'gen_'.$generation->id, 'provider_status' => 'succeeded',
            'provider_key_fingerprint' => hash('sha256', $key), 'rates_snapshot' => json_encode(['input_text_tokens' => '5.00']),
            'usage' => json_encode($usage + ['total_tokens' => array_sum($tokens)]), 'provider_created_at' => $completedAt, 'provider_completed_at' => $completedAt,
        ]);
        DB::table('generation_charges')->insert([
            'generation_id' => $generation->id, 'provider_cost_nanodollars' => $provider,
            'customer_cost_nanodollars' => intdiv($provider * 5 + 39_999_999, 40_000_000) * 10_000_000,
            'markup_numerator' => 5, 'markup_denominator' => 4, 'rounding' => 'ceiling', 'scale' => 2,
            'provider_completed_at' => $completedAt, 'created_at' => now(),
        ]);
    }

    /** @param array<string, array{int, int, int, int, int, string}> $days date => [generations, text, image, output, reasoning, cost] */
    private function usage(string $start, string $end, array $days): MockResponse
    {
        $data = [];
        $total = ['generations' => 0, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost' => 0];
        for ($day = CarbonImmutable::parse($start); $day->lte(CarbonImmutable::parse($end)); $day = $day->addDay()) {
            [$n, $text, $image, $output, $reasoning, $cost] = $days[$day->toDateString()] ?? [0, 0, 0, 0, 0, '0.000000000'];
            $data[] = ['date' => $day->toDateString(), 'generations' => $n, 'input_text_tokens' => $text, 'input_image_tokens' => $image, 'output_tokens' => $output, 'reasoning_tokens' => $reasoning, 'cost_usd' => $cost];
            foreach (['generations' => $n, 'input_text_tokens' => $text, 'input_image_tokens' => $image, 'output_tokens' => $output, 'reasoning_tokens' => $reasoning, 'cost' => (int) str_replace('.', '', $cost)] as $field => $value) {
                $total[$field] += $value;
            }
        }
        $cost = $total['cost'];
        unset($total['cost']);

        return MockResponse::make(['object' => 'usage', 'start_date' => $start, 'end_date' => $end, 'data' => $data,
            'total' => $total + ['cost_usd' => sprintf('%d.%09d', intdiv($cost, 1_000_000_000), $cost % 1_000_000_000)]]);
    }
}

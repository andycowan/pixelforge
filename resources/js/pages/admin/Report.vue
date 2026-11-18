<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatSignedUsd, formatUsdAmount } from '@/lib/quote';
import { report as adminReport } from '@/routes/admin';

type Figures = {
    generations: number;
    credit_spend_usd: string;
    provider_cost_usd: string;
    profit_usd: string;
};
type Owner = { user_id: number | null; name: string; email: string | null };
type Check = {
    status: 'match' | 'mismatch';
    mismatches: string[];
    generations: number;
    cost_usd: string;
    cost_difference_usd: string;
    generations_difference: number;
};
type Day = Figures & {
    date: string;
    users: (Owner & Figures)[];
    pixelforge: Check | null;
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Operator report', href: adminReport() }],
    },
});

const props = defineProps<{
    report: {
        range: { start_date: string; end_date: string };
        days: Day[];
        users: (Owner & Figures)[];
        totals: Figures;
        reconciliation: {
            available: boolean;
            reason: string | null;
            mismatched_days: number;
            pixelforge_total: Check | null;
        };
        other_key_charges: number;
        key_configured: boolean;
        bank: { unspent_usd: string; held_usd: string };
    };
    errors: Partial<Record<'start_date' | 'end_date', string>>;
}>();

const range = reactive({ ...props.report.range });

function apply(): void {
    router.get(adminReport().url, { ...range }, { preserveScroll: true });
}

const days = computed(() => [...props.report.days].reverse()); // newest first
const reconciliation = computed(() => props.report.reconciliation);

const usd = (value: string): string => `$${formatUsdAmount(value)}`;

/** Plain-language reason for a mismatch: ours minus Pixelforge's. */
function describe(check: Check): string {
    const parts: string[] = [];
    if (check.mismatches.includes('cost')) {
        parts.push(`cost ${formatSignedUsd(check.cost_difference_usd)}`);
    }
    if (check.mismatches.includes('generations')) {
        parts.push(
            `generations ${check.generations_difference > 0 ? '+' : ''}${check.generations_difference}`,
        );
    }
    const tokens = check.mismatches.filter(
        (field) => field !== 'cost' && field !== 'generations',
    );
    if (tokens.length > 0) {
        parts.push(
            `${tokens.map((t) => t.replaceAll('_', ' ')).join(', ')} differ`,
        );
    }

    return parts.join('; ');
}

function formatDate(date: string): string {
    return new Date(`${date}T00:00:00Z`).toLocaleDateString(undefined, {
        timeZone: 'UTC',
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}
</script>

<template>
    <Head title="Operator report" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            class="mb-0!"
            title="Operator report"
            description="Credit spend, provider cost and profit by UTC day and user, checked against what Pixelforge billed this API key."
        />

        <form
            class="flex flex-wrap items-end gap-3"
            aria-label="Report date range"
            @submit.prevent="apply"
        >
            <div class="grid gap-1.5">
                <Label for="start_date">From (UTC)</Label>
                <Input
                    id="start_date"
                    v-model="range.start_date"
                    type="date"
                    class="w-44"
                    :aria-invalid="errors.start_date ? true : undefined"
                />
            </div>
            <div class="grid gap-1.5">
                <Label for="end_date">To (UTC)</Label>
                <Input
                    id="end_date"
                    v-model="range.end_date"
                    type="date"
                    class="w-44"
                    :aria-invalid="errors.end_date ? true : undefined"
                />
            </div>
            <Button type="submit">Show</Button>
            <p class="w-full text-xs text-muted-foreground">
                Up to 92 days. Generations count on the day they completed, as
                Pixelforge bills them.
            </p>
            <InputError :message="errors.start_date ?? errors.end_date" />
        </form>

        <Alert v-if="!reconciliation.available" variant="destructive">
            <AlertTitle>Pixelforge usage unavailable</AlertTitle>
            <AlertDescription
                >{{ reconciliation.reason }} Our own figures are shown
                below.</AlertDescription
            >
        </Alert>
        <Alert
            v-else-if="reconciliation.mismatched_days > 0"
            variant="destructive"
        >
            <AlertTitle
                >{{ reconciliation.mismatched_days }}
                {{
                    reconciliation.mismatched_days === 1
                        ? 'day differs'
                        : 'days differ'
                }}
                from Pixelforge</AlertTitle
            >
            <AlertDescription
                >Our records are never adjusted to match. Investigate the days
                marked below.</AlertDescription
            >
        </Alert>
        <Alert v-else>
            <AlertTitle>Matches Pixelforge</AlertTitle>
            <AlertDescription
                >Provider cost, generation counts and tokens agree with
                Pixelforge's usage for every day in this
                range.</AlertDescription
            >
        </Alert>
        <p
            v-if="report.other_key_charges > 0"
            class="text-sm text-muted-foreground"
        >
            {{ report.other_key_charges }}
            {{ report.other_key_charges === 1 ? 'charge' : 'charges' }} in this
            range
            {{
                report.key_configured
                    ? `${report.other_key_charges === 1 ? 'was' : 'were'} made with a different Pixelforge API key and ${report.other_key_charges === 1 ? 'is' : 'are'} not included.`
                    : "can't be attributed to an API key because none is configured."
            }}
        </p>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <Card>
                <CardHeader>
                    <CardDescription>Credit spend</CardDescription>
                    <CardTitle class="text-2xl tabular-nums">{{
                        usd(report.totals.credit_spend_usd)
                    }}</CardTitle>
                </CardHeader>
                <CardContent class="text-xs text-muted-foreground"
                    >{{ report.totals.generations }} charged
                    generations</CardContent
                >
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>Provider cost</CardDescription>
                    <CardTitle class="text-2xl tabular-nums">{{
                        usd(report.totals.provider_cost_usd)
                    }}</CardTitle>
                </CardHeader>
                <CardContent class="text-xs text-muted-foreground"
                    >From our settled charges</CardContent
                >
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>Profit</CardDescription>
                    <CardTitle class="text-2xl tabular-nums">{{
                        formatSignedUsd(report.totals.profit_usd)
                    }}</CardTitle>
                </CardHeader>
                <CardContent class="text-xs text-muted-foreground"
                    >Credit spend minus provider cost</CardContent
                >
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>Pixelforge billed</CardDescription>
                    <CardTitle class="text-2xl tabular-nums">{{
                        reconciliation.pixelforge_total
                            ? usd(reconciliation.pixelforge_total.cost_usd)
                            : '—'
                    }}</CardTitle>
                </CardHeader>
                <CardContent class="text-xs text-muted-foreground">{{
                    reconciliation.pixelforge_total === null
                        ? 'Unavailable'
                        : reconciliation.pixelforge_total.status === 'match'
                          ? 'Matches our provider cost'
                          : `Differs: ${describe(reconciliation.pixelforge_total)}`
                }}</CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>Bank</CardDescription>
                    <CardTitle class="text-2xl tabular-nums">{{
                        formatSignedUsd(report.bank.unspent_usd)
                    }}</CardTitle>
                </CardHeader>
                <CardContent class="text-xs text-muted-foreground"
                    >Unspent customer credit now, all users<template
                        v-if="report.bank.held_usd !== '0.000000000'"
                        >, including {{ usd(report.bank.held_usd) }} held for
                        generations in progress</template
                    ></CardContent
                >
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>By day</CardTitle>
                <CardDescription
                    >Newest first. Each day lists the users charged that
                    day.</CardDescription
                >
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <table class="w-full min-w-[48rem] text-sm">
                    <thead
                        class="border-b text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th scope="col" class="py-2 pr-4 font-medium">
                                Day / user
                            </th>
                            <th
                                scope="col"
                                class="py-2 pr-4 text-right font-medium"
                            >
                                Generations
                            </th>
                            <th
                                scope="col"
                                class="py-2 pr-4 text-right font-medium"
                            >
                                Credit spend
                            </th>
                            <th
                                scope="col"
                                class="py-2 pr-4 text-right font-medium"
                            >
                                Provider cost
                            </th>
                            <th
                                scope="col"
                                class="py-2 pr-4 text-right font-medium"
                            >
                                Profit
                            </th>
                            <th
                                scope="col"
                                class="py-2 pr-4 text-right font-medium"
                            >
                                Pixelforge billed
                            </th>
                            <th scope="col" class="py-2 font-medium">Check</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="day in days" :key="day.date">
                            <tr
                                class="border-t tabular-nums"
                                :class="{
                                    'text-muted-foreground':
                                        day.generations === 0 &&
                                        day.pixelforge?.status !== 'mismatch',
                                }"
                            >
                                <th
                                    scope="row"
                                    class="py-2 pr-4 text-left font-medium"
                                >
                                    {{ formatDate(day.date) }}
                                </th>
                                <td class="py-2 pr-4 text-right">
                                    {{ day.generations }}
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    {{ usd(day.credit_spend_usd) }}
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    {{ usd(day.provider_cost_usd) }}
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    {{ formatSignedUsd(day.profit_usd) }}
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    {{
                                        day.pixelforge
                                            ? usd(day.pixelforge.cost_usd)
                                            : '—'
                                    }}
                                </td>
                                <td class="py-2">
                                    <span
                                        v-if="
                                            day.pixelforge?.status === 'match'
                                        "
                                        class="text-emerald-600 dark:text-emerald-400"
                                        >Match</span
                                    >
                                    <span
                                        v-else-if="day.pixelforge"
                                        class="font-medium text-destructive"
                                        >Mismatch:
                                        {{ describe(day.pixelforge) }}</span
                                    >
                                    <span v-else class="text-muted-foreground"
                                        >—</span
                                    >
                                </td>
                            </tr>
                            <tr
                                v-for="user in day.users"
                                :key="`${day.date}-${user.user_id ?? 'deleted'}`"
                                class="text-xs text-muted-foreground tabular-nums"
                            >
                                <th
                                    scope="row"
                                    class="py-1 pr-4 pl-4 text-left font-normal"
                                >
                                    {{ user.name }}
                                    <span v-if="user.email" class="ml-1">{{
                                        user.email
                                    }}</span>
                                </th>
                                <td class="py-1 pr-4 text-right">
                                    {{ user.generations }}
                                </td>
                                <td class="py-1 pr-4 text-right">
                                    {{ usd(user.credit_spend_usd) }}
                                </td>
                                <td class="py-1 pr-4 text-right">
                                    {{ usd(user.provider_cost_usd) }}
                                </td>
                                <td class="py-1 pr-4 text-right">
                                    {{ formatSignedUsd(user.profit_usd) }}
                                </td>
                                <td colspan="2"></td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot class="border-t-2 font-medium tabular-nums">
                        <tr>
                            <th scope="row" class="py-2 pr-4 text-left">
                                Total
                            </th>
                            <td class="py-2 pr-4 text-right">
                                {{ report.totals.generations }}
                            </td>
                            <td class="py-2 pr-4 text-right">
                                {{ usd(report.totals.credit_spend_usd) }}
                            </td>
                            <td class="py-2 pr-4 text-right">
                                {{ usd(report.totals.provider_cost_usd) }}
                            </td>
                            <td class="py-2 pr-4 text-right">
                                {{ formatSignedUsd(report.totals.profit_usd) }}
                            </td>
                            <td class="py-2 pr-4 text-right">
                                {{
                                    reconciliation.pixelforge_total
                                        ? usd(
                                              reconciliation.pixelforge_total
                                                  .cost_usd,
                                          )
                                        : '—'
                                }}
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>By user</CardTitle>
                <CardDescription
                    >Totals for the whole range, highest spend
                    first.</CardDescription
                >
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <p
                    v-if="report.users.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No charged generations in this range.
                </p>
                <table v-else class="w-full min-w-[36rem] text-sm">
                    <thead
                        class="border-b text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th scope="col" class="py-2 pr-4 font-medium">
                                User
                            </th>
                            <th
                                scope="col"
                                class="py-2 pr-4 text-right font-medium"
                            >
                                Generations
                            </th>
                            <th
                                scope="col"
                                class="py-2 pr-4 text-right font-medium"
                            >
                                Credit spend
                            </th>
                            <th
                                scope="col"
                                class="py-2 pr-4 text-right font-medium"
                            >
                                Provider cost
                            </th>
                            <th scope="col" class="py-2 text-right font-medium">
                                Profit
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="user in report.users"
                            :key="user.user_id ?? 'deleted'"
                            class="border-t tabular-nums"
                        >
                            <th
                                scope="row"
                                class="py-2 pr-4 text-left font-medium"
                            >
                                {{ user.name }}
                                <span
                                    v-if="user.email"
                                    class="ml-1 text-xs font-normal text-muted-foreground"
                                    >{{ user.email }}</span
                                >
                            </th>
                            <td class="py-2 pr-4 text-right">
                                {{ user.generations }}
                            </td>
                            <td class="py-2 pr-4 text-right">
                                {{ usd(user.credit_spend_usd) }}
                            </td>
                            <td class="py-2 pr-4 text-right">
                                {{ usd(user.provider_cost_usd) }}
                            </td>
                            <td class="py-2 text-right">
                                {{ formatSignedUsd(user.profit_usd) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>
    </div>
</template>

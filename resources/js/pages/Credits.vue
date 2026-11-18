<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import LiveStatus from '@/components/LiveStatus.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { useGenerationStream } from '@/composables/useGenerationStream';
import { creditEntryPresentation } from '@/lib/credits';
import { createSubmissionToken } from '@/lib/generation';
import { formatSignedUsd, formatUsdAmount } from '@/lib/quote';
import { index } from '@/routes/credits';
import { store } from '@/routes/credits/purchases';
import type { CreditEntry, CreditPackage } from '@/types/credits';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Credits', href: index() }] },
});

const props = defineProps<{
    packages: CreditPackage[];
    entries: CreditEntry[];
    /** Same-origin hub path and this user's private topic. */
    live: { hub: string; topic: string };
}>();
// Holds, releases and charges follow generation changes, so the same stream refreshes them.
const { state: streamState } = useGenerationStream(
    props.live.hub,
    props.live.topic,
    ['entries', 'credits'],
);
const page = usePage();
const balance = computed(() => page.props.credits);
const form = useForm({ package: '', idempotency_token: '' });
const pendingPackage = ref<string | null>(null);
const requestError = ref<string | null>(null);
const tokens = new Map<string, string>();
const errors = computed(() =>
    [requestError.value, ...Object.values(form.errors)].filter(Boolean),
);

function purchase(packageId: string): void {
    if (form.processing) return;

    const token = tokens.get(packageId) ?? createSubmissionToken();
    tokens.set(packageId, token);
    form.package = packageId;
    form.idempotency_token = token;
    pendingPackage.value = packageId;
    requestError.value = null;
    form.clearErrors();
    form.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            tokens.delete(packageId);
        },
        onHttpException: (response) => {
            requestError.value =
                response.status === 429
                    ? 'Too many purchases. Wait a minute, then retry the same package.'
                    : 'The purchase could not be confirmed. Retry the same package to avoid duplicate credit.';

            // Handled inline; the page is not being replaced.
            return false;
        },
        onNetworkError: () => {
            requestError.value =
                'Connection lost before the purchase was confirmed. Retry the same package to avoid duplicate credit.';
            return false;
        },
        onFinish: () => {
            pendingPackage.value = null;
        },
    });
}

function timestamp(value: string): string {
    return new Date(value).toLocaleString();
}
</script>

<template>
    <Head title="Credits" />
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            class="mb-0!"
            title="Credits"
            description="Your available credit, generation holds and recent activity. All amounts are in USD."
        />
        <p class="text-xs text-muted-foreground">
            <LiveStatus
                :state="streamState"
                live-label="Live: balance and activity update automatically"
            />
        </p>

        <Card>
            <CardHeader>
                <CardTitle>Your balance</CardTitle>
                <CardDescription
                    >Each generation holds its maximum price until it finishes.
                    Then the hold is released and only the actual charge is
                    debited.</CardDescription
                >
            </CardHeader>
            <CardContent>
                <dl v-if="balance" class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <dt class="text-sm text-muted-foreground">
                            Available balance
                        </dt>
                        <dd
                            class="mt-1 text-3xl font-semibold break-all tabular-nums"
                        >
                            {{ formatSignedUsd(balance.available_usd) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-muted-foreground">On hold</dt>
                        <dd
                            class="mt-1 text-3xl font-semibold break-all tabular-nums"
                        >
                            ${{ formatUsdAmount(balance.held_usd) }}
                        </dd>
                    </div>
                </dl>
                <p v-else class="text-sm text-muted-foreground">
                    Balance unavailable. Refresh to try again.
                </p>
            </CardContent>
        </Card>

        <section aria-labelledby="buy-credits-title" class="grid gap-4">
            <Alert class="border-primary/40 bg-primary/5">
                <AlertTitle id="buy-credits-title"
                    >Demo purchase — no payment is taken and no card is
                    needed</AlertTitle
                >
                <AlertDescription
                    >These packages add demo credit immediately. This is not a
                    real payment checkout.</AlertDescription
                >
            </Alert>
            <div class="grid gap-4 sm:grid-cols-3">
                <Card v-for="item in packages" :key="item.id">
                    <CardHeader>
                        <CardTitle class="text-2xl tabular-nums"
                            >${{ formatUsdAmount(item.amount_usd) }}</CardTitle
                        >
                        <CardDescription
                            >Demo generation credit</CardDescription
                        >
                    </CardHeader>
                    <CardContent>
                        <Button
                            type="button"
                            class="w-full"
                            :disabled="form.processing"
                            :aria-busy="
                                form.processing && pendingPackage === item.id
                            "
                            @click="purchase(item.id)"
                        >
                            <Spinner
                                v-if="
                                    form.processing &&
                                    pendingPackage === item.id
                                "
                                class="size-4"
                            />
                            {{
                                form.processing && pendingPackage === item.id
                                    ? 'Adding credit…'
                                    : `Buy $${formatUsdAmount(item.amount_usd)}`
                            }}
                        </Button>
                    </CardContent>
                </Card>
            </div>
            <div
                v-if="errors.length"
                role="alert"
                class="text-sm text-destructive"
            >
                <p v-for="(error, i) in errors" :key="i">{{ error }}</p>
            </div>
        </section>

        <Card>
            <CardHeader>
                <CardTitle>Recent activity</CardTitle>
                <CardDescription
                    >Your latest 50 credit entries, newest first. A hold
                    reserves credit while a generation is in progress; once it
                    finishes, only the actual charge is shown.</CardDescription
                >
            </CardHeader>
            <CardContent>
                <ul v-if="entries.length" class="divide-y">
                    <!-- Stacked on narrow screens; one aligned line from sm upwards. -->
                    <li
                        v-for="entry in entries"
                        :key="entry.id"
                        class="flex flex-col gap-1 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-baseline sm:gap-4"
                    >
                        <span class="text-sm font-medium sm:w-24 sm:shrink-0">
                            {{ creditEntryPresentation[entry.type].label }}
                        </span>
                        <span
                            class="min-w-0 truncate text-sm text-muted-foreground empty:hidden sm:flex-1 sm:empty:block"
                            :title="entry.detail ?? undefined"
                            >{{ entry.detail }}</span
                        >
                        <time
                            :datetime="entry.created_at"
                            class="text-xs text-muted-foreground sm:shrink-0"
                            >{{ timestamp(entry.created_at) }}</time
                        >
                        <span
                            class="text-sm font-medium tabular-nums sm:w-32 sm:shrink-0 sm:text-right"
                            >{{ creditEntryPresentation[entry.type].sign }}${{
                                formatUsdAmount(entry.amount_usd)
                            }}
                            USD</span
                        >
                    </li>
                </ul>
                <p
                    v-else
                    class="py-6 text-center text-sm text-muted-foreground"
                >
                    No credit activity yet. Add demo credit to start generating.
                </p>
            </CardContent>
        </Card>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import PriceInfoTooltip from '@/components/quote/PriceInfoTooltip.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import {
    formatCurrency,
    formatUsdAmount,
    parseReferenceUrls,
    quoteQualityOptions,
    quoteSizeOptions,
} from '@/lib/quote';
import type {
    QuoteFailure,
    QuoteFailureKind,
    QuoteFormInput,
    QuotePreview,
    QuoteStatus,
} from '@/types/quote';

const props = defineProps<{
    status: QuoteStatus;
    quote: QuotePreview | null;
    failure: QuoteFailure | null;
    input: QuoteFormInput;
}>();

const emit = defineEmits<{
    retry: [];
}>();

const failureTitles: Record<QuoteFailureKind, string> = {
    validation: 'Check your details',
    session: 'Session expired',
    throttled: 'Too many requests',
    unavailable: 'Pricing unavailable',
    network: 'Connection problem',
    unknown: 'Price unavailable',
};

const failureTitle = computed(() =>
    props.failure === null
        ? 'Price unavailable'
        : failureTitles[props.failure.kind],
);

// The quote is cleared whenever an input changes, so this label always
// describes the inputs the displayed price belongs to.
const contextLabel = computed(() => {
    const size =
        quoteSizeOptions.find((option) => option.value === props.input.size)
            ?.label ?? props.input.size;
    const quality =
        quoteQualityOptions.find(
            (option) => option.value === props.input.quality,
        )?.label ?? props.input.quality;
    const references = parseReferenceUrls(props.input.referenceUrls).length;
    const parts = [size, `${quality} quality`];

    if (references > 0) {
        parts.push(
            `${references} reference ${references === 1 ? 'image' : 'images'}`,
        );
    }

    return parts.join(' · ');
});

const announcement = computed<string>(() => {
    if (props.status === 'pending') {
        return 'Updating the price estimate.';
    }

    if (props.status === 'ready' && props.quote !== null) {
        const currency = formatCurrency(props.quote.currency);

        return `Estimate updated: ${formatUsdAmount(props.quote.estimated_cost_usd)} ${currency} estimated, ${formatUsdAmount(props.quote.maximum_cost_usd)} ${currency} maximum.`;
    }

    // Field errors are announced by the form; server failures use the alert.
    return '';
});

function retry(): void {
    emit('retry');
}
</script>

<template>
    <Card class="w-full">
        <CardHeader>
            <div class="flex flex-wrap items-start justify-between gap-2">
                <CardTitle>Price preview</CardTitle>
                <Badge variant="secondary">Preview only</Badge>
            </div>
            <CardDescription>
                Live prices from the provider pricing catalogue, calculated on
                the server.
            </CardDescription>
        </CardHeader>

        <CardContent class="flex flex-col gap-6">
            <p class="sr-only" aria-live="polite" aria-atomic="true">
                {{ announcement }}
            </p>

            <div class="min-h-5">
                <div
                    v-if="status === 'idle'"
                    class="flex flex-col gap-2 text-sm text-muted-foreground"
                >
                    <p>Add a prompt to see an estimate and maximum price.</p>
                </div>

                <div v-else-if="status === 'pending'">
                    <p
                        class="flex items-center gap-2 text-sm text-muted-foreground"
                    >
                        <Spinner class="size-4" />
                        Updating estimate…
                    </p>
                </div>

                <p
                    v-else-if="status === 'invalid'"
                    class="text-sm text-muted-foreground"
                >
                    Fix the highlighted fields to see a price. A quote request
                    is only sent for a prompt with valid options.
                </p>

                <Alert v-else-if="status === 'error' && failure !== null">
                    <AlertTitle>{{ failureTitle }}</AlertTitle>
                    <AlertDescription>
                        <p>{{ failure.message }}</p>
                        <Button
                            v-if="failure.retryable"
                            type="button"
                            variant="outline"
                            size="sm"
                            class="mt-3"
                            @click="retry"
                        >
                            Retry price preview
                        </Button>
                    </AlertDescription>
                </Alert>
            </div>

            <div class="flex flex-col gap-6">
                <p class="text-xs text-muted-foreground">
                    For {{ contextLabel }}
                </p>

                <div
                    class="grid gap-4 sm:grid-cols-2"
                    :aria-busy="status === 'pending'"
                >
                    <div class="rounded-lg border bg-muted/40 p-4">
                        <div class="flex items-center gap-1">
                            <p
                                class="text-xs font-medium text-muted-foreground uppercase"
                            >
                                Estimated price
                            </p>
                            <PriceInfoTooltip label="About the estimated price">
                                A typical price for these settings. You're
                                charged for what the generation actually uses,
                                so the final price may be a little higher or
                                lower.
                            </PriceInfoTooltip>
                        </div>
                        <div
                            class="mt-1 text-2xl font-semibold break-all tabular-nums"
                        >
                            <Skeleton
                                v-if="status === 'pending'"
                                class="h-8 w-36 max-w-full"
                            />
                            <template v-else-if="quote !== null"
                                >${{
                                    formatUsdAmount(quote.estimated_cost_usd)
                                }}</template
                            >
                            <template v-else>—</template>
                        </div>
                        <p class="mt-0.5 text-xs text-muted-foreground">USD</p>
                    </div>
                    <div class="rounded-lg border p-4">
                        <div class="flex items-center gap-1">
                            <p
                                class="text-xs font-medium text-muted-foreground uppercase"
                            >
                                Maximum price
                            </p>
                            <PriceInfoTooltip label="About the maximum price">
                                The most this generation can cost at today's
                                prices. This amount is reserved from your credit
                                while it runs. If prices change before it
                                starts, the maximum can change too.
                            </PriceInfoTooltip>
                        </div>
                        <div
                            class="mt-1 text-2xl font-semibold break-all tabular-nums"
                        >
                            <Skeleton
                                v-if="status === 'pending'"
                                class="h-8 w-36 max-w-full"
                            />
                            <template v-else-if="quote !== null"
                                >${{
                                    formatUsdAmount(quote.maximum_cost_usd)
                                }}</template
                            >
                            <template v-else>—</template>
                        </div>
                        <p class="mt-0.5 text-xs text-muted-foreground">USD</p>
                    </div>
                </div>

                <div class="flex flex-col gap-3 text-sm text-muted-foreground">
                    <p>
                        The actual price is only known once a generation
                        finishes and its exact usage is settled; failed
                        generations are not charged. A quote on its own never
                        creates a generation, reserves credit or charges
                        anything — generating is a separate, deliberate step.
                    </p>
                </div>
            </div>
        </CardContent>
    </Card>
</template>

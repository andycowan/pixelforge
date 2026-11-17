<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';
import {
    formatTokenCount,
    formatUsdAmount,
    quoteTokenCategories,
} from '@/lib/quote';
import type { QuotePreview } from '@/types/quote';

defineProps<{
    quote: QuotePreview | null;
    loading: boolean;
}>();
</script>

<template>
    <section
        aria-label="Token estimate and provider rates"
        class="rounded-lg border"
    >
        <h4 class="px-4 py-3 text-sm font-medium">
            Token estimate and provider rates
        </h4>

        <div class="grid gap-5 border-t px-4 py-4" :aria-busy="loading">
            <table class="w-full text-sm">
                <caption class="sr-only">
                    Token counts the estimate and maximum were calculated from
                </caption>
                <thead>
                    <tr class="text-xs text-muted-foreground">
                        <th scope="col" class="pb-2 text-left font-medium">
                            Token category
                        </th>
                        <th scope="col" class="pb-2 text-right font-medium">
                            Estimated
                        </th>
                        <th scope="col" class="pb-2 text-right font-medium">
                            Maximum
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="category in quoteTokenCategories"
                        :key="category.key"
                        class="border-t"
                    >
                        <th
                            scope="row"
                            class="py-1.5 pr-2 text-left font-normal text-muted-foreground"
                        >
                            {{ category.label }}
                        </th>
                        <td class="py-1.5 pl-2 text-right tabular-nums">
                            <Skeleton
                                v-if="loading"
                                class="inline-block h-4 w-12"
                            />
                            <template v-else>{{
                                quote === null
                                    ? '—'
                                    : formatTokenCount(
                                          quote.token_estimate[category.key],
                                      )
                            }}</template>
                        </td>
                        <td class="py-1.5 pl-2 text-right tabular-nums">
                            <Skeleton
                                v-if="loading"
                                class="inline-block h-4 w-12"
                            />
                            <template v-else>{{
                                quote === null
                                    ? '—'
                                    : formatTokenCount(
                                          quote.token_maximum[category.key],
                                      )
                            }}</template>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="grid gap-2">
                <h5 class="text-xs font-medium text-muted-foreground">
                    Provider rates (USD per 1M tokens)
                </h5>
                <dl class="grid gap-1 text-sm">
                    <div
                        v-for="category in quoteTokenCategories"
                        :key="category.key"
                        class="flex items-baseline justify-between gap-4"
                    >
                        <dt class="text-muted-foreground">
                            {{ category.label }}
                        </dt>
                        <dd class="tabular-nums">
                            <Skeleton
                                v-if="loading"
                                class="inline-block h-4 w-16"
                            />
                            <template v-else-if="quote !== null"
                                >${{
                                    formatUsdAmount(quote.rates[category.key])
                                }}</template
                            >
                            <template v-else>—</template>
                        </dd>
                    </div>
                </dl>
                <p class="text-xs text-muted-foreground">
                    These are the provider rates this quote was calculated with.
                </p>
            </div>

            <dl class="grid gap-1 border-t pt-4 text-sm">
                <div class="flex items-baseline justify-between gap-4">
                    <dt class="text-muted-foreground">
                        Provider cost at estimated usage
                    </dt>
                    <dd class="tabular-nums">
                        <Skeleton
                            v-if="loading"
                            class="inline-block h-4 w-20"
                        />
                        <template v-else-if="quote !== null"
                            >${{
                                formatUsdAmount(
                                    quote.provider_estimated_cost_usd,
                                )
                            }}</template
                        >
                        <template v-else>—</template>
                        USD
                    </dd>
                </div>
                <div class="flex items-baseline justify-between gap-4">
                    <dt class="text-muted-foreground">
                        Provider cost at maximum usage
                    </dt>
                    <dd class="tabular-nums">
                        <Skeleton
                            v-if="loading"
                            class="inline-block h-4 w-20"
                        />
                        <template v-else-if="quote !== null"
                            >${{
                                formatUsdAmount(quote.provider_maximum_cost_usd)
                            }}</template
                        >
                        <template v-else>—</template>
                        USD
                    </dd>
                </div>
            </dl>
        </div>
    </section>
</template>

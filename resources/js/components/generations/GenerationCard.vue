<script setup lang="ts">
import { computed } from 'vue';
import GenerationResultImage from '@/components/generations/GenerationResultImage.vue';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { describeActualCost, describeGenerationState } from '@/lib/generation';
import {
    formatUsdAmount,
    quoteQualityOptions,
    quoteSizeOptions,
} from '@/lib/quote';
import type {
    GenerationHistoryItem,
    GenerationStateTone,
} from '@/types/generation';

const props = defineProps<{
    item: GenerationHistoryItem;
}>();

const state = computed(() => describeGenerationState(props.item));
const actualCost = computed(() => describeActualCost(props.item));

const badgeVariant = computed(() => {
    const variants: Record<
        GenerationStateTone,
        'default' | 'secondary' | 'destructive' | 'outline'
    > = {
        pending: 'outline',
        active: 'secondary',
        success: 'default',
        error: 'destructive',
    };

    return variants[state.value.tone];
});

const optionsSummary = computed(() => {
    const size =
        quoteSizeOptions.find((option) => option.value === props.item.size)
            ?.label ?? props.item.size;
    const quality =
        quoteQualityOptions.find(
            (option) => option.value === props.item.quality,
        )?.label ?? props.item.quality;
    const references = props.item.reference_images.length;
    const parts = [size, `${quality} quality`];

    if (references > 0) {
        parts.push(
            `${references} reference ${references === 1 ? 'image' : 'images'}`,
        );
    }

    return parts.join(' · ');
});

// The settled amount is displayed exactly as the server recorded it. A
// failure's known zero is its own stored value, never a client-made figure.
const actualAmount = computed(() =>
    props.item.actual_cost_usd === null
        ? null
        : formatUsdAmount(props.item.actual_cost_usd),
);

const createdAt = computed(() => {
    if (props.item.created_at === null) {
        return 'Request time unknown';
    }

    const parsed = new Date(props.item.created_at);

    return Number.isNaN(parsed.getTime())
        ? 'Request time unknown'
        : parsed.toLocaleString();
});
</script>

<template>
    <Card class="w-full">
        <CardHeader>
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="flex min-w-0 flex-col gap-1">
                    <CardTitle class="text-base">Generation request</CardTitle>
                    <CardDescription>
                        {{ createdAt }} · {{ optionsSummary }}
                    </CardDescription>
                </div>
                <Badge :variant="badgeVariant">{{ state.label }}</Badge>
            </div>
        </CardHeader>

        <CardContent class="flex flex-col gap-5">
            <p class="text-sm break-words whitespace-pre-wrap">
                {{ item.prompt }}
            </p>

            <div class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-lg border bg-muted/40 p-3">
                    <p
                        class="text-xs font-medium text-muted-foreground uppercase"
                    >
                        Estimated
                    </p>
                    <p
                        class="mt-1 text-lg font-semibold break-all tabular-nums"
                    >
                        ${{ formatUsdAmount(item.estimated_cost_usd) }}
                    </p>
                    <p class="text-xs text-muted-foreground">USD</p>
                </div>
                <div class="rounded-lg border p-3">
                    <p
                        class="text-xs font-medium text-muted-foreground uppercase"
                    >
                        Maximum
                    </p>
                    <p
                        class="mt-1 text-lg font-semibold break-all tabular-nums"
                    >
                        ${{ formatUsdAmount(item.maximum_cost_usd) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        USD at the quoted rates
                    </p>
                </div>
                <div class="rounded-lg border p-3">
                    <p
                        class="text-xs font-medium text-muted-foreground uppercase"
                    >
                        Actual
                    </p>
                    <p
                        v-if="actualCost.kind === 'pending'"
                        class="mt-1 text-lg font-semibold text-muted-foreground"
                    >
                        {{ actualCost.label }}
                    </p>
                    <p
                        v-else-if="actualAmount !== null"
                        class="mt-1 text-lg font-semibold break-all tabular-nums"
                    >
                        ${{ actualAmount }}
                    </p>
                    <p
                        v-else
                        class="mt-1 text-lg font-semibold text-muted-foreground"
                    >
                        —
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ actualCost.detail }}
                    </p>
                </div>
            </div>

            <p class="text-sm text-muted-foreground">
                {{ state.detail }}
            </p>

            <GenerationResultImage
                :image-url="item.image_url"
                :expires-at="item.image_url_expires_at"
                :provider-status="item.provider_status"
                :alt="`Generated image for: ${item.prompt}`"
            />
        </CardContent>
    </Card>
</template>

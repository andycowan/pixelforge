<script setup lang="ts">
import { computed, ref } from 'vue';
import GenerationResultImage from '@/components/generations/GenerationResultImage.vue';
import { Badge } from '@/components/ui/badge';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { describeActualCost, describeGenerationState } from '@/lib/generation';
import { download } from '@/routes/generations';
import {
    formatUsdAmount,
    qualityLabel,
    referenceImagesLabel,
    sizeLabel,
} from '@/lib/quote';
import type {
    GenerationHistoryItem,
    GenerationStateTone,
} from '@/types/generation';

const props = defineProps<{ item: GenerationHistoryItem }>();

const state = computed(() => describeGenerationState(props.item));
const cost = computed(() => describeActualCost(props.item));
const open = ref(false);

const badgeVariants: Record<
    GenerationStateTone,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    pending: 'outline',
    active: 'secondary',
    success: 'default',
    error: 'destructive',
};

const actualCost = computed(() =>
    cost.value.kind === 'settled' && props.item.actual_cost_usd !== null
        ? `$${formatUsdAmount(props.item.actual_cost_usd)}`
        : cost.value.label,
);
const costSummary = computed(() => {
    switch (cost.value.kind) {
        case 'settled':
            return `Actual ${actualCost.value}`;
        case 'not_charged':
            return cost.value.label;
        default:
            return 'Actual pending';
    }
});
const estimatedCost = computed(
    () => `$${formatUsdAmount(props.item.estimated_cost_usd)}`,
);
const maximumCost = computed(
    () => `$${formatUsdAmount(props.item.maximum_cost_usd)}`,
);

const parameters = computed(() => {
    const references = props.item.reference_images.length;

    return [
        sizeLabel(props.item.size),
        `${qualityLabel(props.item.quality)} quality`,
        references === 0
            ? 'No reference images'
            : referenceImagesLabel(references),
    ].join(' · ');
});

const createdAt = computed(() => {
    const parsed = new Date(props.item.created_at ?? '');

    return Number.isNaN(parsed.getTime())
        ? 'Time unknown'
        : parsed.toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          });
});
</script>

<template>
    <div class="flex min-w-0 flex-col overflow-hidden rounded-lg border">
        <div class="relative">
            <GenerationResultImage
                :image-url="item.image_url"
                :expires-at="item.image_url_expires_at"
                :provider-status="item.provider_status"
                :submission-status="item.submission_status"
                :download-url="download(item.id).url"
                :alt="`Generated image for: ${item.prompt}`"
            />
            <Badge
                :variant="badgeVariants[state.tone]"
                class="absolute top-2 left-2 shadow-sm"
                >{{ state.label }}</Badge
            >
        </div>

        <Tooltip v-model:open="open" :disable-closing-trigger="true">
            <TooltipTrigger as-child>
                <button
                    type="button"
                    :aria-expanded="open"
                    class="flex w-full min-w-0 flex-col gap-0.5 border-t px-3 py-2 text-left hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
                    @click="open = !open"
                >
                    <span class="truncate text-sm">{{ item.prompt }}</span>
                    <span class="text-xs text-muted-foreground tabular-nums"
                        >Est. {{ estimatedCost }} · {{ costSummary }}</span
                    >
                    <time
                        :datetime="item.created_at ?? undefined"
                        class="text-xs text-muted-foreground"
                        >{{ createdAt }}</time
                    >
                </button>
            </TooltipTrigger>
            <TooltipContent
                side="bottom"
                :collision-padding="16"
                class="flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-2 text-sm leading-relaxed"
            >
                <p
                    class="max-h-48 overflow-y-auto break-words whitespace-pre-wrap"
                >
                    {{ item.prompt }}
                </p>
                <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                    <dt class="opacity-70">Status</dt>
                    <dd>{{ state.label }}. {{ state.detail }}</dd>
                    <dt class="opacity-70">Estimated</dt>
                    <dd class="tabular-nums">
                        {{ estimatedCost }} USD (up to {{ maximumCost }})
                    </dd>
                    <dt class="opacity-70">Actual</dt>
                    <dd class="tabular-nums">
                        {{ actualCost
                        }}{{ cost.kind === 'settled' ? ' USD' : '' }}.
                        {{ cost.detail }}
                    </dd>
                    <dt class="opacity-70">Settings</dt>
                    <dd>{{ parameters }}</dd>
                </dl>
            </TooltipContent>
        </Tooltip>
    </div>
</template>

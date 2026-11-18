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
    quoteQualityOptions,
    quoteSizeOptions,
} from '@/lib/quote';
import type {
    GenerationHistoryItem,
    GenerationStateTone,
} from '@/types/generation';

const props = defineProps<{ item: GenerationHistoryItem }>();

const state = computed(() => describeGenerationState(props.item));
const cost = computed(() => describeActualCost(props.item));
const open = ref(false);

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

// The settled amount is shown exactly as the server recorded it, never the quote.
const costText = computed(() =>
    cost.value.kind === 'settled' && props.item.actual_cost_usd !== null
        ? `${cost.value.label} $${formatUsdAmount(props.item.actual_cost_usd)} USD`
        : cost.value.label,
);

const parameters = computed(() => {
    const size =
        quoteSizeOptions.find((option) => option.value === props.item.size)
            ?.label ?? props.item.size;
    const quality =
        quoteQualityOptions.find(
            (option) => option.value === props.item.quality,
        )?.label ?? props.item.quality;
    const references = props.item.reference_images.length;

    return [
        size,
        `${quality} quality`,
        references === 0
            ? 'No reference images'
            : `${references} reference ${references === 1 ? 'image' : 'images'}`,
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
            <!-- A clean library: only unfinished or unsuccessful work carries a badge. -->
            <Badge
                v-if="state.tone !== 'success'"
                :variant="badgeVariant"
                class="absolute top-2 left-2 shadow-sm"
                >{{ state.label }}</Badge
            >
        </div>

        <Tooltip v-model:open="open" :disable-closing-trigger="true">
            <TooltipTrigger as-child>
                <!-- A button, not a hover-only strip: details also open on focus and tap. -->
                <button
                    type="button"
                    :aria-expanded="open"
                    class="flex w-full min-w-0 flex-col gap-0.5 border-t px-3 py-2 text-left hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
                    @click="open = !open"
                >
                    <span class="truncate text-sm">{{ item.prompt }}</span>
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
                    <dt class="opacity-70">Cost</dt>
                    <dd class="tabular-nums">{{ costText }}</dd>
                    <dt class="opacity-70">Settings</dt>
                    <dd>{{ parameters }}</dd>
                    <template v-if="state.tone !== 'success'">
                        <dt class="opacity-70">Status</dt>
                        <dd>{{ state.label }}. {{ state.detail }}</dd>
                    </template>
                </dl>
            </TooltipContent>
        </Tooltip>
    </div>
</template>

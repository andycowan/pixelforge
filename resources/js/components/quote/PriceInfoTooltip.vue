<script setup lang="ts">
import { Info } from '@lucide/vue';
import { ref } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

defineProps<{ label: string }>();
const open = ref(false);
</script>

<template>
    <Tooltip v-model:open="open" :disable-closing-trigger="true">
        <TooltipTrigger as-child>
            <button
                type="button"
                :aria-label="label"
                :aria-expanded="open"
                class="-my-1 inline-flex size-7 shrink-0 items-center justify-center rounded-sm text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                @click="open = !open"
            >
                <Info class="size-3.5" aria-hidden="true" />
            </button>
        </TooltipTrigger>
        <TooltipContent
            side="top"
            :collision-padding="16"
            class="w-72 max-w-[calc(100vw-2rem)] text-sm leading-relaxed text-pretty"
        >
            <slot />
        </TooltipContent>
    </Tooltip>
</template>

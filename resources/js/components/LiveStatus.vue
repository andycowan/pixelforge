<script setup lang="ts">
import { computed } from 'vue';
import type { StreamState } from '@/composables/useGenerationStream';

const props = defineProps<{ state: StreamState; liveLabel: string }>();

const label = computed(
    () =>
        ({
            connecting: 'Connecting to live updates…',
            live: props.liveLabel,
            reconnecting: 'Reconnecting to live updates…',
        })[props.state],
);
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5"
        role="status"
        aria-live="polite"
    >
        <span
            class="size-2 rounded-full"
            :class="
                state === 'live'
                    ? 'bg-emerald-500'
                    : 'animate-pulse bg-amber-500'
            "
            aria-hidden="true"
        />
        {{ label }}
    </span>
</template>

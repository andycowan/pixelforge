<script setup lang="ts">
import { computed, ref } from 'vue';
import { isResultImageExpired } from '@/lib/generation';
import type { ProviderGenerationStatus } from '@/types/generation';

const props = defineProps<{
    imageUrl: string | null;
    expiresAt: string | null;
    providerStatus: ProviderGenerationStatus | null;
    alt: string;
}>();

// Expiry is evaluated against the stored timestamp only. We never request a
// new URL to extend a provider link that has expired.
const expired = computed(() => isResultImageExpired(props.expiresAt));

const loadFailed = ref(false);

const placeholder = computed<{ title: string; detail: string }>(() => {
    if (expired.value) {
        return {
            title: 'Result link expired',
            detail: 'Provider image links last 24 hours. This result is no longer available to download here.',
        };
    }

    if (loadFailed.value) {
        return {
            title: 'Image could not be loaded',
            detail: 'The provider link may have expired or become unavailable. Refresh to check the current state.',
        };
    }

    if (props.providerStatus === 'failed') {
        return {
            title: 'No image',
            detail: 'This generation failed, so the provider returned no image.',
        };
    }

    if (props.providerStatus === 'succeeded') {
        return {
            title: 'No result image',
            detail: 'The provider reported success without a usable image link.',
        };
    }

    return {
        title: 'Result will appear here',
        detail: 'The image is shown once the provider has finished and its status has been recorded.',
    };
});
</script>

<template>
    <div
        class="flex aspect-square w-full items-center justify-center overflow-hidden rounded-lg border bg-muted/40"
    >
        <img
            v-if="imageUrl !== null && !expired && !loadFailed"
            :src="imageUrl"
            :alt="alt"
            loading="lazy"
            decoding="async"
            class="size-full object-contain"
            @error="loadFailed = true"
        />
        <div v-else class="flex flex-col gap-1 p-4 text-center">
            <p class="text-sm font-medium">{{ placeholder.title }}</p>
            <p class="text-xs text-muted-foreground">
                {{ placeholder.detail }}
            </p>
        </div>
    </div>
</template>

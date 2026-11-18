<script setup lang="ts">
import { Download } from '@lucide/vue';
import { computed, ref } from 'vue';
import { isResultImageExpired } from '@/lib/generation';
import type {
    GenerationSubmissionStatus,
    ProviderGenerationStatus,
} from '@/types/generation';

const props = defineProps<{
    imageUrl: string | null;
    expiresAt: string | null;
    providerStatus: ProviderGenerationStatus | null;
    submissionStatus: GenerationSubmissionStatus;
    alt: string;
    downloadUrl?: string;
}>();

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

    if (props.submissionStatus === 'rejected') {
        return {
            title: 'No image',
            detail: 'The provider did not accept this request, so nothing was generated.',
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
        class="flex aspect-square w-full items-center justify-center overflow-hidden bg-muted/40"
    >
        <a
            v-if="imageUrl !== null && !expired && !loadFailed && downloadUrl"
            :href="downloadUrl"
            download
            aria-label="Download image"
            class="group relative block size-full focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
        >
            <img
                :src="imageUrl"
                :alt="alt"
                loading="lazy"
                decoding="async"
                class="size-full object-contain"
                @error="loadFailed = true"
            />
            <span
                class="pointer-events-none absolute right-2 bottom-2 inline-flex items-center gap-1 rounded-md bg-background/90 px-2 py-1 text-xs font-medium opacity-0 shadow-sm transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100"
                aria-hidden="true"
            >
                <Download class="size-3.5" />
                Download
            </span>
        </a>
        <img
            v-else-if="imageUrl !== null && !expired && !loadFailed"
            :src="imageUrl"
            :alt="alt"
            loading="lazy"
            decoding="async"
            class="size-full object-contain"
            @error="loadFailed = true"
        />
        <div v-else class="flex flex-col gap-1 p-4 text-center">
            <p class="text-sm font-medium">{{ placeholder.title }}</p>
            <p class="line-clamp-4 text-xs text-muted-foreground">
                {{ placeholder.detail }}
            </p>
        </div>
    </div>
</template>

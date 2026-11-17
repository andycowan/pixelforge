<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import QuoteForm from '@/components/quote/QuoteForm.vue';
import QuotePricePreview from '@/components/quote/QuotePricePreview.vue';
import { useQuotePreview } from '@/composables/useQuotePreview';
import { dashboard } from '@/routes';
import type { QuoteFormInput } from '@/types/quote';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Workspace',
                href: dashboard(),
            },
        ],
    },
});

const input = ref<QuoteFormInput>({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    referenceUrls: '',
});

const { quote, errors, serverFieldErrors, failure, status, retry } =
    useQuotePreview(input);
</script>

<template>
    <Head title="Workspace" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            class="mb-0!"
            title="Workspace"
            description="Price an image generation against live provider rates. Image generation submission is not available yet."
        />

        <div class="grid w-full items-start gap-6 xl:grid-cols-2">
            <QuoteForm
                v-model="input"
                :errors="errors"
                :server-field-errors="serverFieldErrors"
            />

            <QuotePricePreview
                :status="status"
                :quote="quote"
                :failure="failure"
                :input="input"
                @retry="retry"
            />
        </div>
    </div>
</template>

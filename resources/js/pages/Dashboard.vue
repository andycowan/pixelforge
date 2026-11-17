<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import QuoteForm from '@/components/quote/QuoteForm.vue';
import QuotePricePreview from '@/components/quote/QuotePricePreview.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { useGenerationRequest } from '@/composables/useGenerationRequest';
import { useQuotePreview } from '@/composables/useQuotePreview';
import { formatSignedUsd, formatUsdAmount } from '@/lib/quote';
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

const props = defineProps<{
    /** Server-side configuration only; the key itself is never sent. */
    generation_enabled: boolean;
}>();

const page = usePage();
const balance = computed(() => page.props.credits);

const input = ref<QuoteFormInput>({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    referenceUrls: '',
});

const { quote, errors, serverFieldErrors, failure, status, retry } =
    useQuotePreview(input);

const unaffordable = computed(
    () => status.value === 'ready' && quote.value?.affordable === false,
);

const canGenerate = computed(
    () =>
        props.generation_enabled &&
        status.value === 'ready' &&
        quote.value !== null &&
        quote.value.affordable,
);

const { submit, submitting, submissionError, fieldErrors, creditsRejected } =
    useGenerationRequest(input, canGenerate);

const mergedServerFieldErrors = computed(() => ({
    ...serverFieldErrors.value,
    ...fieldErrors.value,
}));

const generateHint = computed<string | null>(() => {
    if (submitting.value) {
        return 'Sending your request. Editing is locked until the server answers.';
    }

    if (!props.generation_enabled) {
        return 'Generation is disabled because this server has no provider API key configured. Price previews still work.';
    }

    if (unaffordable.value && quote.value !== null) {
        return `The maximum price of $${formatUsdAmount(quote.value.maximum_cost_usd)} exceeds your available balance${balance.value ? ` of ${formatSignedUsd(balance.value.available_usd)}` : ''}. Buy credits to continue.`;
    }

    switch (status.value) {
        case 'ready':
            return null;
        case 'pending':
            return 'Waiting for the current price before sending.';
        case 'error':
            return 'A current price is required before generating. Retry the price preview.';
        case 'invalid':
            return 'Fix the highlighted fields to generate.';
        default:
            return 'Add a prompt to generate.';
    }
});
</script>

<template>
    <Head title="Workspace" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            class="mb-0!"
            title="Workspace"
            description="Describe an image and review its estimated price. Generating stores this request on the server, then submits it to the provider in the background."
        />

        <Alert v-if="!generation_enabled">
            <AlertTitle>Image generation is unavailable</AlertTitle>
            <AlertDescription>
                This server has no provider API key configured, so requests
                cannot be submitted. Price previews still work and nothing is
                charged.
            </AlertDescription>
        </Alert>

        <div class="grid w-full items-start gap-6 xl:grid-cols-2">
            <QuoteForm
                v-model="input"
                :errors="errors"
                :server-field-errors="mergedServerFieldErrors"
                :can-generate="canGenerate"
                :submitting="submitting"
                :generate-hint="generateHint"
                :generate-error="submissionError"
                :available-usd="balance?.available_usd ?? null"
                :needs-credits="unaffordable || creditsRejected"
                @generate="submit"
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

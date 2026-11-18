import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import {
    buildGenerationPayload,
    createSubmissionToken,
    generationOptionsKey,
    readSubmissionError,
    submissionHttpExceptionMessage,
} from '@/lib/generation';
import { quoteInputKey, validateQuoteInput } from '@/lib/quote';
import { store } from '@/routes/generations';
import type { GenerationRequestPayload } from '@/types/generation';
import type { QuoteFieldErrors, QuoteFormInput } from '@/types/quote';

export type UseGenerationRequest = {
    submit: () => void;
    submitting: ComputedRef<boolean>;
    submissionError: Ref<string | null>;
    fieldErrors: ComputedRef<QuoteFieldErrors>;
    creditsRejected: ComputedRef<boolean>;
};

const GENERATION_FIELDS = [
    'prompt',
    'size',
    'quality',
    'reference_images',
] as const;

const REJECTED_MESSAGE =
    'Some details were rejected before a generation request was created. Fix the highlighted fields and try again.';

export function useGenerationRequest(
    input: Ref<QuoteFormInput>,
    canGenerate: ComputedRef<boolean>,
): UseGenerationRequest {
    const form = useForm<GenerationRequestPayload>({
        prompt: '',
        size: '1024x1024',
        quality: 'medium',
        reference_images: [],
        submission_token: '',
    });

    const submissionError = ref<string | null>(null);

    let token: string | null = null;
    let tokenOptionsKey: string | null = null;

    const submitting = computed(() => form.processing);

    const fieldErrors = computed<QuoteFieldErrors>(() => {
        const errors = form.errors as Record<string, string | undefined>;
        const mapped: QuoteFieldErrors = {};

        for (const field of GENERATION_FIELDS) {
            const message = errors[field];

            if (message !== undefined && message !== '') {
                mapped[field] = message;
            }
        }

        return mapped;
    });

    watch(
        () => quoteInputKey(input.value),
        () => {
            submissionError.value = null;
            form.clearErrors();
        },
    );

    function submit(): void {
        if (!canGenerate.value || form.processing) {
            return;
        }

        const request = validateQuoteInput(input.value).request;

        if (request === null) {
            return;
        }

        const optionsKey = generationOptionsKey(request);

        if (token === null || tokenOptionsKey !== optionsKey) {
            token = createSubmissionToken();
            tokenOptionsKey = optionsKey;
        }

        const payload = buildGenerationPayload(request, token);

        submissionError.value = null;
        form.transform(() => payload);
        form.post(store.url(), {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => {
                submissionError.value =
                    readSubmissionError(errors as Record<string, unknown>) ??
                    REJECTED_MESSAGE;
            },
            onHttpException: (response) => {
                submissionError.value = submissionHttpExceptionMessage(
                    response.status,
                );

                return false;
            },
            onNetworkError: () => {
                submissionError.value =
                    "We couldn't reach the server, so the request was not confirmed. Nothing is charged until a result is settled. Your prompt is preserved; retrying reuses the same request.";

                return false;
            },
        });
    }

    const creditsRejected = computed(
        () =>
            (form.errors as Record<string, string | undefined>).credits !==
            undefined,
    );

    return {
        submit,
        submitting,
        submissionError,
        fieldErrors,
        creditsRejected,
    };
}

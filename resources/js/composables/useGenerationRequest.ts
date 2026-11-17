/**
 * Inertia-owned generation submission.
 *
 * The server re-quotes and freezes the request; this composable only holds the
 * browser's deduplication identity, blocks duplicate submissions and turns the
 * server's answers into visible form state.
 */
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
import { validateQuoteInput } from '@/lib/quote';
import { store } from '@/routes/generations';
import type { GenerationRequestPayload } from '@/types/generation';
import type { QuoteFieldErrors, QuoteFormInput } from '@/types/quote';

export type UseGenerationRequest = {
    submit: () => void;
    submitting: ComputedRef<boolean>;
    /** Form-level failure message; field messages stay with their controls. */
    submissionError: Ref<string | null>;
    /** Server field messages from a rejected generation request. */
    fieldErrors: ComputedRef<QuoteFieldErrors>;
    /** The server rejected the last request for insufficient credit. */
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

    /**
     * One token for one exact set of options. Retrying an unchanged submission
     * reuses it so the server recognises the original; changing an option and
     * submitting starts a new request with a fresh token.
     */
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

    // Editing invalidates messages about the previous attempt, but the token
    // is kept until the options actually change at the next submission.
    watch(
        () =>
            JSON.stringify([
                input.value.prompt,
                input.value.size,
                input.value.quality,
                input.value.referenceUrls,
            ]),
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

        // The submitted snapshot is frozen here: controls are also disabled
        // while the request is in flight.
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

                // Handled inline; the page is not being replaced.
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

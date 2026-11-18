import { computed, onScopeDispose, ref, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import {
    QUOTE_DEBOUNCE_MS,
    QUOTE_REQUEST_TIMEOUT_MS,
    flattenValidationErrors,
    isQuotePreview,
    readErrorMessage,
    readRetryAfter,
    quoteInputKey,
    readXsrfToken,
    validateQuoteInput,
} from '@/lib/quote';
import { quote as quoteEndpoint } from '@/routes/generations';
import type {
    QuoteFailure,
    QuoteFieldErrors,
    QuoteFormInput,
    QuotePreview,
    QuoteRequest,
    QuoteStatus,
} from '@/types/quote';

export type UseQuotePreview = {
    quote: Ref<QuotePreview | null>;
    errors: ComputedRef<QuoteFieldErrors>;
    serverFieldErrors: Ref<QuoteFieldErrors>;
    failure: Ref<QuoteFailure | null>;
    status: ComputedRef<QuoteStatus>;
    retry: () => void;
};

const SESSION_FAILURE_MESSAGE =
    'Your session expired, so live pricing could not be fetched. Sign in again in another tab, then retry — your prompt stays here.';

const UNEXPECTED_RESPONSE_MESSAGE =
    'The pricing service returned an unexpected response. Nothing was created or charged. Try again.';

export function useQuotePreview(input: Ref<QuoteFormInput>): UseQuotePreview {
    const quote = ref<QuotePreview | null>(null);
    const serverFieldErrors = ref<QuoteFieldErrors>({});
    const failure = ref<QuoteFailure | null>(null);
    const pending = ref(false);

    const validation = computed(() => validateQuoteInput(input.value));

    const errors = computed<QuoteFieldErrors>(() => ({
        ...validation.value.fieldErrors,
        ...serverFieldErrors.value,
    }));

    const status = computed<QuoteStatus>(() => {
        if (validation.value.promptBlank) {
            return 'idle';
        }

        if (pending.value) {
            return 'pending';
        }

        if (quote.value !== null) {
            return 'ready';
        }

        if (Object.keys(errors.value).length > 0) {
            return 'invalid';
        }

        if (failure.value !== null) {
            return 'error';
        }

        return 'idle';
    });

    let timer: ReturnType<typeof setTimeout> | null = null;
    let inflight: AbortController | null = null;
    let requestVersion = 0;
    let disposed = false;

    function clearTimer(): void {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    }

    function abortInflight(): void {
        if (inflight !== null) {
            inflight.abort();
            inflight = null;
        }
    }

    function clearResult(): void {
        quote.value = null;
        failure.value = null;
        serverFieldErrors.value = {};
    }

    function scheduleRequest(delay: number): void {
        clearTimer();
        abortInflight();

        const version = ++requestVersion;
        const request = validation.value.request;

        if (request === null) {
            pending.value = false;

            return;
        }

        pending.value = true;
        timer = setTimeout(() => {
            timer = null;
            void send(request, version);
        }, delay);
    }

    function retry(): void {
        if (disposed) {
            return;
        }

        clearResult();
        scheduleRequest(0);
    }

    function csrfHeader(): Record<string, string> {
        if (typeof document === 'undefined') {
            return {};
        }

        const token = readXsrfToken(document.cookie);

        return token === null ? {} : { 'X-XSRF-TOKEN': token };
    }

    async function readJson(response: Response): Promise<unknown> {
        try {
            return await response.json();
        } catch {
            return null;
        }
    }

    function applyFailure(candidate: QuoteFailure): void {
        failure.value = candidate;
        quote.value = null;
    }

    function handleErrorResponse(response: Response, payload: unknown): void {
        if (response.status === 422) {
            const { fieldErrors, generalMessages } =
                flattenValidationErrors(payload);

            serverFieldErrors.value = fieldErrors;
            applyFailure({
                kind: 'validation',
                message:
                    generalMessages.length > 0
                        ? generalMessages.join(' ')
                        : 'Some details were rejected. Fix the highlighted fields and the price will refresh.',
                retryable: false,
            });

            return;
        }

        if (response.status === 401 || response.status === 419) {
            applyFailure({
                kind: 'session',
                message: SESSION_FAILURE_MESSAGE,
                retryable: true,
            });

            return;
        }

        if (response.status === 429) {
            const retryAfter = readRetryAfter(
                response.headers.get('Retry-After'),
            );

            applyFailure({
                kind: 'throttled',
                message:
                    retryAfter === null
                        ? 'Too many price requests. Wait a moment, then try again.'
                        : `Too many price requests. Try again in about ${retryAfter} seconds.`,
                retryable: true,
            });

            return;
        }

        if (response.status === 503) {
            applyFailure({
                kind: 'unavailable',
                message:
                    'Live pricing is temporarily unavailable. Nothing was created or charged. Try again shortly.',
                retryable: true,
            });

            return;
        }

        applyFailure({
            kind: 'unknown',
            message:
                (response.status < 500 ? readErrorMessage(payload) : null) ??
                'The price could not be fetched. Nothing was created or charged. Try again.',
            retryable: response.status >= 500,
        });
    }

    async function send(request: QuoteRequest, version: number): Promise<void> {
        abortInflight();

        const controller = new AbortController();
        inflight = controller;
        const timeout = setTimeout(
            () => controller.abort(),
            QUOTE_REQUEST_TIMEOUT_MS,
        );

        try {
            const response = await fetch(quoteEndpoint.url(), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    ...csrfHeader(),
                },
                body: JSON.stringify(request),
                credentials: 'same-origin',
                signal: controller.signal,
            });

            const payload = await readJson(response);

            if (disposed || version !== requestVersion) {
                return;
            }

            if (response.ok) {
                if (isQuotePreview(payload)) {
                    serverFieldErrors.value = {};
                    failure.value = null;
                    quote.value = payload;
                } else {
                    applyFailure({
                        kind: 'unknown',
                        message: UNEXPECTED_RESPONSE_MESSAGE,
                        retryable: true,
                    });
                }

                return;
            }

            handleErrorResponse(response, payload);
        } catch {
            if (disposed || version !== requestVersion) {
                return;
            }

            applyFailure({
                kind: 'network',
                message:
                    "We couldn't reach the pricing service. Check your connection and try again — your prompt is preserved.",
                retryable: true,
            });
        } finally {
            clearTimeout(timeout);
            if (inflight === controller) {
                inflight = null;
            }

            if (!disposed && version === requestVersion) {
                pending.value = false;
            }
        }
    }

    watch(
        () => quoteInputKey(input.value),
        () => {
            if (disposed) {
                return;
            }

            clearResult();
            scheduleRequest(QUOTE_DEBOUNCE_MS);
        },
        { immediate: true, flush: 'sync' },
    );

    onScopeDispose(() => {
        disposed = true;
        requestVersion += 1;
        clearTimer();
        abortInflight();
    });

    return {
        quote,
        errors,
        serverFieldErrors,
        failure,
        status,
        retry,
    };
}

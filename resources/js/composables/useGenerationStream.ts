import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { reconnectDelay } from '@/lib/generationStream';

export type StreamState = 'connecting' | 'live' | 'reconnecting';

/**
 * Subscribe to the user's private generation updates and refresh the given
 * authoritative props (e.g. `generations`, `entries`, `credits`) whenever
 * anything may have changed.
 *
 * Messages are invalidations only. Every (re)connect also refreshes, closing
 * the gap between page load and subscription and repairing missed messages.
 * A closed stream (expired cookie, hub restart, 429) is reopened after a prop
 * reload, which also renews the server-issued cookie.
 */
export function useGenerationStream(
    hub: string,
    topic: string,
    only: string[],
) {
    const state = ref<StreamState>('connecting');

    let source: EventSource | null = null;
    let retryTimer: ReturnType<typeof setTimeout> | null = null;
    let failures = 0;
    let reloading = false;
    let reloadAgain = false;
    let disposed = false;

    function refresh(): void {
        if (reloading) {
            reloadAgain = true; // Coalesce bursts into one follow-up reload.

            return;
        }
        reloading = true;
        router.reload({
            only,
            onFinish: () => {
                reloading = false;
                if (reloadAgain && !disposed) {
                    reloadAgain = false;
                    refresh();
                }
            },
        });
    }

    function connect(): void {
        if (disposed) {
            return;
        }
        const url = `${hub}?match=${encodeURIComponent(topic)}`;
        source = new EventSource(url);
        source.onopen = () => {
            failures = 0;
            state.value = 'live';
            refresh();
        };
        source.onmessage = () => refresh();
        source.onerror = () => {
            if (source?.readyState === EventSource.CONNECTING) {
                state.value = 'reconnecting'; // The browser retries network drops itself.

                return;
            }
            // Non-200 responses close the stream for good: renew the cookie, then reopen.
            source?.close();
            source = null;
            state.value = 'reconnecting';
            retryTimer = setTimeout(
                () => router.reload({ only, onFinish: connect }),
                reconnectDelay(failures++),
            );
        };
    }

    onMounted(connect);

    onBeforeUnmount(() => {
        disposed = true;
        source?.close();
        if (retryTimer !== null) {
            clearTimeout(retryTimer);
        }
    });

    return { state };
}

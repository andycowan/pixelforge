import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { reconnectDelay } from '@/lib/generationStream';

export type StreamState = 'connecting' | 'live' | 'reconnecting';

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
            reloadAgain = true;

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
                state.value = 'reconnecting';

                return;
            }
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

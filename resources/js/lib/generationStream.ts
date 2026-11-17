/** Capped exponential backoff for reopening a closed live-update stream. */
export function reconnectDelay(failures: number): number {
    return Math.min(30_000, 1_000 * 2 ** Math.max(0, failures));
}

<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import GenerationCard from '@/components/generations/GenerationCard.vue';
import GenerationPagination from '@/components/generations/GenerationPagination.vue';
import { useGenerationStream } from '@/composables/useGenerationStream';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { index as generationIndex } from '@/routes/generations';
import { dashboard } from '@/routes';
import type { GenerationPaginator } from '@/types/generation';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generations', href: generationIndex() }],
    },
});

const props = defineProps<{
    /** Owned, paginated history with the model's safe public projection. */
    generations: GenerationPaginator;
    /** Same-origin hub path and this user's private topic. */
    live: { hub: string; topic: string };
}>();

const { state: streamState } = useGenerationStream(
    props.live.hub,
    props.live.topic,
);

const streamLabel = computed(
    () =>
        ({
            connecting: 'Connecting to live updates…',
            live: 'Live: statuses update automatically',
            reconnecting: 'Reconnecting… use Refresh meanwhile',
        })[streamState.value],
);

const refreshing = ref(false);

/** Manual fallback while the live stream is connecting or reconnecting. */
function refresh(): void {
    router.reload({
        only: ['generations', 'credits'],
        onStart: () => {
            refreshing.value = true;
        },
        onFinish: () => {
            refreshing.value = false;
        },
    });
}

const hasItems = computed(() => props.generations.data.length > 0);
</script>

<template>
    <Head title="Generations" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                class="mb-0!"
                title="Generations"
                description="Your requests with their real submission, provider and settlement state."
            />
            <div class="flex items-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="refreshing"
                    @click="refresh"
                >
                    {{ refreshing ? 'Refreshing…' : 'Refresh' }}
                </Button>
                <Button as-child variant="secondary" size="sm">
                    <Link :href="dashboard()">New generation</Link>
                </Button>
            </div>
        </div>

        <p
            class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
        >
            <span
                class="inline-flex items-center gap-1.5"
                role="status"
                aria-live="polite"
            >
                <span
                    class="size-2 rounded-full"
                    :class="
                        streamState === 'live'
                            ? 'bg-emerald-500'
                            : 'animate-pulse bg-amber-500'
                    "
                    aria-hidden="true"
                />
                {{ streamLabel }}
            </span>
            <span
                >Estimated and actual amounts are exact USD strings calculated
                and settled by the server.</span
            >
        </p>

        <Card v-if="!hasItems" class="w-full">
            <CardHeader>
                <CardTitle>No generations yet</CardTitle>
                <CardDescription>
                    Price a prompt, then generate it from the workspace. Your
                    own requests will appear here with their exact charges.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Button as-child>
                    <Link :href="dashboard()">Open the workspace</Link>
                </Button>
            </CardContent>
        </Card>

        <template v-else>
            <div class="flex flex-col gap-4">
                <GenerationCard
                    v-for="item in generations.data"
                    :key="item.id"
                    :item="item"
                />
            </div>

            <GenerationPagination :paginator="generations" />
        </template>
    </div>
</template>

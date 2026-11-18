<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Search, X } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import GenerationTile from '@/components/generations/GenerationTile.vue';
import GenerationPagination from '@/components/generations/GenerationPagination.vue';
import LiveStatus from '@/components/LiveStatus.vue';
import { useGenerationStream } from '@/composables/useGenerationStream';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
    /** Current prompt search, as applied by the server. */
    search: string | null;
    /** Same-origin hub path and this user's private topic. */
    live: { hub: string; topic: string };
}>();

const { state: streamState } = useGenerationStream(
    props.live.hub,
    props.live.topic,
    ['generations', 'credits'],
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

// Prompt search runs on the server so pagination and live reloads keep the filter.
const query = ref(props.search ?? '');
let searchTimer: ReturnType<typeof setTimeout> | null = null;

function applySearch(): void {
    const search = query.value.trim();

    router.get(generationIndex().url, search === '' ? {} : { search }, {
        only: ['generations', 'search'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function onSearchInput(): void {
    if (searchTimer !== null) clearTimeout(searchTimer);
    searchTimer = setTimeout(applySearch, 300);
}

function clearSearch(): void {
    query.value = '';
    if (searchTimer !== null) clearTimeout(searchTimer);
    applySearch();
}

onBeforeUnmount(() => {
    if (searchTimer !== null) clearTimeout(searchTimer);
});
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
            <LiveStatus
                :state="streamState"
                live-label="Live: statuses update automatically"
            />
            <span
                >Hover over or tap a caption for the full prompt, cost and
                settings.</span
            >
        </p>

        <div class="relative w-full sm:max-w-sm" role="search">
            <Search
                class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <Input
                v-model="query"
                type="search"
                aria-label="Search prompts"
                placeholder="Search prompts"
                maxlength="200"
                class="pr-9 pl-8 [&::-webkit-search-cancel-button]:hidden"
                @input="onSearchInput"
                @keydown.enter.prevent="applySearch"
            />
            <button
                v-if="query !== ''"
                type="button"
                aria-label="Clear search"
                class="absolute top-1/2 right-1.5 inline-flex size-6 -translate-y-1/2 items-center justify-center rounded-sm text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                @click="clearSearch"
            >
                <X class="size-4" aria-hidden="true" />
            </button>
        </div>

        <p
            v-if="!hasItems && search"
            class="text-sm text-muted-foreground"
            role="status"
        >
            No generations match “{{ search }}”.
        </p>

        <Card v-else-if="!hasItems" class="w-full">
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
            <ul
                class="grid grid-cols-1 gap-4 min-[420px]:grid-cols-2 md:grid-cols-3 xl:grid-cols-4"
            >
                <li v-for="item in generations.data" :key="item.id">
                    <GenerationTile :item="item" />
                </li>
            </ul>

            <GenerationPagination :paginator="generations" />
        </template>
    </div>
</template>

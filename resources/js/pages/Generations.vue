<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { index as generations } from '@/routes/generations';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generations', href: generations() }],
    },
});
</script>

<template>
    <Head title="Generations" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            class="mb-0!"
            title="Generations"
            description="Your image generations, results and charges will appear here."
        />

        <Card class="w-full">
            <CardHeader>
                <CardTitle>Generation history is not available yet</CardTitle>
                <CardDescription>
                    Image generation submission is coming next.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <p class="text-sm text-muted-foreground">
                    Past generations, their exact charges and results will
                    appear here once submission, processing and settlement are
                    connected. Price previews do not create generations, reserve
                    credit or submit work to the provider.
                </p>
            </CardContent>
        </Card>
    </div>
</template>

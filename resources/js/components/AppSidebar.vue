<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChartColumn, Coins, History, LayoutGrid } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { formatSignedUsd } from '@/lib/quote';
import { dashboard } from '@/routes';
import { report as adminReport } from '@/routes/admin';
import { index as credits } from '@/routes/credits';
import { index as generations } from '@/routes/generations';
import type { NavItem } from '@/types';

const page = usePage();
const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Workspace',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Generations',
        href: generations(),
        icon: History,
    },
    {
        title: 'Credits',
        href: credits(),
        icon: Coins,
        badge: page.props.credits
            ? formatSignedUsd(page.props.credits.available_usd)
            : undefined,
    },
    // Operators only; the route itself is also gated server-side.
    ...(page.props.auth.user?.is_admin
        ? [{ title: 'Operator report', href: adminReport(), icon: ChartColumn }]
        : []),
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>

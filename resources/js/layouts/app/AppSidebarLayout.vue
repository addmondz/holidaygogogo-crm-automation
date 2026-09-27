<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import RealtimeNotifications from '@/components/RealtimeNotifications.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
    // Chat-style pages fill the screen and scroll inside their own panels.
    fullHeight?: boolean;
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
    fullHeight: false,
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent
            variant="sidebar"
            :class="
                fullHeight
                    ? 'h-svh min-w-0 overflow-hidden md:h-[calc(100svh-1rem)]'
                    : 'min-w-0 overflow-x-clip'
            "
        >
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <slot />
        </AppContent>
        <Toaster rich-colors close-button />
        <RealtimeNotifications />
    </AppShell>
</template>

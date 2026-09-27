<script setup lang="ts">
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [], fullHeight = false } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
    fullHeight?: boolean;
}>();
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs" :full-height="fullHeight">
        <slot />
    </AppLayout>
</template>

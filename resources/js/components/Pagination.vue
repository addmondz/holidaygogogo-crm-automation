<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/types';

defineProps<{ paginator: Paginated<unknown>; label?: string }>();
</script>

<template>
    <div
        v-if="paginator.last_page > 1 || paginator.total > 0"
        class="flex items-center justify-between gap-4 text-sm text-muted-foreground"
    >
        <span>
            {{ paginator.from ?? 0 }}–{{ paginator.to ?? 0 }} of
            {{ paginator.total }} {{ label ?? '' }}
        </span>
        <div class="flex gap-2">
            <Button
                as-child
                variant="outline"
                size="sm"
                :disabled="!paginator.prev_page_url"
            >
                <Link
                    v-if="paginator.prev_page_url"
                    :href="paginator.prev_page_url"
                    preserve-scroll
                    ><ChevronLeft /> Previous</Link
                >
                <span v-else class="pointer-events-none opacity-50"
                    ><ChevronLeft /> Previous</span
                >
            </Button>
            <Button
                as-child
                variant="outline"
                size="sm"
                :disabled="!paginator.next_page_url"
            >
                <Link
                    v-if="paginator.next_page_url"
                    :href="paginator.next_page_url"
                    preserve-scroll
                    >Next <ChevronRight
                /></Link>
                <span v-else class="pointer-events-none opacity-50"
                    >Next <ChevronRight
                /></span>
            </Button>
        </div>
    </div>
</template>

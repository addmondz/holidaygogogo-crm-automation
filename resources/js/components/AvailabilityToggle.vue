<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AvailabilityController from '@/actions/App/Http/Controllers/AvailabilityController';
import { Switch } from '@/components/ui/switch';

const page = usePage();
const available = computed(() => page.props.auth.user.is_available);

function toggle(value: boolean) {
    router.patch(
        AvailabilityController.url(),
        { is_available: value },
        { preserveScroll: true, preserveState: true },
    );
}
</script>

<template>
    <label
        class="flex cursor-pointer items-center justify-between gap-2 rounded-md px-2 py-1.5 text-xs text-sidebar-foreground/80 group-data-[collapsible=icon]:hidden"
        title="When on, new leads can be assigned to you automatically"
    >
        <span class="flex items-center gap-2">
            <span
                class="size-2 rounded-full"
                :class="available ? 'bg-green-500' : 'bg-neutral-400'"
            />
            {{ available ? 'Available for new leads' : 'Not taking new leads' }}
        </span>
        <Switch :model-value="available" @update:model-value="toggle" />
    </label>
</template>

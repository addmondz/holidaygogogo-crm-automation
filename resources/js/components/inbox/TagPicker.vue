<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import ContactController from '@/actions/App/Http/Controllers/Contacts/ContactController';
import TagBadge from '@/components/inbox/TagBadge.vue';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { tagDotClasses } from '@/lib/tags';
import type { Tag } from '@/types';

const props = defineProps<{
    contactId: number;
    selected: Tag[];
    tags: Tag[];
}>();

const search = ref('');
const selectedIds = computed(() => props.selected.map((tag) => tag.id));
const filtered = computed(() =>
    props.tags.filter((tag) =>
        tag.name.toLowerCase().includes(search.value.toLowerCase()),
    ),
);

function save(ids: number[]): void {
    router.put(
        ContactController.tags(props.contactId).url,
        { tag_ids: ids },
        { preserveScroll: true, preserveState: true },
    );
}

function toggle(tag: Tag): void {
    const ids = selectedIds.value.includes(tag.id)
        ? selectedIds.value.filter((id) => id !== tag.id)
        : [...selectedIds.value, tag.id];

    save(ids);
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-1.5">
        <TagBadge
            v-for="tag in selected"
            :key="tag.id"
            :tag="tag"
            removable
            @remove="toggle(tag)"
        />

        <Popover>
            <PopoverTrigger as-child>
                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-full border border-dashed px-2 py-0.5 text-xs text-muted-foreground hover:border-solid hover:text-foreground"
                >
                    <Plus class="size-3" /> Tag
                </button>
            </PopoverTrigger>
            <PopoverContent align="start" class="w-60 p-2">
                <Input
                    v-model="search"
                    placeholder="Find a tag"
                    class="mb-2 h-8"
                />
                <div class="max-h-60 overflow-y-auto">
                    <button
                        v-for="tag in filtered"
                        :key="tag.id"
                        type="button"
                        class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-accent"
                        @click="toggle(tag)"
                    >
                        <span
                            class="size-2.5 rounded-full"
                            :class="tagDotClasses[tag.color]"
                        />
                        <span class="flex-1 truncate">{{ tag.name }}</span>
                        <Check
                            v-if="selectedIds.includes(tag.id)"
                            class="size-4"
                        />
                    </button>
                    <p
                        v-if="!filtered.length"
                        class="px-2 py-3 text-center text-xs text-muted-foreground"
                    >
                        No tags found. Admins can create tags under Admin →
                        Tags.
                    </p>
                </div>
            </PopoverContent>
        </Popover>
    </div>
</template>

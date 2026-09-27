<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, Wand2 } from '@lucide/vue';
import { ref } from 'vue';
import TagController from '@/actions/App/Http/Controllers/Admin/TagController';
import Heading from '@/components/Heading.vue';
import TagBadge from '@/components/inbox/TagBadge.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { tagDotClasses } from '@/lib/tags';
import type { Tag } from '@/types';

type TagRow = Tag & { keywords: string[]; contacts_count: number };

defineProps<{ tags: TagRow[]; colors: string[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Tags', href: TagController.index() }],
    },
});

const dialogOpen = ref(false);
const editing = ref<TagRow | null>(null);
const deleting = ref<TagRow | null>(null);

const form = useForm({ name: '', color: 'blue', keywords: '' });

function openCreate(): void {
    editing.value = null;
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

function openEdit(tag: TagRow): void {
    editing.value = tag;
    form.clearErrors();
    form.name = tag.name;
    form.color = tag.color;
    form.keywords = tag.keywords.join(', ');
    dialogOpen.value = true;
}

function submit(): void {
    form.submit(
        editing.value
            ? TagController.update(editing.value.id)
            : TagController.store(),
        {
            preserveScroll: true,
            onSuccess: () => (dialogOpen.value = false),
        },
    );
}

function confirmDelete(): void {
    if (deleting.value) {
        router.delete(TagController.destroy(deleting.value.id).url, {
            preserveScroll: true,
            onFinish: () => (deleting.value = null),
        });
    }
}
</script>

<template>
    <Head title="Tags" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Tags"
                description="Label leads (e.g. Japan Tour, Hot Lead, Deposit Paid) to filter the inbox and choose who gets a blast."
            />
            <Button @click="openCreate"><Plus /> New tag</Button>
        </div>

        <div class="overflow-x-auto rounded-xl border bg-card">
            <table class="w-full text-sm">
                <thead class="text-left text-muted-foreground">
                    <tr class="border-b">
                        <th class="px-4 py-3 font-medium">Tag</th>
                        <th class="px-4 py-3 font-medium">Auto-tag keywords</th>
                        <th class="px-4 py-3 font-medium">Contacts</th>
                        <th class="px-4 py-3" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!tags.length">
                        <td
                            colspan="4"
                            class="px-4 py-12 text-center text-muted-foreground"
                        >
                            No tags yet.
                        </td>
                    </tr>
                    <tr
                        v-for="tag in tags"
                        :key="tag.id"
                        class="border-b last:border-0"
                    >
                        <td class="px-4 py-3"><TagBadge :tag="tag" /></td>
                        <td class="px-4 py-3 text-muted-foreground">
                            <span
                                v-if="tag.keywords.length"
                                class="inline-flex items-center gap-1.5"
                            >
                                <Wand2 class="size-3.5" />
                                {{ tag.keywords.join(', ') }}
                            </span>
                            <span v-else>—</span>
                        </td>
                        <td class="px-4 py-3 tabular-nums">
                            {{ tag.contacts_count }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Edit ${tag.name}`"
                                    @click="openEdit(tag)"
                                >
                                    <Pencil />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="text-destructive hover:text-destructive"
                                    :aria-label="`Delete ${tag.name}`"
                                    @click="deleting = tag"
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <Dialog v-model:open="dialogOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{
                    editing ? 'Edit tag' : 'New tag'
                }}</DialogTitle>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="tag-name">Name</Label>
                    <Input
                        id="tag-name"
                        v-model="form.name"
                        placeholder="Japan Tour"
                    />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label>Colour</Label>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="color in colors"
                            :key="color"
                            type="button"
                            class="size-7 rounded-full ring-offset-2 ring-offset-background"
                            :class="[
                                tagDotClasses[color],
                                form.color === color
                                    ? 'ring-2 ring-foreground'
                                    : '',
                            ]"
                            :aria-label="color"
                            @click="form.color = color"
                        />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="tag-keywords"
                        >Auto-tag keywords (optional)</Label
                    >
                    <Input
                        id="tag-keywords"
                        v-model="form.keywords"
                        placeholder="japan, tokyo, osaka, hokkaido"
                    />
                    <p class="text-xs text-muted-foreground">
                        When a customer's message contains one of these words,
                        the tag is added automatically.
                    </p>
                    <InputError :message="form.errors.keywords" />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="dialogOpen = false"
                        >Cancel</Button
                    >
                    <Button type="submit" :disabled="form.processing"
                        >Save</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="deleting !== null"
        @update:open="(open) => !open && (deleting = null)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle
                    >Delete the "{{ deleting?.name }}" tag?</DialogTitle
                >
                <DialogDescription>
                    It will be removed from
                    {{ deleting?.contacts_count }} contact(s). The contacts
                    themselves are kept.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="secondary" @click="deleting = null"
                    >Cancel</Button
                >
                <Button variant="destructive" @click="confirmDelete"
                    >Delete tag</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { Ban, Pencil, Trash2, X } from '@lucide/vue';
import { ref, watch } from 'vue';
import ContactController from '@/actions/App/Http/Controllers/Contacts/ContactController';
import NoteController from '@/actions/App/Http/Controllers/Contacts/NoteController';
import InboxController from '@/actions/App/Http/Controllers/Inbox/InboxController';
import ChannelBadge from '@/components/inbox/ChannelBadge.vue';
import ContactAvatar from '@/components/inbox/ContactAvatar.vue';
import TagPicker from '@/components/inbox/TagPicker.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatPhone, relativeTime } from '@/lib/format';
import { statusClasses } from '@/lib/tags';
import type { ChannelSummary, Contact, Note, Option, Tag } from '@/types';

const props = defineProps<{
    contact: Contact;
    notes: Note[];
    tags: Tag[];
    statuses: Option[];
    otherChats: { id: number; channel: ChannelSummary }[];
}>();

defineEmits<{ close: [] }>();

const editing = ref(false);

const form = useForm({
    name: props.contact.name ?? '',
    phone: props.contact.phone ? `+${props.contact.phone}` : '',
    email: props.contact.email ?? '',
    status: props.contact.status,
});

function resetForm(): void {
    form.defaults({
        name: props.contact.name ?? '',
        phone: props.contact.phone ? `+${props.contact.phone}` : '',
        email: props.contact.email ?? '',
        status: props.contact.status,
    });
    form.reset();
    form.clearErrors();
}

watch(() => props.contact, resetForm, { deep: true });

function save(): void {
    form.submit(ContactController.update(props.contact.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => (editing.value = false),
    });
}

function setStatus(event: Event): void {
    form.status = (event.target as HTMLSelectElement)
        .value as Contact['status'];
    save();
}

const note = useForm({ body: '' });

function addNote(): void {
    note.submit(NoteController.store(props.contact.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => note.reset(),
    });
}

function deleteNote(id: number): void {
    router.delete(NoteController.destroy(id).url, {
        preserveScroll: true,
        preserveState: true,
    });
}
</script>

<template>
    <div class="flex h-full min-h-0 flex-col bg-background">
        <div class="flex items-center justify-between border-b px-4 py-3">
            <h2 class="text-sm font-semibold">Lead details</h2>
            <Button
                variant="ghost"
                size="icon"
                aria-label="Close lead details"
                @click="$emit('close')"
            >
                <X />
            </Button>
        </div>

        <div class="min-h-0 flex-1 space-y-6 overflow-y-auto p-4">
            <!-- Profile -->
            <section class="space-y-3">
                <div class="flex items-center gap-3">
                    <ContactAvatar
                        :name="contact.display_name"
                        :avatar-url="contact.avatar_url"
                        size="lg"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-semibold">
                            {{ contact.display_name }}
                        </p>
                        <p class="truncate text-sm text-muted-foreground">
                            {{ formatPhone(contact.phone) }}
                        </p>
                        <p
                            v-if="contact.email"
                            class="truncate text-sm text-muted-foreground"
                        >
                            {{ contact.email }}
                        </p>
                    </div>
                    <Button
                        v-if="!editing"
                        variant="ghost"
                        size="icon"
                        aria-label="Edit contact"
                        @click="editing = true"
                    >
                        <Pencil />
                    </Button>
                </div>

                <form
                    v-if="editing"
                    class="space-y-3 rounded-lg border p-3"
                    @submit.prevent="save"
                >
                    <div class="grid gap-1.5">
                        <Label for="contact-name" class="text-xs">Name</Label>
                        <Input id="contact-name" v-model="form.name" />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="contact-phone" class="text-xs">Phone</Label>
                        <Input
                            id="contact-phone"
                            v-model="form.phone"
                            placeholder="+60 12-345 6789"
                        />
                        <InputError :message="form.errors.phone" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="contact-email" class="text-xs">Email</Label>
                        <Input
                            id="contact-email"
                            v-model="form.email"
                            type="email"
                        />
                        <InputError :message="form.errors.email" />
                    </div>
                    <div class="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="
                                editing = false;
                                resetForm();
                            "
                            >Cancel</Button
                        >
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="form.processing"
                            >Save</Button
                        >
                    </div>
                </form>

                <div
                    v-if="contact.opted_out"
                    class="flex items-center gap-2 rounded-md bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-950 dark:text-red-300"
                >
                    <Ban class="size-3.5" />
                    Opted out of blasts
                </div>
            </section>

            <!-- Lead status -->
            <section class="space-y-2">
                <Label for="lead-status" class="text-xs text-muted-foreground"
                    >Lead status</Label
                >
                <select
                    id="lead-status"
                    class="h-9 w-full rounded-md border px-2 text-sm font-medium"
                    :class="statusClasses[contact.status]"
                    :value="contact.status"
                    @change="setStatus"
                >
                    <option
                        v-for="status in statuses"
                        :key="status.value"
                        :value="status.value"
                    >
                        {{ status.label }}
                    </option>
                </select>
            </section>

            <!-- Tags -->
            <section class="space-y-2">
                <p class="text-xs text-muted-foreground">Tags</p>
                <TagPicker
                    :contact-id="contact.id"
                    :selected="contact.tags"
                    :tags="tags"
                />
            </section>

            <!-- Other chats with the same person -->
            <section v-if="otherChats.length" class="space-y-2">
                <p class="text-xs text-muted-foreground">Also chatting on</p>
                <Link
                    v-for="chat in otherChats"
                    :key="chat.id"
                    :href="InboxController.show(chat.id).url"
                    class="flex items-center gap-2 rounded-md border px-3 py-2 text-sm hover:bg-muted"
                >
                    <ChannelBadge :type="chat.channel.type" size="md" />
                    {{ chat.channel.name }}
                </Link>
            </section>

            <!-- Notes -->
            <section class="space-y-3">
                <p class="text-xs text-muted-foreground">
                    Internal notes (only your team can see these)
                </p>
                <form class="space-y-2" @submit.prevent="addNote">
                    <Textarea
                        v-model="note.body"
                        rows="2"
                        placeholder="e.g. Wants 5 pax, budget RM20k, travel in March"
                        class="text-sm"
                    />
                    <InputError :message="note.errors.body" />
                    <Button
                        type="submit"
                        size="sm"
                        variant="secondary"
                        :disabled="!note.body.trim() || note.processing"
                        >Add note</Button
                    >
                </form>
                <ul class="space-y-2">
                    <li
                        v-for="item in notes"
                        :key="item.id"
                        class="group rounded-lg bg-amber-50 p-3 text-sm dark:bg-amber-950/40"
                    >
                        <p class="whitespace-pre-wrap">{{ item.body }}</p>
                        <div
                            class="mt-1 flex items-center justify-between text-[11px] text-muted-foreground"
                        >
                            <span
                                >{{ item.user ?? 'Someone' }} ·
                                {{ relativeTime(item.created_at) }}</span
                            >
                            <button
                                v-if="item.can_delete"
                                type="button"
                                class="opacity-0 transition group-hover:opacity-100"
                                aria-label="Delete note"
                                @click="deleteNote(item.id)"
                            >
                                <Trash2 class="size-3.5" />
                            </button>
                        </div>
                    </li>
                </ul>
            </section>

            <p class="text-[11px] text-muted-foreground">
                Lead since {{ relativeTime(contact.created_at) }}
                <template v-if="contact.source">
                    · source: {{ contact.source.replace('_', ' ') }}</template
                >
            </p>
        </div>
    </div>
</template>

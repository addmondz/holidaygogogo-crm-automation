<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import {
    Ban,
    MessageCirclePlus,
    Pencil,
    Plus,
    Search,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ContactController from '@/actions/App/Http/Controllers/Contacts/ContactController';
import InboxController from '@/actions/App/Http/Controllers/Inbox/InboxController';
import Heading from '@/components/Heading.vue';
import ChannelBadge from '@/components/inbox/ChannelBadge.vue';
import ContactAvatar from '@/components/inbox/ContactAvatar.vue';
import TagBadge from '@/components/inbox/TagBadge.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
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
import { Switch } from '@/components/ui/switch';
import { formatPhone, relativeTime } from '@/lib/format';
import { statusClasses, tagDotClasses } from '@/lib/tags';
import type { ChannelSummary, Contact, Option, Paginated, Tag } from '@/types';

type ContactRow = Contact & {
    conversations: {
        id: number;
        channel: ChannelSummary;
        assignee: string | null;
    }[];
};

const props = defineProps<{
    contacts: Paginated<ContactRow>;
    filters: {
        q: string;
        tag: number | null;
        status: string | null;
        opted_out: boolean;
    };
    tags: Tag[];
    statuses: Option[];
    whatsappChannels: ChannelSummary[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Contacts', href: ContactController.index() }],
    },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth.isAdmin);

// --- Filters -------------------------------------------------------------------
const search = ref(props.filters.q);

function applyFilters(changes: Partial<typeof props.filters>): void {
    const next = { ...props.filters, ...changes };
    const query: Record<string, string | number> = {};

    if (next.q) query.q = next.q;
    if (next.tag) query.tag = next.tag;
    if (next.status) query.status = next.status;
    if (next.opted_out) query.opted_out = 1;

    router.get(
        ContactController.index({ query }).url,
        {},
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

const onSearch = useDebounceFn(
    () => applyFilters({ q: search.value.trim() }),
    350,
);

// --- Add / edit ---------------------------------------------------------------------
const dialogOpen = ref(false);
const editing = ref<ContactRow | null>(null);

const form = useForm({
    name: '',
    phone: '',
    email: '',
    status: 'new',
    opted_out: false,
    tag_ids: [] as number[],
});

function openCreate(): void {
    editing.value = null;
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

function openEdit(contact: ContactRow): void {
    editing.value = contact;
    form.clearErrors();
    form.name = contact.name ?? '';
    form.phone = contact.phone ? `+${contact.phone}` : '';
    form.email = contact.email ?? '';
    form.status = contact.status;
    form.opted_out = contact.opted_out;
    dialogOpen.value = true;
}

function toggleTag(id: number): void {
    form.tag_ids = form.tag_ids.includes(id)
        ? form.tag_ids.filter((t) => t !== id)
        : [...form.tag_ids, id];
}

function submit(): void {
    const action = editing.value
        ? ContactController.update(editing.value.id)
        : ContactController.store();

    form.submit(action, {
        preserveScroll: true,
        onSuccess: () => (dialogOpen.value = false),
    });
}

// --- Delete ---------------------------------------------------------------------------
const deleting = ref<ContactRow | null>(null);

function confirmDelete(): void {
    if (deleting.value) {
        router.delete(ContactController.destroy(deleting.value.id).url, {
            preserveScroll: true,
            onFinish: () => (deleting.value = null),
        });
    }
}

// --- Start a WhatsApp chat --------------------------------------------------------
function startWhatsApp(contact: ContactRow): void {
    const existing = contact.conversations.find(
        (c) => c.channel.type === 'whatsapp',
    );

    if (existing) {
        router.visit(InboxController.show(existing.id).url);

        return;
    }

    router.post(ContactController.startWhatsApp(contact.id).url, {
        channel_id: props.whatsappChannels[0]?.id,
    });
}
</script>

<template>
    <Head title="Contacts" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Contacts"
                description="Every lead who has messaged you, plus contacts you imported for blasts."
            />
            <div v-if="isAdmin" class="flex gap-2">
                <Button @click="openCreate"><Plus /> Add contact</Button>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative min-w-60 flex-1">
                <Search
                    class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="search"
                    type="search"
                    placeholder="Search name, phone or email"
                    class="pl-8"
                    @input="onSearch"
                />
            </div>
            <select
                class="h-9 rounded-md border bg-transparent px-2 text-sm"
                :value="filters.status ?? ''"
                aria-label="Lead status"
                @change="
                    applyFilters({
                        status:
                            ($event.target as HTMLSelectElement).value || null,
                    })
                "
            >
                <option value="">All statuses</option>
                <option v-for="s in statuses" :key="s.value" :value="s.value">
                    {{ s.label }}
                </option>
            </select>
            <select
                class="h-9 rounded-md border bg-transparent px-2 text-sm"
                :value="filters.tag ?? ''"
                aria-label="Tag"
                @change="
                    applyFilters({
                        tag:
                            Number(
                                ($event.target as HTMLSelectElement).value,
                            ) || null,
                    })
                "
            >
                <option value="">All tags</option>
                <option v-for="tag in tags" :key="tag.id" :value="tag.id">
                    {{ tag.name }}
                </option>
            </select>
            <label
                class="flex items-center gap-2 text-sm text-muted-foreground"
            >
                <Switch
                    :model-value="filters.opted_out"
                    @update:model-value="
                        (v: boolean) => applyFilters({ opted_out: v })
                    "
                />
                Opted out only
            </label>
        </div>

        <div class="overflow-x-auto rounded-xl border bg-card">
            <table class="w-full text-sm">
                <thead class="text-left text-muted-foreground">
                    <tr class="border-b">
                        <th class="px-4 py-3 font-medium">Contact</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Tags</th>
                        <th class="px-4 py-3 font-medium">Chats</th>
                        <th class="px-4 py-3 font-medium">Added</th>
                        <th class="px-4 py-3" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!contacts.data.length">
                        <td
                            colspan="6"
                            class="px-4 py-12 text-center text-muted-foreground"
                        >
                            No contacts found.
                        </td>
                    </tr>
                    <tr
                        v-for="contact in contacts.data"
                        :key="contact.id"
                        class="border-b last:border-0"
                    >
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <ContactAvatar
                                    :name="contact.display_name"
                                    :avatar-url="contact.avatar_url"
                                    size="sm"
                                />
                                <div class="min-w-0">
                                    <p
                                        class="flex items-center gap-1.5 font-medium"
                                    >
                                        {{ contact.display_name }}
                                        <Ban
                                            v-if="contact.opted_out"
                                            class="size-3.5 text-red-500"
                                            title="Opted out of blasts"
                                        />
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ formatPhone(contact.phone) }}
                                        <template v-if="contact.email">
                                            · {{ contact.email }}</template
                                        >
                                    </p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                                :class="statusClasses[contact.status]"
                                >{{ contact.status }}</span
                            >
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex max-w-60 flex-wrap gap-1">
                                <TagBadge
                                    v-for="tag in contact.tags"
                                    :key="tag.id"
                                    :tag="tag"
                                />
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex gap-1.5">
                                <Link
                                    v-for="chat in contact.conversations"
                                    :key="chat.id"
                                    :href="InboxController.show(chat.id).url"
                                    :title="`${chat.channel.name}${chat.assignee ? ' · ' + chat.assignee : ''}`"
                                >
                                    <ChannelBadge
                                        :type="chat.channel.type"
                                        size="md"
                                    />
                                </Link>
                            </div>
                        </td>
                        <td
                            class="px-4 py-3 whitespace-nowrap text-muted-foreground"
                        >
                            {{ relativeTime(contact.created_at) }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1">
                                <Button
                                    v-if="
                                        contact.phone && whatsappChannels.length
                                    "
                                    variant="ghost"
                                    size="icon"
                                    title="Open WhatsApp chat"
                                    :aria-label="`WhatsApp ${contact.display_name}`"
                                    @click="startWhatsApp(contact)"
                                >
                                    <MessageCirclePlus />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Edit ${contact.display_name}`"
                                    @click="openEdit(contact)"
                                >
                                    <Pencil />
                                </Button>
                                <Button
                                    v-if="isAdmin"
                                    variant="ghost"
                                    size="icon"
                                    class="text-destructive hover:text-destructive"
                                    :aria-label="`Delete ${contact.display_name}`"
                                    @click="deleting = contact"
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="contacts" label="contacts" />
    </div>

    <Dialog v-model:open="dialogOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{
                    editing ? 'Edit contact' : 'Add contact'
                }}</DialogTitle>
                <DialogDescription v-if="!editing">
                    Local numbers are saved with the default country code, e.g.
                    012-345 6789 → +60 12-345 6789.
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="c-name">Name</Label>
                    <Input id="c-name" v-model="form.name" />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="c-phone">Phone (WhatsApp)</Label>
                    <Input
                        id="c-phone"
                        v-model="form.phone"
                        placeholder="+60 12-345 6789"
                    />
                    <InputError :message="form.errors.phone" />
                </div>
                <div class="grid gap-2">
                    <Label for="c-email">Email</Label>
                    <Input id="c-email" v-model="form.email" type="email" />
                    <InputError :message="form.errors.email" />
                </div>
                <div class="grid gap-2">
                    <Label for="c-status">Lead status</Label>
                    <select
                        id="c-status"
                        v-model="form.status"
                        class="h-9 rounded-md border bg-transparent px-2 text-sm"
                    >
                        <option
                            v-for="s in statuses"
                            :key="s.value"
                            :value="s.value"
                        >
                            {{ s.label }}
                        </option>
                    </select>
                </div>
                <div v-if="!editing && tags.length" class="grid gap-2">
                    <Label>Tags</Label>
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="tag in tags"
                            :key="tag.id"
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs"
                            :class="
                                form.tag_ids.includes(tag.id)
                                    ? 'border-primary bg-muted'
                                    : 'opacity-70'
                            "
                            @click="toggleTag(tag.id)"
                        >
                            <span
                                class="size-2 rounded-full"
                                :class="tagDotClasses[tag.color]"
                            />
                            {{ tag.name }}
                        </button>
                    </div>
                </div>
                <label
                    v-if="editing"
                    class="flex items-center justify-between gap-4"
                >
                    <span>
                        <span class="block text-sm font-medium"
                            >Opted out of blasts</span
                        >
                        <span class="text-xs text-muted-foreground"
                            >They won't receive any blast messages.</span
                        >
                    </span>
                    <Switch v-model="form.opted_out" />
                </label>
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
                <DialogTitle>Delete {{ deleting?.display_name }}?</DialogTitle>
                <DialogDescription>
                    This deletes the contact and all their chat history in the
                    CRM. It can't be undone.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="secondary" @click="deleting = null"
                    >Cancel</Button
                >
                <Button variant="destructive" @click="confirmDelete"
                    >Delete</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

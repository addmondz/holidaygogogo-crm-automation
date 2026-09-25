<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Paperclip, Pencil, Plus, Trash2, Users, Zap } from '@lucide/vue';
import { computed, ref } from 'vue';
import QuickReplyController from '@/actions/App/Http/Controllers/QuickReplyController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
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
import { Textarea } from '@/components/ui/textarea';
import type { QuickReply } from '@/types';

defineProps<{ quickReplies: QuickReply[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Quick replies', href: QuickReplyController.index() },
        ],
    },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth.isAdmin);

const dialogOpen = ref(false);
const editing = ref<QuickReply | null>(null);
const deleting = ref<QuickReply | null>(null);

const form = useForm({
    shortcut: '',
    title: '',
    body: '',
    is_shared: false,
    attachment: null as File | null,
    remove_attachment: false,
});

function openCreate(): void {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.is_shared = isAdmin.value;
    dialogOpen.value = true;
}

function openEdit(reply: QuickReply): void {
    editing.value = reply;
    form.clearErrors();
    form.shortcut = reply.shortcut;
    form.title = reply.title;
    form.body = reply.body;
    form.is_shared = reply.is_shared;
    form.attachment = null;
    form.remove_attachment = false;
    dialogOpen.value = true;
}

function onFile(event: Event): void {
    form.attachment = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.remove_attachment = false;
}

function insertVariable(variable: string): void {
    form.body = `${form.body}${form.body && !form.body.endsWith(' ') ? ' ' : ''}{${variable}}`;
}

function submit(): void {
    const action = editing.value
        ? QuickReplyController.update(editing.value.id)
        : QuickReplyController.store();

    form.transform((data) => ({
        ...data,
        is_shared: data.is_shared ? 1 : 0,
        remove_attachment: data.remove_attachment ? 1 : 0,
    })).submit(action, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => (dialogOpen.value = false),
    });
}

function confirmDelete(): void {
    if (deleting.value) {
        router.delete(QuickReplyController.destroy(deleting.value.id).url, {
            preserveScroll: true,
            onFinish: () => (deleting.value = null),
        });
    }
}
</script>

<template>
    <Head title="Quick replies" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Quick replies"
                description="Saved answers for common questions. In a chat, type / and the shortcut to insert one."
            />
            <Button @click="openCreate"><Plus /> New quick reply</Button>
        </div>

        <div
            v-if="!quickReplies.length"
            class="flex flex-col items-center gap-3 rounded-xl border border-dashed p-12 text-center text-muted-foreground"
        >
            <Zap class="size-8 opacity-50" />
            <p>
                No quick replies yet. Good ones to start with:
                <code>/price</code>, <code>/bank</code> (payment details),
                <code>/itinerary</code>.
            </p>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <div
                v-for="reply in quickReplies"
                :key="reply.id"
                class="flex flex-col gap-3 rounded-xl border bg-card p-4"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="font-mono text-sm text-muted-foreground">
                            /{{ reply.shortcut }}
                        </p>
                        <p class="truncate font-medium">{{ reply.title }}</p>
                    </div>
                    <Badge v-if="reply.is_shared" variant="secondary"
                        ><Users /> Team</Badge
                    >
                    <Badge v-else variant="outline">Personal</Badge>
                </div>
                <p
                    class="line-clamp-4 flex-1 text-sm whitespace-pre-wrap text-muted-foreground"
                >
                    {{ reply.body }}
                </p>
                <a
                    v-if="reply.attachment"
                    :href="reply.attachment.url"
                    target="_blank"
                    class="flex items-center gap-2 truncate text-xs text-muted-foreground hover:text-foreground"
                >
                    <Paperclip class="size-3.5 shrink-0" />
                    {{ reply.attachment.name }}
                </a>
                <div v-if="reply.can_manage" class="flex justify-end gap-1">
                    <Button variant="ghost" size="sm" @click="openEdit(reply)"
                        ><Pencil /> Edit</Button
                    >
                    <Button
                        variant="ghost"
                        size="sm"
                        class="text-destructive hover:text-destructive"
                        @click="deleting = reply"
                        ><Trash2 /> Delete</Button
                    >
                </div>
            </div>
        </div>
    </div>

    <Dialog v-model:open="dialogOpen">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{
                    editing ? 'Edit quick reply' : 'New quick reply'
                }}</DialogTitle>
                <DialogDescription>
                    Variables are filled in automatically when you insert the
                    reply.
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="submit">
                <div class="grid grid-cols-3 gap-3">
                    <div class="grid gap-2">
                        <Label for="qr-shortcut">Shortcut</Label>
                        <div class="relative">
                            <span
                                class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground"
                                >/</span
                            >
                            <Input
                                id="qr-shortcut"
                                v-model="form.shortcut"
                                class="pl-6"
                                placeholder="price"
                            />
                        </div>
                        <InputError :message="form.errors.shortcut" />
                    </div>
                    <div class="col-span-2 grid gap-2">
                        <Label for="qr-title">Title</Label>
                        <Input
                            id="qr-title"
                            v-model="form.title"
                            placeholder="Japan tour price"
                        />
                        <InputError :message="form.errors.title" />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="qr-body">Message</Label>
                    <Textarea
                        id="qr-body"
                        v-model="form.body"
                        rows="6"
                        placeholder="Hi {first_name}, the 7D6N Japan tour starts from RM4,999 per person…"
                    />
                    <div
                        class="flex flex-wrap items-center gap-1 text-xs text-muted-foreground"
                    >
                        Insert:
                        <button
                            v-for="variable in [
                                'first_name',
                                'name',
                                'phone',
                                'agent_name',
                            ]"
                            :key="variable"
                            type="button"
                            class="rounded bg-muted px-1.5 py-0.5 font-mono hover:bg-accent"
                            @click="insertVariable(variable)"
                        >
                            {{ '{' + variable + '}' }}
                        </button>
                    </div>
                    <InputError :message="form.errors.body" />
                </div>
                <div class="grid gap-2">
                    <Label for="qr-file">Attachment (optional)</Label>
                    <p
                        v-if="
                            editing?.attachment &&
                            !form.attachment &&
                            !form.remove_attachment
                        "
                        class="flex items-center gap-2 text-sm"
                    >
                        <Paperclip class="size-4" />
                        {{ editing.attachment.name }}
                        <button
                            type="button"
                            class="text-xs text-destructive underline"
                            @click="form.remove_attachment = true"
                        >
                            Remove
                        </button>
                    </p>
                    <Input id="qr-file" type="file" @change="onFile" />
                    <p class="text-xs text-muted-foreground">
                        E.g. a brochure PDF or itinerary image.
                    </p>
                    <InputError :message="form.errors.attachment" />
                </div>
                <label
                    v-if="isAdmin"
                    class="flex items-center justify-between gap-4"
                >
                    <span>
                        <span class="block text-sm font-medium"
                            >Share with the team</span
                        >
                        <span class="text-xs text-muted-foreground"
                            >Everyone can use it. Otherwise only you see
                            it.</span
                        >
                    </span>
                    <Switch v-model="form.is_shared" />
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
                <DialogTitle>Delete /{{ deleting?.shortcut }}?</DialogTitle>
                <DialogDescription
                    >Messages already sent are not affected.</DialogDescription
                >
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

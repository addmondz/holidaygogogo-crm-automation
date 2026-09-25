<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { Ban, Copy, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BroadcastController from '@/actions/App/Http/Controllers/Admin/BroadcastController';
import InboxController from '@/actions/App/Http/Controllers/Inbox/InboxController';
import Heading from '@/components/Heading.vue';
import ChannelBadge from '@/components/inbox/ChannelBadge.vue';
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
import {
    broadcastStatusClasses,
    formatDateTime,
    percent,
} from '@/lib/broadcasts';
import { formatPhone } from '@/lib/format';
import type { ChannelSummary, Paginated } from '@/types';

type Recipient = {
    id: number;
    contact: { id: number; name: string; phone: string | null };
    conversation_id: number | null;
    status: string;
    error: string | null;
    sent_at: string | null;
    replied_at: string | null;
};

const props = defineProps<{
    broadcast: {
        id: number;
        name: string;
        status: string;
        channel: ChannelSummary;
        template: { name: string; language: string } | null;
        body: string | null;
        total_recipients: number;
        scheduled_at: string | null;
        started_at: string | null;
        completed_at: string | null;
        created_at: string;
        creator: string | null;
        audience_labels: {
            include: string[];
            exclude: string[];
            statuses: string[];
        };
    };
    counts: {
        total: number;
        pending: number;
        sent: number;
        delivered: number;
        read: number;
        failed: number;
        skipped: number;
        replied: number;
    };
    recipients: Paginated<Recipient>;
    filter: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Blasts', href: BroadcastController.index() }],
    },
});

const running = computed(() =>
    ['sending', 'scheduled'].includes(props.broadcast.status),
);

// Live progress while the blast is going out (or about to).
const { start, stop } = usePoll(
    3000,
    { only: ['broadcast', 'counts', 'recipients'] },
    { autoStart: false },
);

watch(running, (isRunning) => (isRunning ? start() : stop()), {
    immediate: true,
});

const done = computed(() => props.counts.total - props.counts.pending);

const cards = computed(() => [
    {
        label: 'Sent',
        value: props.counts.sent,
        hint: percent(props.counts.sent, props.counts.total),
    },
    {
        label: 'Delivered',
        value: props.counts.delivered,
        hint: percent(props.counts.delivered, props.counts.sent) + ' of sent',
    },
    {
        label: 'Read',
        value: props.counts.read,
        hint:
            percent(props.counts.read, props.counts.delivered) +
            ' of delivered',
    },
    {
        label: 'Replied',
        value: props.counts.replied,
        hint: percent(props.counts.replied, props.counts.sent) + ' of sent',
    },
    {
        label: 'Failed',
        value: props.counts.failed,
        hint: 'e.g. not on WhatsApp',
    },
    {
        label: 'Skipped',
        value: props.counts.skipped,
        hint: 'opted out / cancelled',
    },
]);

const filters = [
    { value: null, label: 'All' },
    { value: 'failed', label: 'Failed' },
    { value: 'read', label: 'Read' },
    { value: 'delivered', label: 'Delivered' },
    { value: 'sent', label: 'Sent' },
    { value: 'pending', label: 'Waiting' },
    { value: 'skipped', label: 'Skipped' },
];

function filterBy(status: string | null): void {
    router.get(
        BroadcastController.show(props.broadcast.id, {
            query: status ? { status } : {},
        }).url,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['recipients', 'filter'],
        },
    );
}

const confirmCancel = ref(false);
const confirmDelete = ref(false);

function cancel(): void {
    router.post(
        BroadcastController.cancel(props.broadcast.id).url,
        {},
        { onFinish: () => (confirmCancel.value = false) },
    );
}

function destroy(): void {
    router.delete(BroadcastController.destroy(props.broadcast.id).url);
}

const statusLabel: Record<string, string> = {
    pending: 'Waiting',
    sent: 'Sent',
    delivered: 'Delivered',
    read: 'Read',
    failed: 'Failed',
    skipped: 'Skipped',
};
</script>

<template>
    <Head :title="broadcast.name" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-start gap-3">
                <ChannelBadge
                    :type="broadcast.channel.type"
                    size="md"
                    class="mt-1.5"
                />
                <Heading
                    :title="broadcast.name"
                    :description="`${broadcast.template ? 'Template ' + broadcast.template.name : 'Messenger message'} · ${broadcast.channel.name}${broadcast.creator ? ' · by ' + broadcast.creator : ''}`"
                />
            </div>
            <div class="flex items-center gap-2">
                <span
                    class="rounded-full px-2.5 py-1 text-xs font-medium capitalize"
                    :class="broadcastStatusClasses[broadcast.status]"
                    >{{ broadcast.status }}</span
                >
                <Button
                    v-if="running"
                    variant="outline"
                    size="sm"
                    @click="confirmCancel = true"
                    ><Ban /> Cancel</Button
                >
                <Button
                    v-if="broadcast.status !== 'sending'"
                    variant="ghost"
                    size="sm"
                    class="text-destructive hover:text-destructive"
                    @click="confirmDelete = true"
                >
                    <Trash2 /> Delete
                </Button>
            </div>
        </div>

        <div class="grid gap-3 text-sm sm:grid-cols-3">
            <div class="rounded-xl border bg-card p-4">
                <p class="text-muted-foreground">Audience</p>
                <p class="mt-1">
                    <template v-if="broadcast.audience_labels.include.length"
                        >Tagged
                        {{
                            broadcast.audience_labels.include.join(' or ')
                        }}</template
                    >
                    <template v-else>All contacts</template>
                    <template v-if="broadcast.audience_labels.exclude.length"
                        >, not
                        {{
                            broadcast.audience_labels.exclude.join(' / ')
                        }}</template
                    >
                    <template v-if="broadcast.audience_labels.statuses.length">
                        · status
                        {{
                            broadcast.audience_labels.statuses.join(' / ')
                        }}</template
                    >
                </p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-muted-foreground">
                    {{
                        broadcast.status === 'scheduled'
                            ? 'Scheduled for'
                            : 'Started'
                    }}
                </p>
                <p class="mt-1">
                    {{
                        formatDateTime(
                            broadcast.status === 'scheduled'
                                ? broadcast.scheduled_at
                                : broadcast.started_at,
                        ) || '—'
                    }}
                </p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-muted-foreground">Finished</p>
                <p class="mt-1">
                    {{
                        formatDateTime(broadcast.completed_at) ||
                        (running ? 'In progress…' : '—')
                    }}
                </p>
            </div>
        </div>

        <div
            v-if="broadcast.status === 'sending' && counts.total"
            class="space-y-2"
        >
            <div class="flex justify-between text-sm">
                <span>Sending… {{ done }} of {{ counts.total }}</span>
                <span class="text-muted-foreground">{{
                    percent(done, counts.total)
                }}</span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-muted">
                <div
                    class="h-full rounded-full bg-primary transition-all"
                    :style="{ width: percent(done, counts.total) }"
                />
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            <div
                v-for="card in cards"
                :key="card.label"
                class="rounded-xl border bg-card p-4"
            >
                <p class="text-sm text-muted-foreground">{{ card.label }}</p>
                <p class="text-2xl font-semibold tabular-nums">
                    {{ card.value }}
                </p>
                <p class="text-xs text-muted-foreground">{{ card.hint }}</p>
            </div>
        </div>

        <section class="space-y-3">
            <div class="flex flex-wrap gap-1">
                <button
                    v-for="f in filters"
                    :key="f.label"
                    type="button"
                    class="rounded-full border px-3 py-1 text-xs"
                    :class="
                        filter === f.value
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'hover:bg-muted'
                    "
                    @click="filterBy(f.value)"
                >
                    {{ f.label }}
                </button>
            </div>

            <div class="overflow-x-auto rounded-xl border bg-card">
                <table class="w-full text-sm">
                    <thead class="text-left text-muted-foreground">
                        <tr class="border-b">
                            <th class="px-4 py-3 font-medium">Contact</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Sent</th>
                            <th class="px-4 py-3 font-medium">Replied</th>
                            <th class="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!recipients.data.length">
                            <td
                                colspan="5"
                                class="px-4 py-10 text-center text-muted-foreground"
                            >
                                {{
                                    broadcast.status === 'scheduled' ||
                                    broadcast.status === 'draft'
                                        ? 'Recipients are listed once the blast starts.'
                                        : 'Nobody here.'
                                }}
                            </td>
                        </tr>
                        <tr
                            v-for="recipient in recipients.data"
                            :key="recipient.id"
                            class="border-b last:border-0"
                        >
                            <td class="px-4 py-2.5">
                                <p class="font-medium">
                                    {{ recipient.contact.name }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ formatPhone(recipient.contact.phone) }}
                                </p>
                            </td>
                            <td class="px-4 py-2.5">
                                <span
                                    :class="{
                                        'text-red-600':
                                            recipient.status === 'failed',
                                        'text-green-700 dark:text-green-400':
                                            recipient.status === 'read',
                                    }"
                                >
                                    {{
                                        statusLabel[recipient.status] ??
                                        recipient.status
                                    }}
                                </span>
                                <p
                                    v-if="recipient.error"
                                    class="max-w-md text-xs text-muted-foreground"
                                >
                                    {{ recipient.error }}
                                </p>
                            </td>
                            <td class="px-4 py-2.5 text-muted-foreground">
                                {{ formatDateTime(recipient.sent_at) }}
                            </td>
                            <td class="px-4 py-2.5">
                                {{
                                    recipient.replied_at
                                        ? '✓ ' +
                                          formatDateTime(recipient.replied_at)
                                        : ''
                                }}
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <Link
                                    v-if="recipient.conversation_id"
                                    :href="
                                        InboxController.show(
                                            recipient.conversation_id,
                                        ).url
                                    "
                                    class="text-xs underline"
                                    >Open chat</Link
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="recipients" label="recipients" />
        </section>

        <p class="flex items-center gap-2 text-xs text-muted-foreground">
            <Copy class="size-3.5" /> “Replied” counts customers who wrote back
            within 3 days of the blast.
        </p>
    </div>

    <Dialog v-model:open="confirmCancel">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Cancel this blast?</DialogTitle>
                <DialogDescription
                    >People who haven't received it yet will be skipped.
                    Messages already sent can't be recalled.</DialogDescription
                >
            </DialogHeader>
            <DialogFooter>
                <Button variant="secondary" @click="confirmCancel = false"
                    >Keep sending</Button
                >
                <Button variant="destructive" @click="cancel"
                    >Cancel blast</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="confirmDelete">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete this blast?</DialogTitle>
                <DialogDescription
                    >The report is deleted. Messages stay in each customer's
                    chat history.</DialogDescription
                >
            </DialogHeader>
            <DialogFooter>
                <Button variant="secondary" @click="confirmDelete = false"
                    >Cancel</Button
                >
                <Button variant="destructive" @click="destroy">Delete</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

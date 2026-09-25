<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Megaphone, Plus } from '@lucide/vue';
import BroadcastController from '@/actions/App/Http/Controllers/Admin/BroadcastController';
import Heading from '@/components/Heading.vue';
import ChannelBadge from '@/components/inbox/ChannelBadge.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import {
    broadcastStatusClasses,
    formatDateTime,
    percent,
} from '@/lib/broadcasts';
import type { ChannelSummary, Paginated } from '@/types';

type BroadcastRow = {
    id: number;
    name: string;
    status: string;
    channel: ChannelSummary;
    template: { name: string; language: string } | null;
    total_recipients: number;
    scheduled_at: string | null;
    started_at: string | null;
    created_at: string;
    creator: string | null;
    sent_count: number;
    delivered_count: number;
    read_count: number;
    failed_count: number;
    replied_count: number;
};

defineProps<{ broadcasts: Paginated<BroadcastRow> }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Blasts', href: BroadcastController.index() }],
    },
});

function link(row: BroadcastRow): string {
    return ['draft', 'scheduled'].includes(row.status)
        ? BroadcastController.edit(row.id).url
        : BroadcastController.show(row.id).url;
}
</script>

<template>
    <Head title="Blasts" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Blasts"
                description="Send one message to many leads at once, e.g. a promotion to everyone tagged “Japan”."
            />
            <Button as-child>
                <Link :href="BroadcastController.create().url"
                    ><Plus /> New blast</Link
                >
            </Button>
        </div>

        <div
            v-if="!broadcasts.data.length"
            class="flex flex-col items-center gap-3 rounded-xl border border-dashed p-12 text-center text-muted-foreground"
        >
            <Megaphone class="size-8 opacity-50" />
            <p>No blasts yet.</p>
        </div>

        <div v-else class="overflow-x-auto rounded-xl border bg-card">
            <table class="w-full text-sm">
                <thead class="text-left text-muted-foreground">
                    <tr class="border-b">
                        <th class="px-4 py-3 font-medium">Blast</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Recipients</th>
                        <th class="px-4 py-3 font-medium">Delivered</th>
                        <th class="px-4 py-3 font-medium">Read</th>
                        <th class="px-4 py-3 font-medium">Replied</th>
                        <th class="px-4 py-3 font-medium">Failed</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in broadcasts.data"
                        :key="row.id"
                        class="border-b last:border-0 hover:bg-muted/50"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="link(row)"
                                class="flex items-center gap-3"
                            >
                                <ChannelBadge
                                    :type="row.channel.type"
                                    size="md"
                                />
                                <span class="min-w-0">
                                    <span class="block font-medium">{{
                                        row.name
                                    }}</span>
                                    <span
                                        class="block text-xs text-muted-foreground"
                                    >
                                        {{
                                            row.template?.name ??
                                            row.channel.name
                                        }}
                                        ·
                                        {{
                                            row.status === 'scheduled'
                                                ? `scheduled for ${formatDateTime(row.scheduled_at)}`
                                                : formatDateTime(
                                                      row.started_at ??
                                                          row.created_at,
                                                  )
                                        }}
                                        <template v-if="row.creator">
                                            · by {{ row.creator }}</template
                                        >
                                    </span>
                                </span>
                            </Link>
                        </td>
                        <td class="px-4 py-3">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                                :class="broadcastStatusClasses[row.status]"
                                >{{ row.status }}</span
                            >
                        </td>
                        <td class="px-4 py-3 tabular-nums">
                            {{ row.total_recipients || '—' }}
                        </td>
                        <td class="px-4 py-3 tabular-nums">
                            {{
                                percent(
                                    row.delivered_count,
                                    row.total_recipients,
                                )
                            }}
                        </td>
                        <td class="px-4 py-3 tabular-nums">
                            {{ percent(row.read_count, row.total_recipients) }}
                        </td>
                        <td class="px-4 py-3 tabular-nums">
                            {{
                                row.replied_count ||
                                (row.total_recipients ? 0 : '—')
                            }}
                        </td>
                        <td
                            class="px-4 py-3 tabular-nums"
                            :class="{ 'text-red-600': row.failed_count }"
                        >
                            {{ row.total_recipients ? row.failed_count : '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="broadcasts" label="blasts" />
    </div>
</template>

<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { useClipboard } from '@vueuse/core';
import {
    AlertTriangle,
    CheckCircle2,
    Copy,
    Pencil,
    Plus,
    RefreshCw,
    Trash2,
    Wifi,
} from '@lucide/vue';
import { ref } from 'vue';
import ChannelController from '@/actions/App/Http/Controllers/Admin/ChannelController';
import Heading from '@/components/Heading.vue';
import ChannelBadge from '@/components/inbox/ChannelBadge.vue';
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
import type { ChannelSummary, WhatsappTemplate } from '@/types';

type ChannelRow = ChannelSummary & {
    external_id: string;
    business_account_id: string | null;
    is_active: boolean;
    has_token: boolean;
    conversations_count: number;
    approved_templates_count: number;
};

defineProps<{
    channels: ChannelRow[];
    templates: WhatsappTemplate[];
    setup: {
        whatsapp_webhook_url: string;
        messenger_webhook_url: string;
        verify_token: string | null;
        app_secret_set: boolean;
        app_id_set: boolean;
        demo_mode: boolean;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Channels', href: ChannelController.index() }],
    },
});

const { copy, copied } = useClipboard();

const dialogOpen = ref(false);
const editing = ref<ChannelRow | null>(null);
const deleting = ref<ChannelRow | null>(null);

const form = useForm({
    type: 'whatsapp' as 'whatsapp' | 'messenger',
    name: '',
    external_id: '',
    business_account_id: '',
    display_phone: '',
    access_token: '',
    is_active: true,
});

function openCreate(type: 'whatsapp' | 'messenger'): void {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.type = type;
    form.name =
        type === 'whatsapp'
            ? 'HolidayGoGoGo WhatsApp'
            : 'HolidayGoGoGo Facebook Page';
    dialogOpen.value = true;
}

function openEdit(channel: ChannelRow): void {
    editing.value = channel;
    form.clearErrors();
    form.type = channel.type;
    form.name = channel.name;
    form.external_id = channel.external_id;
    form.business_account_id = channel.business_account_id ?? '';
    form.display_phone = channel.display_phone ?? '';
    form.access_token = '';
    form.is_active = channel.is_active;
    dialogOpen.value = true;
}

function submit(): void {
    const action = editing.value
        ? ChannelController.update(editing.value.id)
        : ChannelController.store();

    form.transform((data) => {
        const payload: Record<string, unknown> = { ...data };

        if (editing.value) {
            delete payload.type;
        }

        return payload;
    }).submit(action, {
        preserveScroll: true,
        onSuccess: () => (dialogOpen.value = false),
    });
}

function post(url: string): void {
    router.post(url, {}, { preserveScroll: true });
}

function confirmDelete(): void {
    if (deleting.value) {
        router.delete(ChannelController.destroy(deleting.value.id).url, {
            preserveScroll: true,
            onFinish: () => (deleting.value = null),
        });
    }
}
</script>

<template>
    <Head title="Channels" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Channels"
                description="Your WhatsApp numbers and Facebook Pages connected through Meta."
            />
            <div class="flex gap-2">
                <Button variant="outline" @click="openCreate('messenger')"
                    ><Plus /> Facebook Page</Button
                >
                <Button @click="openCreate('whatsapp')"
                    ><Plus /> WhatsApp number</Button
                >
            </div>
        </div>

        <div
            v-if="setup.demo_mode"
            class="flex gap-3 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-900 dark:bg-sky-950 dark:text-sky-200"
        >
            <Wifi class="size-5 shrink-0" />
            <p>
                <strong>Demo mode is on</strong> (<code>META_FAKE=true</code>).
                Messages are not sent to Meta, and any IDs work. Use "Simulate a
                customer message" in the inbox to try things out. Set
                <code>META_FAKE=false</code> when you're ready to go live.
            </p>
        </div>

        <!-- Connected channels -->
        <div class="grid gap-4 lg:grid-cols-2">
            <div
                v-if="!channels.length"
                class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground lg:col-span-2"
            >
                No channels yet. Add your WhatsApp number and Facebook Page to
                start receiving messages.
            </div>

            <div
                v-for="channel in channels"
                :key="channel.id"
                class="space-y-4 rounded-xl border bg-card p-5"
                :class="{ 'opacity-60': !channel.is_active }"
            >
                <div class="flex items-start gap-3">
                    <ChannelBadge
                        :type="channel.type"
                        size="md"
                        class="mt-0.5"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ channel.name }}</p>
                        <p class="text-sm text-muted-foreground">
                            {{ channel.type_label }}
                            <template v-if="channel.display_phone">
                                · {{ channel.display_phone }}</template
                            >
                        </p>
                    </div>
                    <Badge v-if="!channel.is_active" variant="outline"
                        >Off</Badge
                    >
                    <Badge
                        v-else-if="!channel.has_token && !setup.demo_mode"
                        variant="destructive"
                        >No token</Badge
                    >
                </div>

                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <dt class="text-muted-foreground">
                        {{
                            channel.type === 'whatsapp'
                                ? 'Phone number ID'
                                : 'Page ID'
                        }}
                    </dt>
                    <dd class="truncate font-mono text-xs leading-5">
                        {{ channel.external_id }}
                    </dd>
                    <template v-if="channel.type === 'whatsapp'">
                        <dt class="text-muted-foreground">
                            Business account ID
                        </dt>
                        <dd class="truncate font-mono text-xs leading-5">
                            {{ channel.business_account_id ?? '—' }}
                        </dd>
                        <dt class="text-muted-foreground">
                            Approved templates
                        </dt>
                        <dd>{{ channel.approved_templates_count }}</dd>
                    </template>
                    <dt class="text-muted-foreground">Chats</dt>
                    <dd>{{ channel.conversations_count }}</dd>
                </dl>

                <div class="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        @click="post(ChannelController.test(channel.id).url)"
                    >
                        <CheckCircle2 /> Test connection
                    </Button>
                    <Button
                        v-if="channel.type === 'whatsapp'"
                        variant="outline"
                        size="sm"
                        @click="
                            post(
                                ChannelController.syncTemplates(channel.id).url,
                            )
                        "
                    >
                        <RefreshCw /> Sync templates
                    </Button>
                    <Button variant="ghost" size="sm" @click="openEdit(channel)"
                        ><Pencil /> Edit</Button
                    >
                    <Button
                        v-if="channel.conversations_count === 0"
                        variant="ghost"
                        size="sm"
                        class="text-destructive hover:text-destructive"
                        @click="deleting = channel"
                        ><Trash2 /> Delete</Button
                    >
                </div>
            </div>
        </div>

        <!-- Meta webhook setup -->
        <section class="space-y-4 rounded-xl border bg-card p-5">
            <Heading
                variant="small"
                title="Meta webhook settings"
                description="In your Meta app (developers.facebook.com), paste these under WhatsApp → Configuration and Messenger → Settings → Webhooks."
            />

            <div class="grid gap-3 text-sm">
                <div
                    v-for="row in [
                        {
                            label: 'WhatsApp callback URL',
                            value: setup.whatsapp_webhook_url,
                        },
                        {
                            label: 'Messenger callback URL',
                            value: setup.messenger_webhook_url,
                        },
                        {
                            label: 'Verify token',
                            value: setup.verify_token ?? '',
                        },
                    ]"
                    :key="row.label"
                    class="grid gap-1 sm:grid-cols-[12rem_1fr] sm:items-center"
                >
                    <span class="text-muted-foreground">{{ row.label }}</span>
                    <div class="flex min-w-0 items-center gap-2">
                        <code
                            class="min-w-0 flex-1 truncate rounded bg-muted px-2 py-1.5 text-xs"
                            >{{
                                row.value ||
                                'Not set — add META_WEBHOOK_VERIFY_TOKEN to .env'
                            }}</code
                        >
                        <Button
                            v-if="row.value"
                            variant="ghost"
                            size="icon"
                            :aria-label="`Copy ${row.label}`"
                            @click="copy(row.value)"
                        >
                            <Copy />
                        </Button>
                    </div>
                </div>
            </div>
            <p v-if="copied" class="text-xs text-green-600">Copied!</p>

            <ul class="space-y-1 text-sm">
                <li class="flex items-center gap-2">
                    <CheckCircle2
                        v-if="setup.app_secret_set"
                        class="size-4 text-green-600"
                    />
                    <AlertTriangle v-else class="size-4 text-amber-600" />
                    META_APP_SECRET
                    {{
                        setup.app_secret_set
                            ? 'is set'
                            : 'is missing — webhooks will be rejected in production'
                    }}
                </li>
                <li class="flex items-center gap-2">
                    <CheckCircle2
                        v-if="setup.app_id_set"
                        class="size-4 text-green-600"
                    />
                    <AlertTriangle v-else class="size-4 text-amber-600" />
                    META_APP_ID
                    {{
                        setup.app_id_set
                            ? 'is set'
                            : 'is missing — replies sent from the CRM may appear twice on Messenger'
                    }}
                </li>
            </ul>
            <p class="text-xs text-muted-foreground">
                Subscribe to these webhook fields — WhatsApp:
                <code>messages</code> (and <code>smb_message_echoes</code> if
                you use the WhatsApp Business app on the same number).
                Messenger: <code>messages</code>,
                <code>messaging_postbacks</code>,
                <code>message_deliveries</code>, <code>message_reads</code>,
                <code>message_echoes</code>.
            </p>
        </section>

        <!-- Templates -->
        <section
            v-if="templates.length"
            class="space-y-3 rounded-xl border bg-card p-5"
        >
            <Heading
                variant="small"
                title="WhatsApp templates"
                description="Synced from WhatsApp Manager. Only approved templates can be sent."
            />
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-muted-foreground">
                        <tr class="border-b">
                            <th class="py-2 pr-4 font-medium">Name</th>
                            <th class="py-2 pr-4 font-medium">Language</th>
                            <th class="py-2 pr-4 font-medium">Category</th>
                            <th class="py-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="template in templates"
                            :key="template.id"
                            class="border-b last:border-0"
                        >
                            <td class="py-2 pr-4 font-mono text-xs">
                                {{ template.name }}
                            </td>
                            <td class="py-2 pr-4">{{ template.language }}</td>
                            <td class="py-2 pr-4 capitalize">
                                {{ template.category?.toLowerCase() }}
                            </td>
                            <td class="py-2">
                                <Badge
                                    :variant="
                                        template.status === 'APPROVED'
                                            ? 'default'
                                            : 'outline'
                                    "
                                    >{{ template.status }}</Badge
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <Dialog v-model:open="dialogOpen">
        <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>
                    {{
                        editing
                            ? 'Edit channel'
                            : form.type === 'whatsapp'
                              ? 'Connect a WhatsApp number'
                              : 'Connect a Facebook Page'
                    }}
                </DialogTitle>
                <DialogDescription v-if="form.type === 'whatsapp'">
                    Find these in your Meta app under WhatsApp → API Setup. Use
                    a permanent System User token from Meta Business Settings
                    (temporary tokens expire after 24 hours).
                </DialogDescription>
                <DialogDescription v-else>
                    Use your Page ID and a Page access token (from a System User
                    in Meta Business Settings, with pages_messaging permission).
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="ch-name">Name (shown to agents)</Label>
                    <Input id="ch-name" v-model="form.name" />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="ch-external">{{
                        form.type === 'whatsapp' ? 'Phone number ID' : 'Page ID'
                    }}</Label>
                    <Input
                        id="ch-external"
                        v-model="form.external_id"
                        inputmode="numeric"
                        placeholder="e.g. 106540352242922"
                    />
                    <InputError :message="form.errors.external_id" />
                </div>
                <template v-if="form.type === 'whatsapp'">
                    <div class="grid gap-2">
                        <Label for="ch-waba"
                            >WhatsApp Business Account ID</Label
                        >
                        <Input
                            id="ch-waba"
                            v-model="form.business_account_id"
                            inputmode="numeric"
                        />
                        <p class="text-xs text-muted-foreground">
                            Needed to sync message templates.
                        </p>
                        <InputError
                            :message="form.errors.business_account_id"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="ch-phone">Display phone number</Label>
                        <Input
                            id="ch-phone"
                            v-model="form.display_phone"
                            placeholder="+60 12-345 6789"
                        />
                        <InputError :message="form.errors.display_phone" />
                    </div>
                </template>
                <div class="grid gap-2">
                    <Label for="ch-token">Access token</Label>
                    <Input
                        id="ch-token"
                        v-model="form.access_token"
                        type="password"
                        autocomplete="off"
                        :placeholder="
                            editing?.has_token
                                ? 'Saved — leave empty to keep it'
                                : 'EAAG…'
                        "
                    />
                    <p class="text-xs text-muted-foreground">
                        Stored encrypted.
                    </p>
                    <InputError :message="form.errors.access_token" />
                </div>
                <label
                    v-if="editing"
                    class="flex items-center justify-between gap-4"
                >
                    <span>
                        <span class="block text-sm font-medium">Active</span>
                        <span class="text-xs text-muted-foreground"
                            >Switch off to stop using this channel. History is
                            kept.</span
                        >
                    </span>
                    <Switch v-model="form.is_active" />
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
                <DialogTitle>Delete {{ deleting?.name }}?</DialogTitle>
                <DialogDescription
                    >The CRM will stop receiving its
                    messages.</DialogDescription
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

<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { CalendarClock, Info, Send, Users } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import BroadcastController from '@/actions/App/Http/Controllers/Admin/BroadcastController';
import Heading from '@/components/Heading.vue';
import ChannelBadge from '@/components/inbox/ChannelBadge.vue';
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
import { Textarea } from '@/components/ui/textarea';
import { api } from '@/lib/api';
import { fillPlaceholders, formatMessage } from '@/lib/messageText';
import { tagDotClasses } from '@/lib/tags';
import { renderTemplatePreview } from '@/lib/templatePreview';
import type {
    ChannelSummary,
    Contact,
    Option,
    Tag,
    TemplateVariable,
    WhatsappTemplate,
} from '@/types';

type Audience = {
    tag_ids: number[];
    exclude_tag_ids: number[];
    statuses: string[];
};

const props = defineProps<{
    broadcast: {
        id: number;
        name: string;
        channel_id: number;
        whatsapp_template_id: number | null;
        template_params: Record<string, string>;
        body: string | null;
        audience: Audience;
        scheduled_at: string | null;
        status: string;
    } | null;
    channels: ChannelSummary[];
    templates: (WhatsappTemplate & { variables: TemplateVariable[] })[];
    tags: Tag[];
    statuses: Option[];
    ratePerMinute: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Blasts', href: BroadcastController.index() },
            { title: 'Blast', href: BroadcastController.create() },
        ],
    },
});

const page = usePage();

/** Browser-local "YYYY-MM-DDTHH:mm" for <input type="datetime-local">. */
function toLocalInput(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);
    const offset = date.getTimezoneOffset() * 60000;

    return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

const form = useForm({
    name: props.broadcast?.name ?? '',
    channel_id:
        props.broadcast?.channel_id ??
        props.channels.find((c) => c.type === 'whatsapp')?.id ??
        props.channels[0]?.id ??
        null,
    whatsapp_template_id: props.broadcast?.whatsapp_template_id ?? null,
    template_params: { ...props.broadcast?.template_params } as Record<
        string,
        string
    >,
    body: props.broadcast?.body ?? '',
    audience: {
        tag_ids: [...(props.broadcast?.audience.tag_ids ?? [])],
        exclude_tag_ids: [...(props.broadcast?.audience.exclude_tag_ids ?? [])],
        statuses: [...(props.broadcast?.audience.statuses ?? [])],
    } as Audience,
    when: props.broadcast?.scheduled_at ? 'later' : 'now',
    scheduled_local: toLocalInput(props.broadcast?.scheduled_at ?? null),
    intent: 'draft' as 'draft' | 'send',
});

const channel = computed(
    () => props.channels.find((c) => c.id === form.channel_id) ?? null,
);
const isWhatsApp = computed(() => channel.value?.type === 'whatsapp');
const channelTemplates = computed(() =>
    props.templates.filter((t) => t.channel_id === form.channel_id),
);
const template = computed(
    () =>
        channelTemplates.value.find(
            (t) => t.id === form.whatsapp_template_id,
        ) ?? null,
);

// Pick a template when switching channels; pre-fill the greeting variable.
watch(
    () => form.channel_id,
    () => {
        if (
            !channelTemplates.value.some(
                (t) => t.id === form.whatsapp_template_id,
            )
        ) {
            form.whatsapp_template_id = channelTemplates.value[0]?.id ?? null;
        }
    },
    { immediate: true },
);

// Keep one input per template variable (existing values are kept); pre-fill the greeting.
watch(
    template,
    (current) => {
        if (!current) {
            return;
        }

        const params: Record<string, string> = {};

        current.variables.forEach((variable, index) => {
            params[variable.key] =
                form.template_params[variable.key] ??
                (index === 0 && variable.key.startsWith('body.')
                    ? '{first_name|there}'
                    : '');
        });

        form.template_params = params;
    },
    { immediate: true },
);

// --- Audience size -----------------------------------------------------------------
const audienceCount = ref<number | null>(null);
const audienceSample = ref<string[]>([]);

const refreshAudience = useDebounceFn(async () => {
    if (!form.channel_id) {
        return;
    }

    const response = await api<{ count: number; sample: string[] }>(
        'get',
        BroadcastController.audience({
            query: {
                channel_id: form.channel_id,
                tag_ids: form.audience.tag_ids,
                exclude_tag_ids: form.audience.exclude_tag_ids,
                statuses: form.audience.statuses,
            },
        }).url,
    );
    audienceCount.value = response.count;
    audienceSample.value = response.sample;
}, 300);

watch(() => [form.channel_id, form.audience], refreshAudience, { deep: true });
onMounted(refreshAudience);

function toggle(list: (number | string)[], value: number | string): void {
    const index = list.indexOf(value);

    if (index === -1) {
        list.push(value);
    } else {
        list.splice(index, 1);
    }
}

// --- Preview ---------------------------------------------------------------------------
const sampleContact = {
    name: 'Kerry Tan',
    phone: '60123456789',
} as Contact;

const preview = computed(() => {
    const agent = page.props.auth.user.name;

    if (isWhatsApp.value) {
        if (!template.value) {
            return '';
        }

        const filled = Object.fromEntries(
            Object.entries(form.template_params).map(([k, v]) => [
                k,
                fillPlaceholders(v ?? '', sampleContact, agent),
            ]),
        );

        return formatMessage(renderTemplatePreview(template.value, filled));
    }

    return formatMessage(fillPlaceholders(form.body, sampleContact, agent));
});

const minutesToSend = computed(() =>
    audienceCount.value
        ? Math.max(
              1,
              Math.ceil(audienceCount.value / Math.max(1, props.ratePerMinute)),
          )
        : 0,
);

// --- Test message ------------------------------------------------------------------------
const testPhone = ref('');
const testing = ref(false);

function sendTest(): void {
    testing.value = true;
    router.post(
        BroadcastController.test().url,
        {
            channel_id: form.channel_id,
            whatsapp_template_id: form.whatsapp_template_id,
            template_params: form.template_params,
            phone: testPhone.value,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => (testing.value = false),
        },
    );
}

// --- Save / send ------------------------------------------------------------------------------
const confirmOpen = ref(false);

function submit(intent: 'draft' | 'send'): void {
    form.intent = intent;

    const action = props.broadcast
        ? BroadcastController.update(props.broadcast.id)
        : BroadcastController.store();

    form.transform((data) => ({
        name: data.name,
        channel_id: data.channel_id,
        whatsapp_template_id: isWhatsApp.value
            ? data.whatsapp_template_id
            : null,
        template_params: isWhatsApp.value ? data.template_params : {},
        body: isWhatsApp.value ? null : data.body,
        audience: data.audience,
        scheduled_at:
            data.when === 'later' && data.scheduled_local
                ? new Date(data.scheduled_local).toISOString()
                : null,
        intent: data.intent,
    })).submit(action, {
        preserveScroll: true,
        onFinish: () => (confirmOpen.value = false),
    });
}
</script>

<template>
    <Head :title="broadcast ? `Edit ${broadcast.name}` : 'New blast'" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            :title="broadcast ? 'Edit blast' : 'New blast'"
            description="Only message customers who agreed to hear from you. Every blast includes an easy way to opt out (reply STOP)."
        />

        <div
            v-if="!channels.length"
            class="rounded-xl border border-dashed p-10 text-center text-muted-foreground"
        >
            Connect a WhatsApp number or Facebook Page under Admin → Channels
            first.
        </div>

        <div v-else class="grid gap-6 xl:grid-cols-[1fr_22rem]">
            <div class="space-y-6">
                <!-- 1. Details -->
                <section class="space-y-4 rounded-xl border bg-card p-5">
                    <h2 class="font-medium">1. Name and channel</h2>
                    <div class="grid gap-2">
                        <Label for="b-name"
                            >Blast name (only your team sees this)</Label
                        >
                        <Input
                            id="b-name"
                            v-model="form.name"
                            placeholder="Japan winter promo – Nov"
                        />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label
                            v-for="c in channels"
                            :key="c.id"
                            class="flex cursor-pointer items-center gap-3 rounded-lg border p-3 has-[:checked]:border-primary has-[:checked]:bg-muted/50"
                        >
                            <input
                                v-model="form.channel_id"
                                type="radio"
                                :value="c.id"
                                class="accent-primary"
                            />
                            <ChannelBadge :type="c.type" size="md" />
                            <span class="text-sm">
                                <span class="block font-medium">{{
                                    c.name
                                }}</span>
                                <span class="text-xs text-muted-foreground">{{
                                    c.type_label
                                }}</span>
                            </span>
                        </label>
                    </div>
                    <p
                        v-if="channel?.type === 'messenger'"
                        class="flex gap-2 rounded-md bg-muted p-3 text-xs text-muted-foreground"
                    >
                        <Info class="size-4 shrink-0" />
                        Meta only allows Messenger messages to people who
                        messaged your Page in the last 24 hours, so this blast
                        only reaches them. For everyone else, use WhatsApp.
                    </p>
                    <InputError :message="form.errors.channel_id" />
                </section>

                <!-- 2. Audience -->
                <section class="space-y-4 rounded-xl border bg-card p-5">
                    <h2 class="font-medium">2. Who gets it</h2>
                    <div class="grid gap-2">
                        <Label
                            >Contacts with any of these tags
                            <span class="text-muted-foreground"
                                >(leave empty for everyone)</span
                            ></Label
                        >
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="tag in tags"
                                :key="tag.id"
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
                                :class="
                                    form.audience.tag_ids.includes(tag.id)
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'hover:bg-muted'
                                "
                                @click="toggle(form.audience.tag_ids, tag.id)"
                            >
                                <span
                                    class="size-2 rounded-full"
                                    :class="tagDotClasses[tag.color]"
                                />
                                {{ tag.name }}
                            </button>
                            <span
                                v-if="!tags.length"
                                class="text-xs text-muted-foreground"
                                >No tags yet.</span
                            >
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label>But not contacts tagged</Label>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="tag in tags"
                                :key="tag.id"
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
                                :class="
                                    form.audience.exclude_tag_ids.includes(
                                        tag.id,
                                    )
                                        ? 'border-destructive bg-destructive text-white'
                                        : 'hover:bg-muted'
                                "
                                @click="
                                    toggle(
                                        form.audience.exclude_tag_ids,
                                        tag.id,
                                    )
                                "
                            >
                                <span
                                    class="size-2 rounded-full"
                                    :class="tagDotClasses[tag.color]"
                                />
                                {{ tag.name }}
                            </button>
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label
                            >Lead status
                            <span class="text-muted-foreground"
                                >(leave empty for any)</span
                            ></Label
                        >
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="status in statuses"
                                :key="status.value"
                                type="button"
                                class="rounded-full border px-2.5 py-1 text-xs"
                                :class="
                                    form.audience.statuses.includes(
                                        status.value,
                                    )
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'hover:bg-muted'
                                "
                                @click="
                                    toggle(form.audience.statuses, status.value)
                                "
                            >
                                {{ status.label }}
                            </button>
                        </div>
                    </div>
                    <div
                        class="flex items-center gap-3 rounded-lg bg-muted p-3 text-sm"
                    >
                        <Users class="size-5 shrink-0 text-muted-foreground" />
                        <span v-if="audienceCount === null">Counting…</span>
                        <span v-else>
                            <strong>{{ audienceCount }}</strong>
                            {{ audienceCount === 1 ? 'person' : 'people' }} will
                            receive this
                            <span
                                v-if="audienceSample.length"
                                class="text-muted-foreground"
                            >
                                ({{ audienceSample.join(', ')
                                }}{{
                                    audienceCount > audienceSample.length
                                        ? ', …'
                                        : ''
                                }})</span
                            >. Opted-out contacts are always left out.
                        </span>
                    </div>
                    <InputError :message="form.errors.audience" />
                </section>

                <!-- 3. Message -->
                <section class="space-y-4 rounded-xl border bg-card p-5">
                    <h2 class="font-medium">3. Message</h2>

                    <template v-if="isWhatsApp">
                        <div
                            v-if="!channelTemplates.length"
                            class="rounded-md bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200"
                        >
                            WhatsApp blasts must use a template approved by
                            Meta. Create one in WhatsApp Manager (category
                            “Marketing”), then click
                            <strong>Sync templates</strong> under Admin →
                            Channels.
                        </div>
                        <template v-else>
                            <div class="grid gap-2">
                                <Label for="b-template"
                                    >Approved template</Label
                                >
                                <select
                                    id="b-template"
                                    v-model.number="form.whatsapp_template_id"
                                    class="h-9 rounded-md border bg-transparent px-2 text-sm"
                                >
                                    <option
                                        v-for="t in channelTemplates"
                                        :key="t.id"
                                        :value="t.id"
                                    >
                                        {{ t.name }} ({{ t.language }},
                                        {{ t.category?.toLowerCase() }})
                                    </option>
                                </select>
                                <InputError
                                    :message="form.errors.whatsapp_template_id"
                                />
                            </div>
                            <div
                                v-for="variable in template?.variables ?? []"
                                :key="variable.key"
                                class="grid gap-1.5"
                            >
                                <Label
                                    :for="`v-${variable.key}`"
                                    class="text-xs"
                                    >{{ variable.label }}</Label
                                >
                                <Input
                                    :id="`v-${variable.key}`"
                                    v-model="form.template_params[variable.key]"
                                    :placeholder="variable.example ?? ''"
                                />
                            </div>
                            <InputError
                                :message="form.errors.template_params"
                            />
                            <p
                                v-if="template?.variables.length"
                                class="text-xs text-muted-foreground"
                            >
                                Personalise with <code>{first_name}</code> or
                                <code>{name}</code>. Add a fallback for contacts
                                without a name: <code>{first_name|there}</code>.
                            </p>
                        </template>
                    </template>

                    <template v-else>
                        <div class="grid gap-2">
                            <Label for="b-body">Message</Label>
                            <Textarea
                                id="b-body"
                                v-model="form.body"
                                rows="5"
                                placeholder="Hi {first_name|there}! 🎉 Flash sale today only…"
                            />
                            <InputError :message="form.errors.body" />
                        </div>
                    </template>
                </section>

                <!-- 4. When -->
                <section class="space-y-4 rounded-xl border bg-card p-5">
                    <h2 class="font-medium">4. When</h2>
                    <div class="flex flex-wrap gap-2">
                        <label
                            class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm has-[:checked]:border-primary"
                        >
                            <input
                                v-model="form.when"
                                type="radio"
                                value="now"
                                class="accent-primary"
                            />
                            Send now
                        </label>
                        <label
                            class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm has-[:checked]:border-primary"
                        >
                            <input
                                v-model="form.when"
                                type="radio"
                                value="later"
                                class="accent-primary"
                            />
                            Schedule
                        </label>
                        <Input
                            v-if="form.when === 'later'"
                            v-model="form.scheduled_local"
                            type="datetime-local"
                            class="w-auto"
                        />
                    </div>
                    <InputError
                        :message="
                            (form.errors as Record<string, string>).scheduled_at
                        "
                    />
                    <p
                        v-if="minutesToSend > 1"
                        class="text-xs text-muted-foreground"
                    >
                        Messages go out at up to {{ ratePerMinute }} per minute,
                        so this takes about {{ minutesToSend }} minutes.
                    </p>
                </section>

                <div class="flex flex-wrap justify-end gap-2">
                    <Button
                        variant="outline"
                        :disabled="form.processing"
                        @click="submit('draft')"
                        >Save draft</Button
                    >
                    <Button
                        :disabled="form.processing || !audienceCount"
                        @click="confirmOpen = true"
                    >
                        <CalendarClock v-if="form.when === 'later'" /><Send
                            v-else
                        />
                        {{
                            form.when === 'later'
                                ? 'Schedule blast'
                                : 'Send blast'
                        }}
                    </Button>
                </div>
            </div>

            <!-- Preview + test -->
            <aside class="space-y-4 xl:sticky xl:top-4 xl:self-start">
                <div class="rounded-xl border bg-card p-4">
                    <p class="mb-3 text-xs font-medium text-muted-foreground">
                        Preview (for a contact named Kerry Tan)
                    </p>
                    <div class="rounded-xl bg-muted/60 p-3">
                        <!-- eslint-disable-next-line vue/no-v-html -- escaped in formatMessage() -->
                        <div
                            v-if="preview"
                            class="rounded-2xl rounded-br-md bg-emerald-100 px-3 py-2 text-sm whitespace-pre-wrap text-emerald-950 dark:bg-emerald-900/60 dark:text-emerald-50"
                            v-html="preview"
                        />
                        <p v-else class="text-sm text-muted-foreground">
                            Your message will appear here.
                        </p>
                    </div>
                </div>

                <div
                    v-if="isWhatsApp && template"
                    class="space-y-2 rounded-xl border bg-card p-4"
                >
                    <p class="text-sm font-medium">Send a test to yourself</p>
                    <div class="flex gap-2">
                        <Input v-model="testPhone" placeholder="012-345 6789" />
                        <Button
                            variant="outline"
                            :disabled="testing || !testPhone"
                            @click="sendTest"
                            >Test</Button
                        >
                    </div>
                    <InputError
                        :message="
                            (page.props.errors as Record<string, string>)?.phone
                        "
                    />
                </div>
            </aside>
        </div>
    </div>

    <Dialog v-model:open="confirmOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle
                    >{{ form.when === 'later' ? 'Schedule' : 'Send' }} this
                    blast to {{ audienceCount }}
                    {{
                        audienceCount === 1 ? 'person' : 'people'
                    }}?</DialogTitle
                >
                <DialogDescription>
                    <template v-if="isWhatsApp"
                        >Meta charges for each marketing template
                        message.</template
                    >
                    Messages can't be recalled once sent.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="secondary" @click="confirmOpen = false"
                    >Cancel</Button
                >
                <Button :disabled="form.processing" @click="submit('send')">
                    {{ form.when === 'later' ? 'Schedule' : 'Send now' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { useClipboard } from '@vueuse/core';
import {
    AlertTriangle,
    Check,
    CheckCircle2,
    Circle,
    Clock,
    Copy,
    ExternalLink,
    PartyPopper,
    RotateCcw,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import SetupController from '@/actions/App/Http/Controllers/Admin/SetupController';
import InboxController from '@/actions/App/Http/Controllers/Inbox/InboxController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Field = {
    name: string;
    label: string;
    type?: string;
    placeholder?: string;
    secret?: boolean;
    value: string;
};

type Step = {
    key: string;
    part: string;
    title: string;
    intro: string;
    instructions: string[];
    links: { label: string; url: string }[];
    fields: Field[];
    check: string | null;
    wait: boolean;
    optional: boolean;
    status: 'todo' | 'done' | 'waiting';
    result: string | null;
    has_saved_secret: boolean;
};

const props = defineProps<{
    steps: Step[];
    copyValues: Record<string, string>;
    demoMode: boolean;
    httpsOk: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Meta setup', href: SetupController.show() }],
    },
});

const { copy, copied, text: copiedText } = useClipboard();

const firstOpen = () =>
    props.steps.find((s) => s.status === 'todo')?.key ??
    props.steps[props.steps.length - 1].key;

const currentKey = ref(firstOpen());
const current = computed(() =>
    props.steps.find((s) => s.key === currentKey.value)!,
);
const currentIndex = computed(() =>
    props.steps.findIndex((s) => s.key === currentKey.value),
);

const doneCount = computed(
    () => props.steps.filter((s) => s.status !== 'todo').length,
);
const allDone = computed(() =>
    props.steps.every((s) => s.status === 'done' || s.optional),
);

const parts = computed(() => {
    const groups: { name: string; steps: Step[] }[] = [];

    for (const step of props.steps) {
        const group = groups.find((g) => g.name === step.part);
        if (group) {
            group.steps.push(step);
        } else {
            groups.push({ name: step.part, steps: [step] });
        }
    }

    return groups;
});

const form = useForm<Record<string, string | boolean>>({});

function resetForm(): void {
    const data: Record<string, string> = {};
    current.value.fields.forEach((f) => (data[f.name] = f.value ?? ''));
    form.defaults(data);
    form.reset();
    form.clearErrors();
}

watch(currentKey, resetForm, { immediate: true });

function submit(waiting = false): void {
    form.transform((data) => ({ ...data, waiting })).submit(
        SetupController.complete(current.value.key),
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                const next = props.steps
                    .slice(currentIndex.value + 1)
                    .find((s) => s.status === 'todo');

                if (next) {
                    currentKey.value = next.key;
                }
            },
        },
    );
}

function undo(): void {
    router.delete(SetupController.undo(current.value.key).url, {
        preserveScroll: true,
        preserveState: true,
    });
}

function skip(): void {
    const next = props.steps[currentIndex.value + 1];

    if (next) {
        currentKey.value = next.key;
    }
}

/** Instruction lines that contain a value to paste into Meta get a copy button. */
function copyable(line: string): string | null {
    return (
        Object.values(props.copyValues).find((v) => v && line.includes(v)) ??
        null
    );
}
</script>

<template>
    <Head title="Meta setup" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Connect WhatsApp & Facebook"
            description="Follow each step. Meta's pages open in a new tab — do what the step says there, come back and click Continue."
        />

        <div
            v-if="demoMode"
            class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-900 dark:bg-sky-950 dark:text-sky-200"
        >
            Demo mode is on (<code>META_FAKE=true</code>): checks don't contact
            Meta. Turn it off before doing this for real.
        </div>
        <div
            v-if="!httpsOk && !demoMode"
            class="flex gap-2 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200"
        >
            <AlertTriangle class="size-4 shrink-0" />
            The CRM isn't on an https:// address yet. Meta only delivers
            messages to HTTPS, so the webhook steps will fail until the site is
            online with SSL (see docs/DEPLOYMENT.md).
        </div>

        <div class="space-y-2">
            <div class="flex justify-between text-sm">
                <span>{{ doneCount }} of {{ steps.length }} steps done</span>
                <span class="text-muted-foreground"
                    >{{ Math.round((doneCount / steps.length) * 100) }}%</span
                >
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-muted">
                <div
                    class="h-full rounded-full bg-primary transition-all"
                    :style="{ width: `${(doneCount / steps.length) * 100}%` }"
                />
            </div>
        </div>

        <div
            v-if="allDone"
            class="flex flex-wrap items-center gap-4 rounded-xl border border-green-200 bg-green-50 p-5 dark:border-green-900 dark:bg-green-950"
        >
            <PartyPopper class="size-8 text-green-600" />
            <div class="flex-1">
                <p class="font-semibold">You're live!</p>
                <p class="text-sm text-muted-foreground">
                    Customer messages now arrive in the inbox.
                </p>
            </div>
            <Button as-child
                ><a :href="InboxController.index().url"
                    >Go to the inbox</a
                ></Button
            >
        </div>

        <div class="grid gap-6 lg:grid-cols-[18rem_1fr]">
            <!-- Step list -->
            <nav class="space-y-4">
                <div v-for="part in parts" :key="part.name">
                    <p
                        class="mb-1 px-2 text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        {{ part.name }}
                    </p>
                    <button
                        v-for="step in part.steps"
                        :key="step.key"
                        type="button"
                        class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm"
                        :class="
                            step.key === currentKey
                                ? 'bg-muted font-medium'
                                : 'hover:bg-muted/60'
                        "
                        @click="currentKey = step.key"
                    >
                        <CheckCircle2
                            v-if="step.status === 'done'"
                            class="size-4 shrink-0 text-green-600"
                        />
                        <Clock
                            v-else-if="step.status === 'waiting'"
                            class="size-4 shrink-0 text-amber-500"
                        />
                        <Circle
                            v-else
                            class="size-4 shrink-0 text-muted-foreground"
                        />
                        <span class="flex-1">{{ step.title }}</span>
                        <span
                            v-if="step.optional && step.status === 'todo'"
                            class="text-[10px] text-muted-foreground"
                            >optional</span
                        >
                    </button>
                </div>
            </nav>

            <!-- Current step -->
            <section class="space-y-5 rounded-xl border bg-card p-5 md:p-6">
                <div>
                    <p class="text-xs text-muted-foreground">
                        Step {{ currentIndex + 1 }} of {{ steps.length }} ·
                        {{ current.part }}
                    </p>
                    <h2 class="mt-1 text-lg font-semibold">
                        {{ current.title }}
                    </h2>
                    <p class="mt-2 text-sm text-muted-foreground">
                        {{ current.intro }}
                    </p>
                </div>

                <div
                    v-if="current.status !== 'todo'"
                    class="flex items-start gap-2 rounded-lg p-3 text-sm"
                    :class="
                        current.status === 'done'
                            ? 'bg-green-50 text-green-900 dark:bg-green-950 dark:text-green-200'
                            : 'bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-200'
                    "
                >
                    <Check
                        v-if="current.status === 'done'"
                        class="mt-0.5 size-4 shrink-0"
                    />
                    <Clock v-else class="mt-0.5 size-4 shrink-0" />
                    <span class="flex-1">
                        {{
                            current.status === 'done'
                                ? 'Done.'
                                : 'Waiting for Meta. Come back and click "Approved" when it is.'
                        }}
                        {{ current.result }}
                    </span>
                    <button
                        type="button"
                        class="flex items-center gap-1 text-xs underline"
                        @click="undo"
                    >
                        <RotateCcw class="size-3" /> Redo
                    </button>
                </div>

                <div v-if="current.links.length" class="flex flex-wrap gap-2">
                    <Button
                        v-for="link in current.links"
                        :key="link.url"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <a
                            :href="link.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            >{{ link.label }} <ExternalLink
                        /></a>
                    </Button>
                </div>

                <ol v-if="current.instructions.length" class="space-y-2">
                    <li
                        v-for="(line, i) in current.instructions"
                        :key="i"
                        class="flex gap-3 text-sm"
                    >
                        <span
                            class="flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium"
                            >{{ i + 1 }}</span
                        >
                        <span class="flex-1 pt-0.5">
                            {{ line }}
                            <button
                                v-if="copyable(line)"
                                type="button"
                                class="ml-1 inline-flex items-center gap-1 rounded bg-muted px-1.5 py-0.5 text-xs hover:bg-accent"
                                @click="copy(copyable(line)!)"
                            >
                                <Copy class="size-3" />
                                {{
                                    copied && copiedText === copyable(line)
                                        ? 'Copied!'
                                        : 'Copy'
                                }}
                            </button>
                        </span>
                    </li>
                </ol>

                <form
                    v-if="current.fields.length"
                    class="grid gap-4 rounded-lg border p-4"
                    @submit.prevent="submit()"
                >
                    <div
                        v-for="field in current.fields"
                        :key="field.name"
                        class="grid gap-1.5"
                    >
                        <Label :for="`f-${field.name}`">{{
                            field.label
                        }}</Label>
                        <Input
                            :id="`f-${field.name}`"
                            v-model="form[field.name] as string"
                            :type="field.type ?? 'text'"
                            autocomplete="off"
                            :placeholder="
                                field.secret && current.has_saved_secret
                                    ? 'Saved — leave empty to keep it'
                                    : field.placeholder
                            "
                        />
                        <InputError :message="form.errors[field.name]" />
                    </div>
                </form>

                <InputError :message="form.errors.check" />

                <div class="flex flex-wrap items-center gap-2 border-t pt-4">
                    <template v-if="current.wait">
                        <Button
                            variant="outline"
                            :disabled="form.processing"
                            @click="submit(true)"
                        >
                            <Clock /> Submitted — waiting for Meta
                        </Button>
                        <Button
                            :disabled="form.processing"
                            @click="submit(false)"
                            ><Check /> Approved</Button
                        >
                    </template>
                    <Button
                        v-else
                        :disabled="form.processing"
                        @click="submit()"
                    >
                        {{
                            current.check
                                ? 'Check & continue'
                                : 'Done, continue'
                        }}
                    </Button>
                    <Button
                        v-if="current.optional || current.status !== 'todo'"
                        variant="ghost"
                        @click="skip"
                    >
                        {{
                            current.status !== 'todo'
                                ? 'Next step'
                                : 'Skip for now'
                        }}
                    </Button>
                </div>
            </section>
        </div>
    </div>
</template>

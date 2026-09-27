<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import TemplateMessageController from '@/actions/App/Http/Controllers/Inbox/TemplateMessageController';
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
import { api, ApiError } from '@/lib/api';
import { usePage } from '@inertiajs/vue3';
import { fillPlaceholders, formatMessage } from '@/lib/messageText';
import { renderTemplatePreview } from '@/lib/templatePreview';
import type {
    ConversationSummary,
    Message,
    TemplateVariable,
    WhatsappTemplate,
} from '@/types';

type TemplateWithVariables = WhatsappTemplate & {
    variables: TemplateVariable[];
};

const props = defineProps<{
    conversation: ConversationSummary;
    templates: TemplateWithVariables[];
}>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ sent: [message: Message] }>();

const templateId = ref<number | null>(null);
const values = ref<Record<string, string>>({});
const sending = ref(false);
const error = ref<string | null>(null);

const template = computed(
    () => props.templates.find((t) => t.id === templateId.value) ?? null,
);

watch(open, (isOpen) => {
    if (isOpen) {
        templateId.value = props.templates[0]?.id ?? null;
        error.value = null;
    }
});

// Suggest {first_name} for the first variable, since most templates start with a greeting.
watch(template, (current) => {
    values.value = {};

    current?.variables.forEach((variable, index) => {
        values.value[variable.key] =
            index === 0 &&
            variable.kind === 'text' &&
            variable.key.startsWith('body.')
                ? '{first_name|there}'
                : '';
    });
});

const page = usePage();

// Show the preview as this customer will see it.
const preview = computed(() => {
    if (!template.value) {
        return '';
    }

    const filled = Object.fromEntries(
        Object.entries(values.value).map(([key, value]) => [
            key,
            fillPlaceholders(
                value,
                props.conversation.contact,
                page.props.auth.user.name,
            ),
        ]),
    );

    return formatMessage(renderTemplatePreview(template.value, filled));
});

async function send(): Promise<void> {
    if (!template.value) {
        return;
    }

    sending.value = true;
    error.value = null;

    try {
        const response = await api<{ message: Message }>(
            'post',
            TemplateMessageController.store(props.conversation.id).url,
            { template_id: template.value.id, values: values.value },
        );

        emit('sent', response.message);
        open.value = false;
    } catch (e) {
        error.value =
            e instanceof ApiError ? e.message : 'Could not send the template.';
    } finally {
        sending.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Send a WhatsApp template</DialogTitle>
                <DialogDescription>
                    Templates are pre-approved by Meta and can be sent at any
                    time, even after the 24-hour window. Meta charges per
                    template message.
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="!templates.length"
                class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                No approved templates yet. Create them in WhatsApp Manager, then
                ask an admin to click <strong>Sync templates</strong> under
                Admin → Channels.
            </div>

            <div v-else class="grid gap-5 sm:grid-cols-2">
                <div class="space-y-4">
                    <div class="grid gap-2">
                        <Label for="template">Template</Label>
                        <select
                            id="template"
                            v-model.number="templateId"
                            class="h-9 rounded-md border bg-transparent px-2 text-sm"
                        >
                            <option
                                v-for="t in templates"
                                :key="t.id"
                                :value="t.id"
                            >
                                {{ t.name }} ({{ t.language }})
                            </option>
                        </select>
                    </div>

                    <div
                        v-for="variable in template?.variables ?? []"
                        :key="variable.key"
                        class="grid gap-1.5"
                    >
                        <Label :for="variable.key" class="text-xs">{{
                            variable.label
                        }}</Label>
                        <Input
                            :id="variable.key"
                            v-model="values[variable.key]"
                            :placeholder="variable.example ?? ''"
                        />
                    </div>

                    <p
                        v-if="template?.variables.length"
                        class="text-xs text-muted-foreground"
                    >
                        You can use <code>{first_name}</code>,
                        <code>{name}</code> and <code>{agent_name}</code>. Add a
                        fallback like <code>{first_name|there}</code>.
                    </p>
                </div>

                <div class="rounded-xl bg-muted/60 p-4">
                    <p class="mb-2 text-xs font-medium text-muted-foreground">
                        Preview
                    </p>
                    <!-- eslint-disable-next-line vue/no-v-html -- escaped in formatMessage() -->
                    <div
                        class="rounded-2xl rounded-br-md bg-emerald-100 px-3 py-2 text-sm whitespace-pre-wrap text-emerald-950 dark:bg-emerald-900/60 dark:text-emerald-50"
                        v-html="preview"
                    />
                </div>
            </div>

            <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

            <DialogFooter>
                <Button variant="secondary" @click="open = false"
                    >Cancel</Button
                >
                <Button :disabled="!template || sending" @click="send">
                    Send template
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

export type ChannelType = 'whatsapp' | 'messenger';

export type ChannelSummary = {
    id: number;
    type: ChannelType;
    type_label: string;
    name: string;
    display_phone: string | null;
};

export type Tag = {
    id: number;
    name: string;
    color: string;
};

export type ContactStatus =
    | 'new'
    | 'contacted'
    | 'interested'
    | 'booked'
    | 'lost';

export type Contact = {
    id: number;
    name: string | null;
    display_name: string;
    phone: string | null;
    email: string | null;
    status: ContactStatus;
    source: string | null;
    avatar_url: string | null;
    opted_out: boolean;
    tags: Tag[];
    created_at: string | null;
};

export type AgentOption = {
    id: number;
    name: string;
    is_available?: boolean;
};

export type ConversationSummary = {
    id: number;
    status: 'open' | 'closed';
    channel: ChannelSummary;
    contact: Contact;
    assignee: { id: number; name: string } | null;
    last_message_at: string | null;
    last_message_preview: string | null;
    unread_count: number;
    window_open: boolean;
    window_expires_at: string | null;
};

export type MessageMedia = {
    url?: string;
    mime?: string | null;
    filename?: string | null;
    size?: number | null;
    remote_url?: string | null;
};

export type Message = {
    id: number;
    conversation_id: number;
    direction: 'inbound' | 'outbound' | 'event';
    type: string;
    body: string | null;
    media: MessageMedia | null;
    meta: Record<string, any> | null;
    status: 'received' | 'pending' | 'sent' | 'delivered' | 'read' | 'failed';
    error: string | null;
    user: { id: number; name: string } | null;
    created_at: string;
};

export type Note = {
    id: number;
    body: string;
    user: string | null;
    created_at: string;
    can_delete: boolean;
};

export type QuickReply = {
    id: number;
    shortcut: string;
    title: string;
    body: string;
    is_shared: boolean;
    owner: string | null;
    attachment: { name: string; mime: string; url: string } | null;
    can_manage: boolean;
};

export type TemplateComponent = {
    type: string;
    format?: string;
    text?: string;
    buttons?: { type: string; text: string; url?: string }[];
};

export type WhatsappTemplate = {
    id: number;
    channel_id: number;
    name: string;
    language: string;
    category: string | null;
    status: string | null;
    parameter_format: string | null;
    components: TemplateComponent[];
};

export type TemplateVariable = {
    key: string;
    label: string;
    kind: 'text' | 'media';
    example: string | null;
};

export type Option = { value: string; label: string };

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
};

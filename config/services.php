<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Meta (WhatsApp Cloud API + Facebook Messenger)
    |--------------------------------------------------------------------------
    |
    | Channel tokens (WhatsApp number, Facebook Page) are stored encrypted in
    | the database and managed from Admin -> Channels. The app-level values
    | below are shared by all channels and come from your Meta Developer App.
    |
    */

    'meta' => [
        'graph_url' => env('META_GRAPH_URL', 'https://graph.facebook.com'),
        'graph_version' => env('META_GRAPH_VERSION', 'v23.0'),
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),

        // Demo mode: messages are not sent to Meta, and admins get a
        // "simulate incoming message" tool so the CRM can be tried out
        // before the Meta setup is finished.
        'fake' => (bool) env('META_FAKE', false),

        // Messenger only allows replies within 24h of the customer's last
        // message. With Meta's "Human Agent" permission approved, agents can
        // reply for up to 7 days using the HUMAN_AGENT tag.
        'messenger_human_agent' => (bool) env('META_MESSENGER_HUMAN_AGENT', false),
    ],

];

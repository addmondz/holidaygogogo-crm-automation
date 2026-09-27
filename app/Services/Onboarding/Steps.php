<?php

namespace App\Services\Onboarding;

/**
 * The Meta setup wizard, from "no Facebook Page yet" to live.
 *
 * Meta renames menus from time to time: when a step goes out of date, edit
 * the text here. Placeholders like {app_id} are filled in once known.
 *
 * check: what the portal verifies before "Continue" (see SetupChecker).
 * wait:  true for steps Meta reviews itself (days); the admin may continue
 *        with other steps and come back.
 */
class Steps
{
    /**
     * @return list<array{key: string, part: string, title: string, intro: string, instructions: list<string>, links: list<array{label: string, url: string}>, fields: list<array<string, mixed>>, check: ?string, wait: bool, optional: bool}>
     */
    public static function all(): array
    {
        return array_map(fn (array $step) => [
            'instructions' => [],
            'links' => [],
            'fields' => [],
            'check' => null,
            'wait' => false,
            'optional' => false,
            ...$step,
        ], [
            // ---- Part A: Facebook & business -------------------------------------------
            [
                'key' => 'facebook_account',
                'part' => 'Facebook & business',
                'title' => 'Log in to Facebook',
                'intro' => 'Use the personal Facebook account of the business owner or manager. This person becomes the admin of everything below, so don\'t use a shared or fake account (Meta may disable it).',
                'instructions' => [
                    'Open Facebook and log in (or create an account).',
                    'Turn on two-factor authentication: Settings → Accounts Center → Password and security. Meta requires it for business tools.',
                ],
                'links' => [
                    ['label' => 'Open Facebook', 'url' => 'https://www.facebook.com/'],
                    ['label' => 'Two-factor settings', 'url' => 'https://accountscenter.facebook.com/password_and_security'],
                ],
            ],
            [
                'key' => 'facebook_page',
                'part' => 'Facebook & business',
                'title' => 'Create your Facebook Page',
                'intro' => 'Customers message this Page on Messenger. Skip this step if HolidayGoGoGo already has a Page.',
                'instructions' => [
                    'Click "Create Page".',
                    'Page name: HolidayGoGoGo. Category: Travel Agency.',
                    'Add a bio, your business phone, email, address and website.',
                    'Upload your logo (profile picture) and a cover photo, then click "Done".',
                ],
                'links' => [['label' => 'Create a Page', 'url' => 'https://www.facebook.com/pages/create']],
            ],
            [
                'key' => 'business_portfolio',
                'part' => 'Facebook & business',
                'title' => 'Create a Meta Business portfolio',
                'intro' => 'The business portfolio (formerly Business Manager) owns your Page, WhatsApp number and app. Skip creating one if you already have it.',
                'instructions' => [
                    'Click "Create an account" and enter the business name exactly as registered (e.g. with SSM), your name and a business email.',
                    'Confirm the email Meta sends you.',
                ],
                'links' => [['label' => 'Open Meta Business Suite', 'url' => 'https://business.facebook.com/overview']],
            ],
            [
                'key' => 'add_page_to_portfolio',
                'part' => 'Facebook & business',
                'title' => 'Add the Page to your business portfolio',
                'intro' => 'So the portfolio (and later the CRM) can manage the Page\'s messages.',
                'instructions' => [
                    'In Business settings, go to Accounts → Pages.',
                    'Click "Add" → "Add a Page" and pick your HolidayGoGoGo Page.',
                ],
                'links' => [['label' => 'Business settings → Pages', 'url' => 'https://business.facebook.com/settings/pages']],
            ],
            [
                'key' => 'business_verification',
                'part' => 'Facebook & business',
                'title' => 'Verify your business',
                'intro' => 'Meta checks that your business is real. It raises your WhatsApp sending limits and is needed for app review. Meta usually replies within a few days, so you can continue with the next steps while you wait.',
                'instructions' => [
                    'Go to Security Center and click "Start verification".',
                    'Enter the legal business name, address, phone and website exactly as on your documents.',
                    'Upload a document showing the business name and address, e.g. the SSM registration certificate, plus a utility bill or bank statement if asked.',
                    'Confirm by email, phone or domain as Meta offers.',
                    'Tick "done" below once submitted. Come back and mark it verified when Meta approves.',
                ],
                'links' => [['label' => 'Open Security Center', 'url' => 'https://business.facebook.com/settings/security']],
                'wait' => true,
            ],

            // ---- Part B: Meta app -------------------------------------------------------
            [
                'key' => 'create_app',
                'part' => 'Meta app',
                'title' => 'Create a Meta developer app',
                'intro' => 'The "app" is how the CRM talks to WhatsApp and Messenger. Nobody downloads it; it is just a technical connection.',
                'instructions' => [
                    'Open Meta for Developers and click "Get started" to register as a developer (first time only).',
                    'Click "Create app".',
                    'Use case: choose "Other", then app type "Business".',
                    'App name: "HolidayGoGoGo CRM". Business portfolio: choose yours. Click "Create app".',
                ],
                'links' => [['label' => 'Open My Apps', 'url' => 'https://developers.facebook.com/apps/']],
            ],
            [
                'key' => 'app_credentials',
                'part' => 'Meta app',
                'title' => 'Copy the App ID and App secret',
                'intro' => 'The CRM uses these to check that messages really come from Meta.',
                'instructions' => [
                    'In your app, open App settings → Basic.',
                    'Copy the App ID, then click "Show" next to App secret and copy it.',
                    'Paste both below. The secret is stored encrypted.',
                ],
                'links' => [['label' => 'Open App settings → Basic', 'url' => 'https://developers.facebook.com/apps/{app_id}/settings/basic/']],
                'fields' => [
                    ['name' => 'app_id', 'label' => 'App ID', 'placeholder' => '1234567890123456'],
                    ['name' => 'app_secret', 'label' => 'App secret', 'type' => 'password', 'secret' => true],
                ],
                'check' => 'app_credentials',
            ],
            [
                'key' => 'add_whatsapp',
                'part' => 'Meta app',
                'title' => 'Add WhatsApp and Messenger to the app',
                'intro' => 'Turns on the two products the CRM uses.',
                'instructions' => [
                    'On the app dashboard, find "WhatsApp" and click "Set up". Choose your business portfolio when asked.',
                    'Go back to the dashboard, find "Messenger" and click "Set up".',
                ],
                'links' => [['label' => 'Open app dashboard', 'url' => 'https://developers.facebook.com/apps/{app_id}/dashboard/']],
            ],
            [
                'key' => 'system_token',
                'part' => 'Meta app',
                'title' => 'Create a permanent access token',
                'intro' => 'A "system user" is a robot account for the CRM. Its token never expires (the temporary token on the WhatsApp page expires in 24 hours).',
                'instructions' => [
                    'Go to Business settings → Users → System users and click "Add". Name: "CRM", role: Admin.',
                    'Select the new system user → "Assign assets": give Full control of your app, your WhatsApp account and your Facebook Page.',
                    'Click "Generate new token", pick your app, set expiry to "Never".',
                    'Tick these permissions: whatsapp_business_messaging, whatsapp_business_management, pages_messaging, pages_manage_metadata, pages_show_list, pages_read_engagement, business_management.',
                    'Click "Generate token", copy it and paste it below. Treat it like a password.',
                ],
                'links' => [['label' => 'Open System users', 'url' => 'https://business.facebook.com/settings/system-users']],
                'fields' => [
                    ['name' => 'system_token', 'label' => 'System user access token', 'type' => 'password', 'secret' => true, 'placeholder' => 'EAAG…'],
                ],
                'check' => 'system_token',
            ],

            // ---- Part C: WhatsApp ---------------------------------------------------------
            [
                'key' => 'whatsapp_number',
                'part' => 'WhatsApp',
                'title' => 'Add your WhatsApp number',
                'intro' => 'Important: a number registered here stops working in the normal WhatsApp / WhatsApp Business app on your phone. Use a new number, or back up your chats first. (To keep using the phone app on the same number, "coexistence", you need a Meta Business Partner.)',
                'instructions' => [
                    'In your app, open WhatsApp → API Setup.',
                    'Under "Send and receive messages", click "Add phone number".',
                    'Enter the display name (e.g. HolidayGoGoGo), category Travel, and your number. Verify it with the SMS or call code.',
                    'In WhatsApp Manager, add a payment method (Meta charges for template messages and blasts).',
                ],
                'links' => [
                    ['label' => 'Open WhatsApp → API Setup', 'url' => 'https://developers.facebook.com/apps/{app_id}/whatsapp-business/wa-dev-console/'],
                    ['label' => 'Open WhatsApp Manager', 'url' => 'https://business.facebook.com/wa/manage/home/'],
                ],
            ],
            [
                'key' => 'whatsapp_ids',
                'part' => 'WhatsApp',
                'title' => 'Connect the number to the CRM',
                'intro' => 'The CRM needs two IDs to send and receive messages on your number.',
                'instructions' => [
                    'On WhatsApp → API Setup, choose your real number in the "From" list.',
                    'Copy the "Phone number ID" and the "WhatsApp Business Account ID" shown under it, and paste them below.',
                ],
                'links' => [['label' => 'Open WhatsApp → API Setup', 'url' => 'https://developers.facebook.com/apps/{app_id}/whatsapp-business/wa-dev-console/']],
                'fields' => [
                    ['name' => 'phone_number_id', 'label' => 'Phone number ID', 'placeholder' => '106540352242922'],
                    ['name' => 'waba_id', 'label' => 'WhatsApp Business Account ID', 'placeholder' => '102290129340398'],
                ],
                'check' => 'whatsapp_number',
            ],
            [
                'key' => 'whatsapp_webhook',
                'part' => 'WhatsApp',
                'title' => 'Send WhatsApp messages to the CRM (webhook)',
                'intro' => 'This tells Meta where to deliver your customers\' messages.',
                'instructions' => [
                    'In your app, open WhatsApp → Configuration → Webhook → "Edit".',
                    'Callback URL: {whatsapp_webhook}',
                    'Verify token: {verify_token}',
                    'Click "Verify and save". Then, under "Webhook fields", click "Manage" and subscribe to "messages".',
                    'Click "Check" below.',
                ],
                'links' => [['label' => 'Open WhatsApp → Configuration', 'url' => 'https://developers.facebook.com/apps/{app_id}/whatsapp-business/wa-settings/']],
                'check' => 'whatsapp_webhook',
            ],
            [
                'key' => 'whatsapp_test',
                'part' => 'WhatsApp',
                'title' => 'Send a test message',
                'intro' => 'Proves everything works end to end.',
                'instructions' => [
                    'From your own phone, send a WhatsApp message (e.g. "Hello") to your business number {display_phone}.',
                    'Click "Check". It should appear in the CRM inbox within a few seconds.',
                ],
                'check' => 'whatsapp_test',
            ],

            // ---- Part D: Messenger ---------------------------------------------------------
            [
                'key' => 'messenger_page',
                'part' => 'Messenger',
                'title' => 'Connect your Facebook Page',
                'intro' => 'So Messenger conversations with your Page appear in the CRM.',
                'instructions' => [
                    'Open your Page, go to About → Page transparency (or Settings) and copy the Page ID.',
                    'Paste it below. The CRM checks the Page and subscribes to its messages.',
                ],
                'links' => [['label' => 'Your Pages', 'url' => 'https://www.facebook.com/pages/?category=your_pages']],
                'fields' => [['name' => 'page_id', 'label' => 'Page ID', 'placeholder' => '105123456789012']],
                'check' => 'messenger_page',
                'optional' => true,
            ],
            [
                'key' => 'messenger_webhook',
                'part' => 'Messenger',
                'title' => 'Send Messenger messages to the CRM (webhook)',
                'intro' => 'Same as for WhatsApp, but in the Messenger settings.',
                'instructions' => [
                    'In your app, open Messenger → Settings (Messenger API Settings) → Webhooks.',
                    'Callback URL: {messenger_webhook}',
                    'Verify token: {verify_token}',
                    'Click "Verify and save", then subscribe to: messages, messaging_postbacks, message_deliveries, message_reads, message_echoes.',
                    'Click "Check" below.',
                ],
                'links' => [['label' => 'Open Messenger settings', 'url' => 'https://developers.facebook.com/apps/{app_id}/messenger/messenger_api_settings/']],
                'check' => 'messenger_webhook',
                'optional' => true,
            ],
            [
                'key' => 'app_review',
                'part' => 'Messenger',
                'title' => 'Request Messenger permission (app review)',
                'intro' => 'Until Meta approves this, Messenger only works for people with a role on your app. Review usually takes a few days to two weeks; you can finish the other steps meanwhile.',
                'instructions' => [
                    'In your app, open App Review → Permissions and Features.',
                    'Request "Advanced Access" for pages_messaging (and pages_manage_metadata).',
                    'Explain the use: "Our travel agents reply to customer enquiries sent to our Facebook Page from our CRM." Record a short screen video of replying to a Messenger chat in the CRM.',
                    'Tick "done" once submitted.',
                ],
                'links' => [['label' => 'Open App Review', 'url' => 'https://developers.facebook.com/apps/{app_id}/app-review/permissions/']],
                'wait' => true,
                'optional' => true,
            ],

            // ---- Part E: Finish -------------------------------------------------------------
            [
                'key' => 'templates',
                'part' => 'Finish',
                'title' => 'Create message templates',
                'intro' => 'Templates are pre-approved messages for blasts and for restarting chats after 24 hours. Approval takes minutes to hours.',
                'instructions' => [
                    'In WhatsApp Manager, go to Message templates → "Create template".',
                    'Category "Marketing" for promotions, "Utility" for booking updates. Use {{1}}, {{2}} for personal details, e.g. "Hi {{1}}! Our {{2}} tour has new dates…"',
                    'Add a footer "Reply STOP to unsubscribe".',
                    'When at least one is approved, click "Check" to copy them into the CRM.',
                ],
                'links' => [['label' => 'Open Message templates', 'url' => 'https://business.facebook.com/wa/manage/message-templates/?waba_id={waba_id}']],
                'check' => 'templates',
            ],
            [
                'key' => 'go_live',
                'part' => 'Finish',
                'title' => 'Switch the app to Live',
                'intro' => 'In Development mode Meta only delivers to test users. Going Live needs a privacy policy link; the CRM has one ready.',
                'instructions' => [
                    'In App settings → Basic, set "Privacy policy URL" to {privacy_url} and pick a category.',
                    'Save, then flip the "App mode" switch at the top from Development to Live.',
                ],
                'links' => [
                    ['label' => 'Open App settings → Basic', 'url' => 'https://developers.facebook.com/apps/{app_id}/settings/basic/'],
                    ['label' => 'View your privacy policy', 'url' => '{privacy_url}'],
                ],
            ],
        ]);
    }
}

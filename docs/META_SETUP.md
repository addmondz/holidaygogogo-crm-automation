# Connecting WhatsApp and Facebook Messenger (Meta setup)

The CRM uses Meta's **official** APIs: the WhatsApp Business Platform (Cloud API) and the Messenger Platform. This guide takes you from "I have a WhatsApp number and a Facebook Page" to messages flowing into the CRM.

Meta's screens change often, so the menu names below may differ slightly. Your CRM must already be online with **HTTPS** (see [DEPLOYMENT.md](DEPLOYMENT.md)), because Meta only sends webhooks to HTTPS addresses.

---

## 0. What you need

- A **Meta Business portfolio** (business.facebook.com) that owns your Facebook Page.
- Admin access to the portfolio and the Page.
- The WhatsApp number you want to use, able to receive an SMS or voice call for verification.
- Recommended: **Business verification** (Business settings → Security Center). It raises your WhatsApp sending limits and is required for some permissions.

## 1. About your current WhatsApp number

A number can be connected to the API in one of these ways:

| Your situation                                                          | What to do                                                                                                                                                                                                                                                                                      |
| ----------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **New number, or one you can stop using in the app**                    | Register it directly on the Cloud API (step 3). It then works only through the CRM, not the phone app.                                                                                                                                                                                          |
| **You want to keep using the WhatsApp Business app on the same number** | Meta supports this as **"coexistence"**. It is set up through Meta's _Embedded Signup_ flow, usually via a Meta Business Partner (BSP) or by registering your app as a _Tech Provider_. Messages sent from the phone then also appear in the CRM (subscribe to `smb_message_echoes` in step 5). |
| **It's a normal (personal) WhatsApp number**                            | Back up your chats, then use one of the options above. A personal account can't be connected to the API directly.                                                                                                                                                                               |

> Avoid "unofficial" WhatsApp Web automation tools for blasting. Meta regularly bans numbers that use them.

## 2. Create a Meta app

1. Go to **developers.facebook.com → My Apps → Create app**.
2. Choose **Other → Business** (or the "Connect with customers through WhatsApp" use case) and link it to your Business portfolio.
3. In the app, open **App settings → Basic** and copy:
    - **App ID** → `META_APP_ID` in `.env`
    - **App secret** → `META_APP_SECRET` in `.env`
4. Set `META_WEBHOOK_VERIFY_TOKEN` in `.env` to any long random text.
5. On the server, run `php artisan config:cache`.

## 3. Add WhatsApp to the app

1. In the app dashboard, add the **WhatsApp** product.
2. Under **WhatsApp → API Setup**, add your phone number and verify it by SMS or call. Set the display name (Meta reviews it).
3. Note these IDs (you'll paste them into the CRM):
    - **Phone number ID**
    - **WhatsApp Business Account ID**
4. Add a payment method in WhatsApp Manager. Template messages (blasts, and messages after the 24-hour window) are charged by Meta.

## 4. Create a permanent access token

Temporary tokens from the API Setup page expire in 24 hours, so use a **System User** instead:

1. **Business settings → Users → System users → Add**. Name it "CRM" and give it the **Admin** role.
2. **Add assets** to this system user:
    - your **app** (full control)
    - your **WhatsApp account** (full control)
    - your **Facebook Page** (full control, needed for Messenger)
3. **Generate new token** for your app with these permissions:
    - `whatsapp_business_messaging`, `whatsapp_business_management`
    - `pages_messaging`, `pages_manage_metadata`, `pages_show_list`, `pages_read_engagement` (for Messenger)
    - Expiration: **Never**
4. Copy the token. You'll paste it into the CRM in step 7. Treat it like a password.

## 5. Set up the WhatsApp webhook

In the CRM, open **Admin → Channels** and scroll to **Meta webhook settings** to find your URLs.

In the Meta app go to **WhatsApp → Configuration → Webhook → Edit**:

- **Callback URL:** `https://YOUR-CRM-DOMAIN/webhooks/whatsapp`
- **Verify token:** the same value as `META_WEBHOOK_VERIFY_TOKEN`

Click **Verify and save**, then subscribe to these **webhook fields**:

- `messages` (required: incoming messages and delivered/read receipts)
- `smb_message_echoes` (only if you use coexistence)

## 6. Set up Messenger (Facebook Page)

1. Add the **Messenger** product to the app.
2. Under **Messenger → Settings → Webhooks**, add:
    - **Callback URL:** `https://YOUR-CRM-DOMAIN/webhooks/messenger`
    - **Verify token:** the same `META_WEBHOOK_VERIFY_TOKEN`
    - Subscription fields: `messages`, `messaging_postbacks`, `message_deliveries`, `message_reads`, `message_echoes`
3. Note your **Page ID** (Facebook Page → About → Page transparency, or the Messenger settings screen).
4. **App Review:** to message customers who aren't admins or testers of your app, request **Advanced Access** for `pages_messaging` (and **Human Agent** if you want 7-day replies; then set `META_MESSENGER_HUMAN_AGENT=true`).

## 7. Connect the channels in the CRM

In **Admin → Channels**:

1. **+ WhatsApp number:** enter the phone number ID, WhatsApp Business Account ID, display number and the system user token. Save.
2. **+ Facebook Page:** enter the Page ID and the same system user token. The CRM uses it as the Page token.
3. Click **Test connection** on each channel. This checks the token and subscribes the app to that number's or Page's webhooks.
4. Click **Sync templates** on the WhatsApp channel.

Make sure `META_FAKE=false` in `.env` (demo mode off).

## 8. Switch the app to Live

In the Meta app dashboard, switch **App mode** from Development to **Live**. You need a Privacy Policy URL for this. In Development mode, WhatsApp can only message numbers on the test list, and Messenger only works for app admins and testers.

## 9. Test

1. Send a WhatsApp message to your business number from another phone. It should appear in the CRM inbox within a second or two.
2. Reply from the CRM. You should see ✓, then ✓✓, then blue ✓✓.
3. Send your Facebook Page a message and reply from the CRM.

If nothing arrives, check `storage/logs/laravel.log` and see the Troubleshooting section in the README.

---

## WhatsApp templates (for blasts and follow-ups)

Templates are created in **WhatsApp Manager → Message templates** and approved by Meta, usually within minutes to hours.

- Category **Marketing** for promotions and **Utility** for updates about an existing booking.
- Use variables like `{{1}}` for personal details. In the CRM you can fill them with `{first_name}`, `{name}` or fixed text.
- Always offer an opt-out, e.g. a footer "Reply STOP to unsubscribe". The CRM handles STOP automatically.
- After creating or editing templates, click **Sync templates** in Admin → Channels.

Example marketing template:

```
Header:  ✈️ {{1}} Special
Body:    Hi {{1}}! Our {{2}} tour has limited seats at {{3}}. Reply YES and our team will send you the full itinerary.
Footer:  Reply STOP to unsubscribe
```

## Sending limits and quality

- Meta limits how many **different customers** you can start conversations with (via templates) per 24 hours. New numbers start low, and the limit rises automatically as you send good-quality messages. Business verification also helps.
- If many people block or report your messages, your **quality rating** drops (shown under Test connection) and Meta may lower your limit. Send blasts only to people who expect them, and use tags to target them.
- The CRM sends blasts at up to `CRM_BROADCAST_PER_MINUTE` (default 300) messages per minute.

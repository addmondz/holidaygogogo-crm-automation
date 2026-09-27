<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy Policy - {{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 40px auto; padding: 0 20px; line-height: 1.6; color: #1f2937; }
        h1 { font-size: 1.75rem; } h2 { font-size: 1.15rem; margin-top: 2rem; }
    </style>
</head>
<body>
    <h1>Privacy Policy</h1>
    <p>Last updated: {{ now()->format('j F Y') }}</p>

    <p>HolidayGoGoGo ("we") uses this system to reply to enquiries customers send us on WhatsApp and Facebook Messenger.</p>

    <h2>What we collect</h2>
    <p>When you message us, we receive your name, phone number or Facebook ID, profile photo (if shared by Meta), and the messages, photos and files you send.</p>

    <h2>How we use it</h2>
    <p>Only to answer your enquiries, arrange your travel bookings, and, if you agreed, send you travel offers. We do not sell your data.</p>

    <h2>Marketing messages</h2>
    <p>You can stop receiving offers at any time by replying <strong>STOP</strong>.</p>

    <h2>Who can see it</h2>
    <p>Only our staff, and the service providers we use to run this system (Meta, which operates WhatsApp and Messenger, and our hosting provider).</p>

    <h2>How long we keep it</h2>
    <p>As long as needed for your bookings and our legal obligations. You can ask us to delete your data.</p>

    <h2>Your rights and contact</h2>
    <p>To see, correct or delete your data, message us on WhatsApp or Facebook, or email {{ config('mail.from.address') }}.</p>
</body>
</html>

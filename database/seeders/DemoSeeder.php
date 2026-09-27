<?php

namespace Database\Seeders;

use App\Enums\ChannelType;
use App\Enums\ContactStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\QuickReply;
use App\Models\Tag;
use App\Models\User;
use App\Models\WhatsappTemplate;
use Illuminate\Database\Seeder;

/**
 * Sample data to explore the CRM in demo mode (META_FAKE=true):
 *   php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $aisyah = User::where('email', 'agent@example.com')->firstOrFail();
        $ben = User::query()->firstOrCreate(
            ['email' => 'ben@example.com'],
            ['name' => 'Ben Lim', 'password' => 'password', 'role' => UserRole::Agent],
        );

        $whatsapp = Channel::query()->firstOrCreate(
            ['type' => ChannelType::WhatsApp, 'external_id' => '100000000000001'],
            ['name' => 'HolidayGoGoGo WhatsApp', 'business_account_id' => '200000000000001', 'display_phone' => '+60 12-888 8888', 'access_token' => 'demo'],
        );
        $messenger = Channel::query()->firstOrCreate(
            ['type' => ChannelType::Messenger, 'external_id' => '300000000000001'],
            ['name' => 'HolidayGoGoGo Facebook Page', 'access_token' => 'demo'],
        );

        $tags = collect([
            ['Japan', 'red', ['japan', 'tokyo', 'osaka', 'hokkaido', 'kyoto']],
            ['Korea', 'blue', ['korea', 'seoul', 'busan', 'jeju']],
            ['Bali', 'teal', ['bali']],
            ['Hot lead', 'orange', []],
            ['Deposit paid', 'green', []],
            ['Group tour', 'purple', ['group', 'family']],
        ])->mapWithKeys(fn ($t) => [$t[0] => Tag::query()->firstOrCreate(['name' => $t[0]], ['color' => $t[1], 'keywords' => $t[2]])]);

        foreach ([
            ['price', 'Japan tour price', "Hi {first_name|there}! 😊 Our 7D6N Japan (Tokyo–Osaka) tour starts from RM4,999 per person, including flights, 4-star hotels and a local guide.\n\nWhich month are you planning to travel?"],
            ['bank', 'Bank details for deposit', "To secure your seats, please pay a deposit of RM500 per person to:\n\n*HolidayGoGoGo Travel Sdn Bhd*\nMaybank 5123 4567 8901\n\nSend us the receipt here once done. Thank you! 🙏"],
            ['thanks', 'Thank you', 'Thank you {first_name}! {agent_name} from HolidayGoGoGo here — let me know if you have any other questions. Have a great day! 🌴'],
            ['visa', 'Visa info', 'Malaysians do not need a visa for Japan or Korea for stays of up to 90 days. Please make sure your passport is valid for at least 6 months. ✈️'],
        ] as [$shortcut, $title, $body]) {
            QuickReply::query()->firstOrCreate(
                ['shortcut' => $shortcut],
                ['title' => $title, 'body' => $body, 'is_shared' => true, 'user_id' => $admin->id],
            );
        }

        WhatsappTemplate::query()->firstOrCreate(
            ['channel_id' => $whatsapp->id, 'name' => 'tour_promo', 'language' => 'en'],
            [
                'category' => 'MARKETING',
                'status' => 'APPROVED',
                'parameter_format' => 'POSITIONAL',
                'components' => [
                    ['type' => 'HEADER', 'format' => 'TEXT', 'text' => '✈️ {{1}} Special'],
                    ['type' => 'BODY', 'text' => 'Hi {{1}}! Our {{2}} tour has limited seats at {{3}}. Reply YES and our team will send you the full itinerary.', 'example' => ['body_text' => [['Kerry', 'Hokkaido Winter', 'RM5,299']]]],
                    ['type' => 'FOOTER', 'text' => 'Reply STOP to unsubscribe'],
                ],
            ],
        );
        WhatsappTemplate::query()->firstOrCreate(
            ['channel_id' => $whatsapp->id, 'name' => 'follow_up', 'language' => 'en'],
            [
                'category' => 'UTILITY',
                'status' => 'APPROVED',
                'parameter_format' => 'POSITIONAL',
                'components' => [
                    ['type' => 'BODY', 'text' => 'Hi {{1}}, just following up on your enquiry about {{2}}. Would you like us to hold seats for you?', 'example' => ['body_text' => [['Kerry', 'the Japan tour']]]],
                ],
            ],
        );

        $chats = [
            [$whatsapp, 'Kerry Tan', '60123456789', $aisyah, ContactStatus::Interested, ['Japan', 'Hot lead'], 25, [
                ['in', 'Hi, how much is the Japan tour in December?'],
                ['out', "Hi Kerry! 😊 Our 7D6N Japan (Tokyo–Osaka) tour starts from RM4,999 per person, including flights, 4-star hotels and a local guide.\n\nWhich month are you planning to travel?", $aisyah],
                ['in', 'December, 4 pax. Can we see the itinerary?'],
            ]],
            [$whatsapp, 'Siti Nurhaliza', '60198765432', null, ContactStatus::New, ['Bali'], 8, [
                ['in', 'Salam, ada pakej Bali untuk honeymoon?'],
            ]],
            [$messenger, 'Jason Wong', null, $ben, ContactStatus::Booked, ['Korea', 'Deposit paid'], 180, [
                ['in', 'Hi, is the Seoul autumn tour still available?'],
                ['out', 'Yes! We still have seats on 12 Oct. Shall I reserve for you?', $ben],
                ['in', 'Yes please, 2 pax. Deposit transferred.'],
                ['out', 'Received, thank you Jason! You are all set 🎉', $ben],
            ]],
            [$whatsapp, 'Priya Raman', '60112233445', null, ContactStatus::New, ['Group tour'], 60 * 30, [
                ['in', 'Hello, we are a family of 8 looking for a group tour to Korea in March.'],
            ]],
        ];

        foreach ($chats as [$channel, $name, $phone, $agent, $status, $tagNames, $minutesAgo, $lines]) {
            $contact = $phone
                ? Contact::query()->firstOrCreate(['phone' => $phone], ['name' => $name, 'status' => $status, 'source' => $channel->type->value])
                : Contact::query()->firstOrCreate(['name' => $name], ['status' => $status, 'source' => $channel->type->value]);
            $contact->tags()->syncWithoutDetaching($tags->only($tagNames)->pluck('id'));

            $conversation = Conversation::query()->firstOrCreate(
                ['channel_id' => $channel->id, 'external_id' => $phone ?? 'demo-'.$contact->id],
                ['contact_id' => $contact->id, 'assigned_user_id' => $agent?->id],
            );

            if ($conversation->messages()->exists()) {
                continue;
            }

            $time = now()->subMinutes($minutesAgo + count($lines) * 3);

            foreach ($lines as $line) {
                $time = $time->addMinutes(3);
                $inbound = $line[0] === 'in';

                $message = $conversation->messages()->create([
                    'direction' => $inbound ? MessageDirection::Inbound : MessageDirection::Outbound,
                    'type' => MessageType::Text,
                    'body' => $line[1],
                    'status' => $inbound ? MessageStatus::Received : MessageStatus::Read,
                    'user_id' => $line[2]->id ?? null,
                    'external_id' => ($inbound ? 'wamid.DEMO' : 'wamid.DEMOOUT').uniqid(),
                    'created_at' => $time,
                ]);

                $conversation->fill([
                    'last_message_at' => $time,
                    'last_message_preview' => $message->preview(),
                    'last_inbound_at' => $inbound ? $time : $conversation->last_inbound_at,
                    'unread_count' => $inbound ? $conversation->unread_count + 1 : 0,
                ])->save();
            }
        }
    }
}

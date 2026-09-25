<?php

namespace Tests\Feature\Broadcasts;

use App\Models\Contact;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ContactImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_import_contacts_from_csv()
    {
        $vip = Tag::factory()->create(['name' => 'VIP']);
        Contact::factory()->create(['phone' => '60123456789', 'name' => 'Existing Name', 'email' => null]);

        $csv = "\u{FEFF}Name,Mobile,Email,Tags,Status\n"
            ."Kerry Tan,012-345 6789,kerry@example.com,Japan;Hot Lead,interested\n"
            ."Siti,+60 19-876 5432,,Bali,\n"
            ."Bad Row,abc,,,\n"
            ."Jason,0060111222333,jason@example.com,vip,booked\n";

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.contacts.import'), [
                'file' => UploadedFile::fake()->createWithContent('leads.csv', $csv),
                'tag_ids' => [$vip->id],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(3, Contact::count());

        $kerry = Contact::where('phone', '60123456789')->sole();
        $this->assertSame('Existing Name', $kerry->name);
        $this->assertSame('kerry@example.com', $kerry->email);
        $this->assertSame('interested', $kerry->status->value);
        $this->assertEqualsCanonicalizing(['Hot Lead', 'Japan', 'VIP'], $kerry->tags->pluck('name')->all());

        $siti = Contact::where('phone', '60198765432')->sole();
        $this->assertSame('new', $siti->status->value);
        $this->assertSame('import', $siti->source);

        // Tags are matched case-insensitively.
        $this->assertSame(['VIP'], Contact::where('phone', '60111222333')->sole()->tags->pluck('name')->all());
        $this->assertSame(4, Tag::count());
    }

    public function test_csv_without_phone_column_is_rejected()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.contacts.import'), [
                'file' => UploadedFile::fake()->createWithContent('leads.csv', "Name,Email\nKerry,k@example.com\n"),
            ])
            ->assertSessionHasErrors('file');
    }

    public function test_agents_cannot_import()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('admin.contacts.import'), ['file' => UploadedFile::fake()->createWithContent('a.csv', "phone\n0123456789\n")])
            ->assertForbidden();
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ContactStatus;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Tag;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use SplFileObject;

/**
 * Import leads from a spreadsheet (CSV) so they can be reached with blasts.
 *
 * Columns (header row, any order): name, phone, email, status, tags.
 * Tags are separated by ";" and created if they don't exist yet.
 */
class ContactImportController extends Controller
{
    public const MAX_ROWS = 10000;

    private const HEADER_ALIASES = [
        'name' => ['name', 'full name', 'customer', 'customer name', 'nama'],
        'phone' => ['phone', 'phone number', 'mobile', 'whatsapp', 'number', 'contact', 'tel', 'no tel', 'telefon'],
        'email' => ['email', 'e-mail', 'email address'],
        'status' => ['status', 'lead status'],
        'tags' => ['tags', 'tag', 'labels'],
    ];

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', Rule::exists(Tag::class, 'id')],
        ]);

        $file = new SplFileObject($request->file('file')->getRealPath());
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);

        $columns = null;
        $created = $updated = $skipped = 0;
        $problems = [];
        $tagCache = [];
        $extraTagIds = array_map('intval', $validated['tag_ids'] ?? []);

        foreach ($file as $index => $row) {
            if (! is_array($row) || $row === [null]) {
                continue;
            }

            if ($columns === null) {
                $columns = $this->columns($row);

                if (! isset($columns['phone'])) {
                    throw ValidationException::withMessages(['file' => __('The first row must have column names, including "phone".')]);
                }

                continue;
            }

            if ($created + $updated + $skipped >= self::MAX_ROWS) {
                $problems[] = __('Stopped after :max rows. Split the file to import more.', ['max' => self::MAX_ROWS]);
                break;
            }

            $value = fn (string $key) => isset($columns[$key]) ? trim((string) ($row[$columns[$key]] ?? '')) : '';
            $line = $index + 1;
            $phone = Phone::normalize($value('phone'));

            if (! $phone) {
                $skipped++;
                $problems[] = __('Row :line: invalid phone ":phone".', ['line' => $line, 'phone' => Str::limit($value('phone'), 20)]);

                continue;
            }

            $email = filter_var($value('email'), FILTER_VALIDATE_EMAIL) ?: null;
            $status = ContactStatus::tryFrom(Str::lower($value('status')));

            $contact = Contact::query()->firstOrNew(['phone' => $phone]);
            $isNew = ! $contact->exists;

            // Never overwrite details the team already has.
            $contact->name = $contact->name ?: ($value('name') ?: null);
            $contact->email = $contact->email ?: $email;
            $contact->source = $contact->source ?: 'import';

            if ($isNew || $status) {
                $contact->status = $status ?? $contact->status ?? ContactStatus::New;
            }

            $contact->save();

            $tagIds = [...$extraTagIds, ...$this->tagIds($value('tags'), $tagCache)];

            if ($tagIds) {
                $contact->tags()->syncWithoutDetaching(array_unique($tagIds));
            }

            $isNew ? $created++ : $updated++;
        }

        $message = __(':created added, :updated updated, :skipped skipped.', compact('created', 'updated', 'skipped'));

        Inertia::flash('toast', [
            'type' => $skipped ? 'warning' : 'success',
            'message' => $problems ? $message.' '.implode(' ', array_slice($problems, 0, 3)).(count($problems) > 3 ? ' …' : '') : $message,
        ]);

        return back();
    }

    /**
     * @param  list<string|null>  $header
     * @return array<string, int>
     */
    private function columns(array $header): array
    {
        $columns = [];

        foreach ($header as $position => $label) {
            // Strip a UTF-8 byte-order mark that Excel adds.
            $label = Str::of((string) $label)->replace("\u{FEFF}", '')->lower()->replace(['_', '.'], ' ')->squish()->value();

            foreach (self::HEADER_ALIASES as $key => $aliases) {
                if (! isset($columns[$key]) && in_array($label, $aliases, true)) {
                    $columns[$key] = $position;
                }
            }
        }

        return $columns;
    }

    /**
     * @param  array<string, int>  $cache
     * @return list<int>
     */
    private function tagIds(string $value, array &$cache): array
    {
        $ids = [];

        foreach (preg_split('/[;|]/', $value) ?: [] as $name) {
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $key = Str::lower($name);
            $cache[$key] ??= Tag::query()->whereRaw('lower(name) = ?', [$key])->value('id')
                ?? Tag::query()->create(['name' => Str::limit($name, 50, ''), 'color' => 'slate'])->id;

            $ids[] = $cache[$key];
        }

        return $ids;
    }
}

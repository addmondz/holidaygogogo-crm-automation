<?php

namespace App\Services\Inbox;

use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsappTemplate;

/**
 * Works out which variables an approved WhatsApp template needs, and turns
 * the values an agent typed into the Cloud API "components" payload.
 *
 * Variable keys: "header.1" / "header.media", "body.1" (or "body.first_name"
 * for templates with named parameters), "button.0.1", "button.0.coupon_code".
 */
class TemplateRenderer
{
    private const PLACEHOLDER = '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/';

    /**
     * @return list<array{key: string, label: string, kind: string, example: ?string}>
     */
    public function variables(WhatsappTemplate $template): array
    {
        $variables = [];

        foreach ($template->components ?? [] as $component) {
            $type = strtoupper($component['type'] ?? '');

            if ($type === 'HEADER') {
                $format = strtoupper($component['format'] ?? 'TEXT');

                if (in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
                    $variables[] = [
                        'key' => 'header.media',
                        'label' => 'Header '.strtolower($format).' link (public https URL)',
                        'kind' => 'media',
                        'example' => $component['example']['header_handle'][0] ?? null,
                    ];
                } elseif ($format === 'TEXT') {
                    foreach ($this->placeholders($component['text'] ?? '') as $i => $name) {
                        $variables[] = [
                            'key' => "header.{$name}",
                            'label' => "Header {{{$name}}}",
                            'kind' => 'text',
                            'example' => $component['example']['header_text'][$i] ?? null,
                        ];
                    }
                }
            }

            if ($type === 'BODY') {
                foreach ($this->placeholders($component['text'] ?? '') as $i => $name) {
                    $example = $component['example']['body_text'][0][$i]
                        ?? collect($component['example']['body_text_named_params'] ?? [])->firstWhere('param_name', $name)['example']
                        ?? null;

                    $variables[] = ['key' => "body.{$name}", 'label' => "Message {{{$name}}}", 'kind' => 'text', 'example' => $example];
                }
            }

            if ($type === 'BUTTONS') {
                foreach ($component['buttons'] ?? [] as $index => $button) {
                    $buttonType = strtoupper($button['type'] ?? '');

                    if ($buttonType === 'URL') {
                        foreach ($this->placeholders($button['url'] ?? '') as $name) {
                            $variables[] = [
                                'key' => "button.{$index}.{$name}",
                                'label' => "Button “{$button['text']}” link ending",
                                'kind' => 'text',
                                'example' => $button['example'][0] ?? null,
                            ];
                        }
                    }

                    if ($buttonType === 'COPY_CODE') {
                        $variables[] = [
                            'key' => "button.{$index}.coupon_code",
                            'label' => 'Coupon code',
                            'kind' => 'text',
                            'example' => $button['example'][0] ?? null,
                        ];
                    }
                }
            }
        }

        return $variables;
    }

    /**
     * @param  array<string, string|null>  $values
     * @return list<string> Keys of required variables that are empty.
     */
    public function missing(WhatsappTemplate $template, array $values): array
    {
        return collect($this->variables($template))
            ->pluck('key')
            ->filter(fn (string $key) => blank($values[$key] ?? null))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string|null>  $values
     * @return list<array<string, mixed>>
     */
    public function components(WhatsappTemplate $template, array $values, ?Contact $contact = null, ?User $agent = null): array
    {
        $named = strtoupper((string) $template->parameter_format) === 'NAMED';
        $fill = fn (string $key) => $this->value($values[$key] ?? '', $contact, $agent);
        $components = [];

        foreach ($template->components ?? [] as $component) {
            $type = strtoupper($component['type'] ?? '');

            if ($type === 'HEADER') {
                $format = strtoupper($component['format'] ?? 'TEXT');

                if (in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
                    $kind = strtolower($format);
                    $components[] = [
                        'type' => 'header',
                        'parameters' => [['type' => $kind, $kind => ['link' => $fill('header.media')]]],
                    ];
                } elseif ($format === 'TEXT' && $names = $this->placeholders($component['text'] ?? '')) {
                    $components[] = [
                        'type' => 'header',
                        'parameters' => array_map(fn ($name) => $this->textParameter($name, $fill("header.{$name}"), $named), $names),
                    ];
                }
            }

            if ($type === 'BODY' && $names = $this->placeholders($component['text'] ?? '')) {
                $components[] = [
                    'type' => 'body',
                    'parameters' => array_map(fn ($name) => $this->textParameter($name, $fill("body.{$name}"), $named), $names),
                ];
            }

            if ($type === 'BUTTONS') {
                foreach ($component['buttons'] ?? [] as $index => $button) {
                    $buttonType = strtoupper($button['type'] ?? '');

                    if ($buttonType === 'URL' && $names = $this->placeholders($button['url'] ?? '')) {
                        $components[] = [
                            'type' => 'button',
                            'sub_type' => 'url',
                            'index' => (string) $index,
                            'parameters' => [['type' => 'text', 'text' => $fill("button.{$index}.{$names[0]}")]],
                        ];
                    }

                    if ($buttonType === 'COPY_CODE') {
                        $components[] = [
                            'type' => 'button',
                            'sub_type' => 'copy_code',
                            'index' => (string) $index,
                            'parameters' => [['type' => 'coupon_code', 'coupon_code' => $fill("button.{$index}.coupon_code")]],
                        ];
                    }
                }
            }
        }

        return $components;
    }

    /**
     * The message as the customer will read it, for the chat history.
     *
     * @param  array<string, string|null>  $values
     */
    public function render(WhatsappTemplate $template, array $values, ?Contact $contact = null, ?User $agent = null): string
    {
        $parts = [];

        foreach ($template->components ?? [] as $component) {
            $type = strtoupper($component['type'] ?? '');
            $section = match ($type) {
                'HEADER' => 'header',
                'BODY' => 'body',
                'FOOTER' => 'footer',
                default => null,
            };

            if ($section && isset($component['text'])) {
                $text = preg_replace_callback(
                    self::PLACEHOLDER,
                    fn ($m) => $this->value($values["{$section}.{$m[1]}"] ?? '', $contact, $agent),
                    $component['text'],
                );

                $parts[] = $type === 'HEADER' ? "*{$text}*" : $text;
            }

            if ($type === 'BUTTONS') {
                $labels = array_map(fn ($button) => '['.($button['text'] ?? '').']', $component['buttons'] ?? []);
                $parts[] = implode(' ', $labels);
            }
        }

        return trim(implode("\n\n", array_filter($parts, fn ($part) => $part !== '')));
    }

    /**
     * @return list<string> Placeholder names in order of first appearance.
     */
    private function placeholders(string $text): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function value(?string $raw, ?Contact $contact, ?User $agent): string
    {
        $value = trim(Placeholders::fill((string) $raw, $contact, $agent));

        // WhatsApp rejects empty parameters.
        return $value === '' ? '-' : $value;
    }

    /**
     * @return array<string, string>
     */
    private function textParameter(string $name, string $value, bool $named): array
    {
        return $named
            ? ['type' => 'text', 'parameter_name' => $name, 'text' => $value]
            : ['type' => 'text', 'text' => $value];
    }
}

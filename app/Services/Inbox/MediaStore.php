<?php

namespace App\Services\Inbox;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Mime\MimeTypes;

/**
 * Stores customer photos/files and agents' attachments on the private media
 * disk. Files are only served to logged-in agents (see MediaController).
 */
class MediaStore
{
    public static function disk(): FilesystemAdapter
    {
        return Storage::disk(config('crm.media_disk'));
    }

    /**
     * @return array{path: string, mime: ?string, filename: ?string, size: int}
     */
    public static function put(string $contents, ?string $mime, ?string $filename = null): array
    {
        $mime = $mime ? trim(explode(';', $mime)[0]) : null;
        $extension = pathinfo((string) $filename, PATHINFO_EXTENSION)
            ?: (MimeTypes::getDefault()->getExtensions((string) $mime)[0] ?? 'bin');

        $path = 'crm-media/'.now()->format('Y/m').'/'.Str::uuid().'.'.strtolower($extension);

        static::disk()->put($path, $contents);

        return ['path' => $path, 'mime' => $mime, 'filename' => $filename, 'size' => strlen($contents)];
    }

    /**
     * @return array{path: string, mime: ?string, filename: ?string, size: int}
     */
    public static function putUpload(UploadedFile $file, string $folder = 'crm-media'): array
    {
        $path = static::disk()->putFileAs(
            $folder.'/'.now()->format('Y/m'),
            $file,
            Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?? 'bin')),
        );

        return [
            'path' => (string) $path,
            'mime' => $file->getMimeType(),
            'filename' => $file->getClientOriginalName(),
            'size' => (int) $file->getSize(),
        ];
    }

    public static function get(string $path): string
    {
        return (string) static::disk()->get($path);
    }

    /**
     * Serve a stored file to an agent. Only images, audio, video and PDFs are
     * shown in the browser; anything else (e.g. an HTML or SVG file a customer
     * sent) is downloaded, so it can never run scripts on the CRM's domain.
     */
    public static function respond(string $path, ?string $filename, ?string $mime, bool $download = false): StreamedResponse
    {
        $mime = $mime ?: 'application/octet-stream';
        $inline = ! $download
            && $mime !== 'image/svg+xml'
            && (preg_match('#^(image|audio|video)/#', $mime) || $mime === 'application/pdf');

        $headers = [
            'Content-Type' => $inline ? $mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, max-age=86400',
        ];

        $name = $filename ?: basename($path);

        return $inline
            ? static::disk()->response($path, $name, $headers)
            : static::disk()->download($path, $name, $headers);
    }
}

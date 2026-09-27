<?php

namespace App\Services\Meta;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around Meta's Graph API that turns API errors into
 * MetaApiException. Only network failures are retried.
 */
class GraphClient
{
    public function __construct(private readonly ?string $accessToken) {}

    public static function isFake(): bool
    {
        return (bool) config('services.meta.fake');
    }

    public static function baseUrl(): string
    {
        return rtrim((string) config('services.meta.graph_url'), '/').'/'.config('services.meta.graph_version');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->handle(fn () => $this->request()->get($this->url($path), $query));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function post(string $path, array $data = []): array
    {
        return $this->handle(fn () => $this->request()->asJson()->post($this->url($path), $data));
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     */
    public function postFile(string $path, array $fields, string $fileField, string $contents, string $filename, string $mime): array
    {
        return $this->handle(fn () => $this->request()
            ->timeout(120)
            ->attach($fileField, $contents, $filename, ['Content-Type' => $mime])
            ->post($this->url($path), $fields));
    }

    /**
     * Download a file (e.g. WhatsApp media) using the channel's token.
     */
    public function download(string $url): Response
    {
        $response = $this->request()->timeout(120)->get($url);

        if ($response->failed()) {
            throw new MetaApiException("Could not download media (HTTP {$response->status()}).", $response->status());
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        return Http::withToken((string) $this->accessToken)
            ->acceptJson()
            ->timeout(30)
            ->retry(3, 500, fn ($exception) => $exception instanceof ConnectionException, throw: false);
    }

    private function url(string $path): string
    {
        return str_starts_with($path, 'http') ? $path : self::baseUrl().'/'.ltrim($path, '/');
    }

    /**
     * @param  callable(): Response  $send
     * @return array<string, mixed>
     */
    private function handle(callable $send): array
    {
        $response = $send();

        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $error = $response->json('error') ?? [];

        throw new MetaApiException(
            $error['error_user_msg'] ?? $error['message'] ?? "Meta API request failed (HTTP {$response->status()}).",
            $response->status(),
            isset($error['code']) ? (int) $error['code'] : null,
            $error['error_data']['details'] ?? null,
        );
    }
}

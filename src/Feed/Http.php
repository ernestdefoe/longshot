<?php

namespace ErnestDefoe\Longshot\Feed;

use GuzzleHttp\Client;
use Throwable;

/**
 * The one way out to a data provider: budgeted, time-boxed, size-capped, no
 * redirects, and honest about why a call failed.
 *
 * 🚨 No custom User-Agent. ESPN answers Guzzle's own and refuses custom ones
 * with a 403 (Picks' EspnProvider); the other providers do not care.
 */
class Http
{
    public const MAX_BYTES = 8_000_000;

    public function __construct(private Budget $budget, private ?Client $client = null)
    {
    }

    /**
     * GET a JSON document, or null (budget spent, provider paused, or the call failed).
     *
     * @param array<string, scalar> $query
     * @param array<string, string> $headers
     * @return array<mixed>|null
     */
    public function json(string $provider, string $url, array $query = [], array $headers = [], int $cost = 1): ?array
    {
        if ($this->budget->paused($provider) || ! $this->budget->spend($provider, $cost)) {
            return null;
        }

        try {
            $response = ($this->client ?? new Client())->get($url, [
                'query' => $query,
                'headers' => ['Accept' => 'application/json'] + $headers,
                'timeout' => 12,
                'connect_timeout' => 5,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        } catch (Throwable $e) {
            $this->budget->failed($provider, $e->getMessage());

            return null;
        }

        $status = $response->getStatusCode();

        if ($status === 429) {
            // Told to slow down: stop asking for half an hour.
            $this->budget->failed($provider, 'HTTP 429: rate limited', true, 30);

            return null;
        }

        if ($status === 401 || $status === 403) {
            // A bad or expired key will not fix itself in a minute.
            $this->budget->failed($provider, 'HTTP '.$status.': the key was refused', true, 60);

            return null;
        }

        if ($status !== 200) {
            $this->budget->failed($provider, 'HTTP '.$status);

            return null;
        }

        $stream = $response->getBody();

        if (($stream->getSize() ?? 0) > self::MAX_BYTES) {
            $this->budget->failed($provider, 'response too large');

            return null;
        }

        $body = $stream->getContents();

        if (strlen($body) > self::MAX_BYTES) {
            $this->budget->failed($provider, 'response too large');

            return null;
        }

        $data = json_decode($body, true);

        if (! is_array($data)) {
            $this->budget->failed($provider, 'not JSON');

            return null;
        }

        $this->budget->succeeded($provider);

        return $data;
    }

    /** Raw bytes of an image, for the logo cache. Same caps, no budget (CDN files, not API calls). */
    public function image(string $url, int $maxBytes = 2_000_000): ?string
    {
        try {
            $response = ($this->client ?? new Client())->get($url, [
                'timeout' => 10,
                'connect_timeout' => 5,
                'http_errors' => false,
                'allow_redirects' => false,
                'headers' => ['Accept' => 'image/png,image/jpeg,image/webp,image/gif'],
            ]);
        } catch (Throwable) {
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $stream = $response->getBody();

        if (($stream->getSize() ?? 0) > $maxBytes) {
            return null;
        }

        $body = $stream->getContents();

        return $body === '' || strlen($body) > $maxBytes ? null : $body;
    }
}

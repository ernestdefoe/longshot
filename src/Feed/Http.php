<?php

namespace ErnestDefoe\Longshot\Feed;

use GuzzleHttp\Client;
use Psr\Http\Message\StreamInterface;
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

    /** A wall-clock deadline for this process (staff's "sync now" must answer). */
    public static ?float $deadline = null;

    public function __construct(private Budget $budget, private ?Client $client = null)
    {
    }

    /**
     * GET a JSON document, or null (budget spent, provider paused, past the
     * deadline, or the call failed).
     *
     * @param array<string, scalar> $query
     * @param array<string, string> $headers
     * @return array<mixed>|null
     */
    public function json(string $provider, string $url, array $query = [], array $headers = [], int $cost = 1): ?array
    {
        if ((self::$deadline !== null && microtime(true) > self::$deadline) || $this->budget->paused($provider) || ! $this->budget->spend($provider, $cost)) {
            return null;
        }

        try {
            $response = ($this->client ?? new Client())->get($url, $this->options($query, ['Accept' => 'application/json'] + $headers, 12));
            $status = $response->getStatusCode();
            $body = $status === 200 ? $this->read($response->getBody(), self::MAX_BYTES) : '';
        } catch (Throwable $e) {
            $this->budget->failed($provider, self::scrub($e->getMessage()));

            return null;
        }

        if ($status === 429) {
            // Told to slow down: stop asking for half an hour.
            $this->budget->failed($provider, 'HTTP 429: rate limited', true, 30);

            return null;
        }

        if ($status === 401 || $status === 403) {
            // A bad or expired key will not fix itself in a minute (saving a new key lifts it).
            $this->budget->failed($provider, 'HTTP '.$status.': the key was refused', true, 60);

            return null;
        }

        if ($status !== 200 || $body === null) {
            $this->budget->failed($provider, $body === null ? 'response too large' : 'HTTP '.$status);

            return null;
        }

        $data = json_decode($body, true);

        if (! is_array($data)) {
            $this->budget->failed($provider, 'not JSON');

            return null;
        }

        // API-Football answers 200 with an "errors" object for a bad key or a
        // spent plan; that is a failure, and a key problem pauses it.
        if (! empty($data['errors']) && ! array_is_list((array) $data) && is_array($data['errors'])) {
            $message = self::scrub(mb_substr(implode('; ', array_map('strval', array_values($data['errors']))), 0, 200));
            $this->budget->failed($provider, 'API error: '.$message, (bool) preg_match('/token|key|plan|limit|subscri/i', $message), 60);

            return null;
        }

        $this->budget->succeeded($provider);

        return $data;
    }

    /** Raw bytes of an image, for the logo cache. Same caps, no budget (CDN files, not API calls). */
    public function image(string $url, int $maxBytes = 2_000_000): ?string
    {
        if (self::$deadline !== null && microtime(true) > self::$deadline) {
            return null;
        }

        try {
            $response = ($this->client ?? new Client())->get($url, $this->options([], ['Accept' => 'image/png,image/jpeg,image/webp,image/gif'], 10, $maxBytes));

            return $response->getStatusCode() === 200 ? $this->read($response->getBody(), $maxBytes) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, scalar> $query
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function options(array $query, array $headers, int $timeout, int $max = self::MAX_BYTES): array
    {
        return [
            'query' => $query,
            'headers' => $headers,
            'timeout' => $timeout,
            'connect_timeout' => 5,
            'http_errors' => false,
            'allow_redirects' => false,
            // Cut off mid-download past the cap, rather than buffered and then refused.
            // (Not 'stream' => true: that swaps curl for PHP's stream wrapper.)
            'progress' => function ($total, $downloaded) use ($max): void {
                if ($downloaded > $max || $total > $max) {
                    throw new \RuntimeException('response too large');
                }
            },
        ];
    }

    /** The body up to $max bytes, or null if it is longer. */
    private function read(StreamInterface $stream, int $max): ?string
    {
        if (($stream->getSize() ?? 0) > $max) {
            return null;
        }

        $body = '';
        while (! $stream->eof()) {
            $body .= $stream->read(65536);
            if (strlen($body) > $max) {
                return null;
            }
        }

        return $body;
    }

    /** No keys in stored errors: query strings carry The Odds API's. */
    public static function scrub(string $message): string
    {
        return mb_substr(preg_replace('/\?[^\s"\']*/', '?…', $message) ?? $message, 0, 300);
    }
}

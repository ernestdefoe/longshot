<?php

namespace ErnestDefoe\Longshot\Feed;

use Carbon\Carbon;

/**
 * One game as any provider reports it, in Longshot's own shape.
 *
 * `prices` is the EspnOdds::parse() shape: moneyline {home, away, draw},
 * spread {line, home, away} (the home line), total {line, over, under}.
 */
final class Game
{
    public const SCHEDULED = 'scheduled';
    public const LIVE = 'live';
    public const FINAL = 'final';
    /** Postponed, cancelled, abandoned: no result is coming at this time. */
    public const OFF = 'off';

    /**
     * @param array<string, array<string, mixed>>|null $prices
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $id,
        public readonly string $league,
        public readonly Carbon $startsAt,
        public readonly Side $home,
        public readonly Side $away,
        public readonly string $status = self::SCHEDULED,
        public readonly ?int $homeScore = null,
        public readonly ?int $awayScore = null,
        public readonly ?array $prices = null,
        public readonly ?string $bookmaker = null,
    ) {
    }

    public function isFinal(): bool
    {
        return $this->status === self::FINAL && $this->homeScore !== null && $this->awayScore !== null;
    }
}

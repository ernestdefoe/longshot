<?php

namespace ErnestDefoe\Longshot\Feed;

/**
 * A data provider. Its capabilities are the interfaces it implements:
 * Schedules (games, results and often prices), Prices, Results, Branding.
 */
abstract class Provider
{
    abstract public function key(): string;

    abstract public function label(): string;

    /** Whether it needs an API key to be used at all. */
    public function needsKey(): bool
    {
        return true;
    }

    /** Where an admin makes a key. */
    public function keyUrl(): ?string
    {
        return null;
    }

    /** The provider's terms of use. */
    public function termsUrl(): ?string
    {
        return null;
    }

    /** The default budget, in calls (credits for The Odds API) per period. */
    abstract public function defaultBudget(): int;

    /** "day" or "month": what the free tier resets by. */
    public function budgetPeriod(): string
    {
        return 'day';
    }

    /** A per-minute ceiling, for providers that refuse bursts; 0 for none. */
    public function perMinute(): int
    {
        return 0;
    }

    /** Minutes between price checks per league, for providers that sell prices. */
    public function priceEvery(): int
    {
        return 30;
    }

    public function covers(League $league): bool
    {
        return $league->id($this->key()) !== null;
    }
}

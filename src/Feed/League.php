<?php

namespace ErnestDefoe\Longshot\Feed;

/**
 * A competition Longshot can follow, with each provider's own name for it.
 * A provider that has no id here does not cover the league.
 */
final class League
{
    /**
     * @param array<string, string|int> $ids provider key => that provider's id for the league
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $sport,
        public readonly array $ids,
        /** Three-way moneyline (soccer). */
        public readonly bool $draw = false,
        /** Games are in weeks with days off between (football): fewer days to look at. */
        public readonly int $horizonDays = 0,
    ) {
    }

    public function id(string $provider): string|int|null
    {
        return $this->ids[$provider] ?? null;
    }
}

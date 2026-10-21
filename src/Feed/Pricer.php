<?php

namespace ErnestDefoe\Longshot\Feed;

use ErnestDefoe\Longshot\Grader;
use ErnestDefoe\Longshot\Model\Market;
use Illuminate\Database\ConnectionInterface;

/**
 * Writes a provider's prices onto an event's feed-priced markets.
 *
 * A market only takes a price when every one of its selections has one (and
 * its line, where it has one): half a market is no market. Markets staff
 * priced by hand (`feed` off), and markets already settled or suspended, are
 * never touched.
 */
class Pricer
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    /**
     * @param array<string, array<string, mixed>> $prices EspnOdds::parse() shape
     * @return int how many prices or lines changed
     */
    public function apply(int $eventId, array $prices, ?string $bookmaker): int
    {
        $updated = 0;
        $markets = Market::query()->where('event_id', $eventId)->where('feed', true)->where('status', Market::OPEN)->with('selections')->get();

        foreach ($markets as $market) {
            $feed = $prices[$market->type] ?? null;

            if (! $feed) {
                continue;
            }

            $selections = $market->selections->keyBy('key');
            $complete = $selections->isNotEmpty()
                && $selections->every(fn ($s) => isset($feed[$s->key]))
                && (! $market->hasLine() || ($feed['line'] ?? null) !== null);

            if (! $complete) {
                continue;
            }

            $this->db->transaction(function () use ($market, $selections, $feed, $bookmaker, &$updated) {
                if ($market->hasLine() && Grader::normalise($market->line) !== $feed['line']) {
                    $market->line = $feed['line'];
                    $updated++;
                }

                if ($bookmaker !== null) {
                    $market->odds_provider = mb_substr($bookmaker, 0, 60);
                }

                $market->isDirty() && $market->save();

                foreach ($selections as $key => $selection) {
                    if ($selection->odds !== $feed[$key]) {
                        $selection->odds = $feed[$key];
                        $selection->save();
                        $updated++;
                    }
                }
            });
        }

        return $updated;
    }

    /** Whether an event's feed markets all have a price. */
    public function priced(int $eventId): bool
    {
        return ! $this->db->table('longshot_selections')
            ->join('longshot_markets', 'longshot_markets.id', '=', 'longshot_selections.market_id')
            ->where('longshot_markets.event_id', $eventId)
            ->where('longshot_markets.feed', true)
            ->where('longshot_markets.status', Market::OPEN)
            ->whereNull('longshot_selections.odds')
            ->exists();
    }
}

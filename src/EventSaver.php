<?php

namespace ErnestDefoe\Longshot;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Model\Event;
use ErnestDefoe\Longshot\Model\Leg;
use ErnestDefoe\Longshot\Model\Market;
use ErnestDefoe\Longshot\Model\Offer;
use ErnestDefoe\Longshot\Model\Selection;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Creates and edits events for staff.
 *
 * 🚨 Once a market has bets or offers, what they were placed on is fixed: its
 * type, its selections and their keys cannot change, and neither it nor its
 * selections can be removed — void it instead, which returns the stakes. Price
 * and line may still move; bets keep the ones they were placed at.
 */
class EventSaver
{
    public function __construct(private ConnectionInterface $db, private TranslatorInterface $translator)
    {
    }

    /** @param array<string, mixed> $data */
    public function save(User $actor, ?Event $event, array $data): Event
    {
        $actor->assertCan('longshot.manage');

        if ($event && $event->status !== Event::OPEN) {
            $this->fail('event', 'finished');
        }

        $title = $this->text($data['title'] ?? null, 200);
        $home = $this->text($data['home'] ?? null, 100);
        $away = $this->text($data['away'] ?? null, 100);
        $league = (string) $this->text($data['league'] ?? null, 60);

        if (($home === null) !== ($away === null) || ($home === null && $title === null)) {
            $this->fail('title', 'names');
        }

        try {
            $starts = Carbon::parse((string) ($data['startsAt'] ?? ''))->utc();
        } catch (\Throwable) {
            $this->fail('startsAt', 'starts');
        }

        $markets = array_values(array_filter((array) ($data['markets'] ?? []), 'is_array'));

        if (count($markets) > 30) {
            $this->fail('markets', 'too_many_markets');
        }

        return $this->db->transaction(function () use ($actor, $event, $title, $home, $away, $league, $starts, $markets, $data) {
            $event ??= new Event();

            if ($event->exists && $event->source !== 'manual' && ! ($data['locked'] ?? $event->locked)) {
                // An imported game keeps its feed's teams and kickoff (the sync
                // would put them back) unless staff lock it first. To close it
                // early without locking, suspend its markets.
                $home = $event->home_name;
                $away = $event->away_name;
                $starts = $event->starts_at;
            }

            // Staff's hands-off switch: no sync moves, renames or reschedules a locked event.
            if (array_key_exists('locked', $data)) {
                $event->locked = (bool) $data['locked'];
            }

            $event->title = $title;
            $event->league = $league;
            $event->home_name = $home;
            $event->away_name = $away;
            $event->starts_at = $starts;
            $event->status = Event::OPEN;
            $event->created_by ??= $actor->id;
            $event->save();

            $this->markets($event, $markets);

            return $event->load('markets.selections');
        });
    }

    /** @param list<array<string, mixed>> $input */
    private function markets(Event $event, array $input): void
    {
        $existing = Market::query()->where('event_id', $event->id)->with('selections')->get()->keyBy('id');
        $used = $this->usedMarkets($existing->keys()->all());
        $kept = [];

        foreach ($input as $sort => $data) {
            $id = is_int($data['id'] ?? null) ? $data['id'] : null;
            $market = $id ? $existing->get($id) : null;

            if ($id && ! $market) {
                $this->fail('markets', 'unknown_market');
            }

            $type = (string) ($data['type'] ?? '');

            if (! in_array($type, Market::TYPES, true) || ($type !== Market::CUSTOM && ! $event->isMatchup())) {
                $this->fail('markets', 'market_type');
            }

            if ($market && $market->isFinished()) {
                $kept[] = $market->id;
                continue;
            }

            if ($market && isset($used[$market->id]) && $market->type !== $type) {
                $this->fail('markets', 'market_used');
            }

            $market ??= new Market();
            $market->event_id = $event->id;
            $market->type = $type;
            $market->label = $this->text($data['label'] ?? null, 150);
            $market->status = ($data['status'] ?? null) === Market::SUSPENDED ? Market::SUSPENDED : Market::OPEN;
            $market->feed = $event->picks_event_id !== null && ! empty($data['feed']) && $type !== Market::CUSTOM;
            $market->sort = $sort;

            if ($market->hasLine()) {
                $line = Grader::normalise($data['line'] ?? null);

                if ($line === null) {
                    $this->fail('markets', 'line');
                }

                $market->line = $line;
            } else {
                $market->line = null;
            }

            if ($type === Market::CUSTOM && $market->label === null) {
                $this->fail('markets', 'custom_label');
            }

            $market->isDirty() && $market->save();
            $kept[] = $market->id;

            $this->selections($market, array_values(array_filter((array) ($data['selections'] ?? []), 'is_array')), isset($used[$market->id]));
        }

        foreach ($existing as $market) {
            if (! in_array($market->id, $kept, true)) {
                if (isset($used[$market->id]) || $market->isFinished()) {
                    $this->fail('markets', 'market_used');
                }

                Selection::query()->where('market_id', $market->id)->delete();
                $market->delete();
            }
        }

        // Every new option of every market, in one insert.
        if ($this->inserts) {
            Selection::query()->insert($this->inserts);
            $this->inserts = [];
        }
    }

    /** @var list<array<string, mixed>> new selections, written together at the end */
    private array $inserts = [];

    /** @param list<array<string, mixed>> $input */
    private function selections(Market $market, array $input, bool $used): void
    {
        $existing = Selection::query()->where('market_id', $market->id)->get()->keyBy('id');
        $keys = [];
        $kept = [];
        // New custom options are numbered after every key the market has had.
        $next = 1 + (int) $existing->map(fn ($s) => (int) substr($s->key, 3))->max();

        if ($market->type === Market::CUSTOM) {
            if (count($input) < 2 || count($input) > 40) {
                $this->fail('markets', 'custom_count');
            }
        }

        foreach ($input as $sort => $data) {
            $id = is_int($data['id'] ?? null) ? $data['id'] : null;
            $selection = $id ? $existing->get($id) : null;

            if ($id && ! $selection) {
                $this->fail('markets', 'unknown_market');
            }

            $key = $market->type !== Market::CUSTOM
                ? (string) ($data['key'] ?? '')
                : ($selection ? $selection->key : 'opt'.$next++);

            if (($market->type !== Market::CUSTOM && ! in_array($key, Market::KEYS[$market->type], true)) || isset($keys[$key])) {
                $this->fail('markets', 'selection_key');
            }

            if ($selection && $used && $selection->key !== $key) {
                $this->fail('markets', 'market_used');
            }

            $odds = $data['odds'] ?? null;
            if ($odds !== null && ! Odds::valid($odds)) {
                $this->fail('markets', 'odds');
            }

            $keys[$key] = true;
            $label = $market->type === Market::CUSTOM ? $this->text($data['label'] ?? null, 150) : null;

            if ($market->type === Market::CUSTOM && $label === null) {
                $this->fail('markets', 'custom_label');
            }

            if (! $selection) {
                // A new option (a draw added to a moneyline) would change how
                // the bets already on it are graded.
                if ($used) {
                    $this->fail('markets', 'market_used');
                }

                $this->inserts[] = ['market_id' => $market->id, 'key' => $key, 'label' => $label, 'odds' => $odds, 'sort' => $sort];
                continue;
            }

            $selection->label = $label;
            $selection->odds = $odds;
            $selection->sort = $sort;

            // Only what changed is written.
            $selection->isDirty() && $selection->save();
            $kept[] = $selection->id;
        }

        // A two-sided market needs both sides; a moneyline may add a draw.
        if ($market->type !== Market::CUSTOM) {
            $required = $market->type === Market::MONEYLINE ? ['away', 'home'] : Market::KEYS[$market->type];
            if (array_diff($required, array_keys($keys))) {
                $this->fail('markets', 'selection_key');
            }
        }

        foreach ($existing as $selection) {
            if (! in_array($selection->id, $kept, true)) {
                if ($used) {
                    $this->fail('markets', 'market_used');
                }

                $selection->delete();
            }
        }
    }

    /**
     * Markets something was placed on.
     *
     * @param list<int> $ids
     * @return array<int, true>
     */
    private function usedMarkets(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        return array_fill_keys(
            Leg::query()->whereIn('market_id', $ids)->distinct()->pluck('market_id')
                ->merge(Offer::query()->whereIn('market_id', $ids)->distinct()->pluck('market_id'))
                ->map(fn ($id) => (int) $id)->unique()->all(),
            true
        );
    }

    /** Whether anything was ever placed on this event: it cannot be deleted, only voided. */
    public function used(Event $event): bool
    {
        return Leg::query()->where('event_id', $event->id)->exists() || Offer::query()->where('event_id', $event->id)->exists();
    }

    private function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function fail(string $field, string $key): never
    {
        throw new ValidationException([$field => $this->translator->trans('ernestdefoe-longshot.api.admin.'.$key)]);
    }
}

<?php

namespace ErnestDefoe\Longshot;

use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Longshot's settings, typed and clamped in one place.
 */
class Config
{
    public const PREFIX = 'ernestdefoe-longshot.';

    public const OWN = 'own';
    public const POINTS = 'points';

    public function __construct(private SettingsRepositoryInterface $settings, private ExtensionManager $extensions)
    {
    }

    public function get(string $key): mixed
    {
        return $this->settings->get(self::PREFIX.$key);
    }

    private function int(string $key, int $default, int $min = 0, int $max = Odds::HARD_MAX_PAYOUT): int
    {
        $value = $this->get($key);

        return max($min, min($max, is_numeric($value) ? (int) $value : $default));
    }

    /**
     * Which balance is spent: Longshot's own, or FoF Gamification points.
     *
     * 🚨 Points are only possible where Gamification can GRANT them. It
     * recounts a member's points from post votes, so anything written straight
     * to `users.votes` is wiped by the next vote; its PointGrants service (from
     * the version that has it) is the only lasting way in. Without it, the
     * setting falls back to the own balance rather than spend points that
     * would come back.
     */
    public function currency(): string
    {
        return $this->get('currency') === self::POINTS && $this->pointsAvailable() ? self::POINTS : self::OWN;
    }

    public function pointsAvailable(): bool
    {
        return class_exists(\FoF\Gamification\PointGrants::class) && $this->extensions->isEnabled('fof-gamification');
    }

    public function picksAvailable(): bool
    {
        return class_exists(\Resofire\Picks\PickEvent::class) && $this->extensions->isEnabled('ernestdefoe-picks');
    }

    public function startingBalance(): int
    {
        return $this->int('starting_balance', 1000);
    }

    /** The forum-wide weekly allowance; groups may raise it. */
    public function allowance(): int
    {
        return $this->int('allowance', 250);
    }

    /** "add": the allowance is added each week. "topup": the balance is topped up to it. */
    public function allowanceMode(): string
    {
        return $this->get('allowance_mode') === 'topup' ? 'topup' : 'add';
    }

    public function minStake(): int
    {
        return $this->int('min_stake', 1, 1);
    }

    /** The forum-wide most a single bet may stake; groups may raise it. */
    public function maxStake(): int
    {
        return $this->int('max_stake', 1000, 1);
    }

    /** The most a member may stake in a day, 0 for no limit; groups may raise it. */
    public function dailyLimit(): int
    {
        return $this->int('daily_limit', 0);
    }

    public function maxPayout(): int
    {
        return $this->int('max_payout', 1_000_000, 1);
    }

    public function maxLegs(): int
    {
        return $this->int('max_legs', 8, 2, 12);
    }

    /** Whether a bet whose price got BETTER since the member saw it goes through at the new price. */
    public function acceptBetterOdds(): bool
    {
        return (bool) $this->get('accept_better_odds');
    }

    public function horizonDays(): int
    {
        return $this->int('horizon_days', 7, 1, 30);
    }

    /** Days after the start before an event Picks never finished is voided, 0 for never. */
    public function autoVoidDays(): int
    {
        return $this->int('auto_void_days', 3, 0, 60);
    }

    /** @return list<string> Picks league keys to import; empty for every league. */
    public function picksLeagues(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->get('picks_leagues')))));
    }

    /** @return list<string> Longshot league keys the forum follows through the data providers. */
    public function followedLeagues(): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $this->get('leagues'))))));
    }

    public function picksImport(): bool
    {
        return (bool) ($this->get('picks_import') ?? true);
    }

    public function feedOdds(): bool
    {
        return (bool) ($this->get('feed_odds') ?? true);
    }

    public function publicHistory(): bool
    {
        return (bool) ($this->get('public_history') ?? true);
    }

    public function currencyName(): string
    {
        $name = trim((string) $this->get('currency_name'));

        return $name === '' ? '' : mb_substr($name, 0, 30);
    }

    /**
     * For the settings page: which integrations can be switched on, and the
     * leagues Picks knows (key => name), for the import filter.
     *
     * @return array{points: bool, picks: bool, leagues: array<string, string>, feedLeagues: array<string, string>}
     */
    public function adminFacts(): array
    {
        $picks = $this->picksAvailable();

        return [
            'points' => $this->pointsAvailable(),
            'picks' => $picks,
            'leagues' => $picks ? resolve(\Resofire\Picks\Service\Leagues\Leagues::class)->choices() : [],
            'feedLeagues' => resolve(Feed\Leagues::class)->choices(),
        ];
    }
}

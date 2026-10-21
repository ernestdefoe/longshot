<?php

namespace ErnestDefoe\Longshot\Feed;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;

/**
 * Every provider, in the order they are tried, with the settings that turn
 * them on and budget them. Keyless providers work out of the box; the others
 * light up when an admin enters a key.
 */
class Providers
{
    /** Tried in this order for each job: the free, keyless ones first. */
    public const ORDER = [
        Providers\Espn::class,
        Providers\ApiFootball::class,
        Providers\OddsApi::class,
        Providers\Cfbd::class,
        Providers\SportsDb::class,
    ];

    /** @var array<string, Provider>|null */
    private ?array $providers = null;

    public function __construct(private Container $container, private SettingsRepositoryInterface $settings)
    {
    }

    /** @return array<string, Provider> */
    public function all(): array
    {
        if ($this->providers === null) {
            $this->providers = [];
            foreach (self::ORDER as $class) {
                $provider = $this->container->make($class);
                $this->providers[$provider->key()] = $provider;
            }
        }

        return $this->providers;
    }

    public function get(string $key): ?Provider
    {
        return $this->all()[$key] ?? null;
    }

    public function setting(string $key, string $name): mixed
    {
        return $this->settings->get('ernestdefoe-longshot.provider_'.$key.'_'.$name);
    }

    public function apiKey(string $key): string
    {
        $own = trim((string) $this->setting($key, 'key'));

        // CollegeFootballData: Picks' key when Longshot has none of its own.
        if ($own === '' && $key === 'cfbd') {
            return trim((string) $this->settings->get('ernestdefoe-picks.cfbd_api_key'));
        }

        return $own;
    }

    /** Whether the key in use is Picks' rather than Longshot's own. */
    public function keyFromPicks(string $key): bool
    {
        return $key === 'cfbd' && trim((string) $this->setting($key, 'key')) === '' && $this->apiKey($key) !== '';
    }

    public function budget(string $key): int
    {
        $value = $this->setting($key, 'budget');
        $provider = $this->get($key);

        return is_numeric($value) ? max(0, (int) $value) : ($provider ? $provider->defaultBudget() : 0);
    }

    /** On, and with a key where one is needed. */
    public function usable(string $key): bool
    {
        $provider = $this->get($key);

        return $provider !== null
            && ($this->setting($key, 'enabled') ?? '1') !== '0'
            && (! $provider->needsKey() || $this->apiKey($key) !== '');
    }

    /**
     * The usable providers with a capability that cover a league, in order.
     *
     * @template T
     * @param class-string<T> $capability
     * @return list<Provider&T>
     */
    public function for(string $capability, League $league): array
    {
        $out = [];
        foreach ($this->all() as $key => $provider) {
            if ($provider instanceof $capability && $provider->covers($league) && $this->usable($key)) {
                $out[] = $provider;
            }
        }

        return $out;
    }
}

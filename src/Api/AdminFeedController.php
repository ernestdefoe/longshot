<?php

namespace ErnestDefoe\Longshot\Api;

use ErnestDefoe\Longshot\Config;
use ErnestDefoe\Longshot\Feed\Budget;
use ErnestDefoe\Longshot\Feed\Http;
use ErnestDefoe\Longshot\Feed\Importer;
use ErnestDefoe\Longshot\Feed\Leagues;
use ErnestDefoe\Longshot\Feed\Logos;
use ErnestDefoe\Longshot\Feed\Providers;
use ErnestDefoe\Longshot\Feed\Providers\Cfbd;
use ErnestDefoe\Longshot\Model\Team;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The data providers and the teams they feed, for admins.
 *
 * GET   /api/longshot/admin/providers              each provider: what it gives, key set or not, budget, usage, health
 * POST  /api/longshot/admin/providers/{key}/test   one small call with the saved key
 * GET   /api/longshot/admin/teams?league=          the book's teams in a league
 * PATCH /api/longshot/admin/teams/{id}             { name, abbreviation, color, altColor, logo, locked }
 *
 * 🚨 Keys are never sent back: only whether one is set.
 */
class AdminFeedController implements RequestHandlerInterface
{
    use Concerns;

    /** One free (or near-free) call per provider, to prove a key works. */
    public const TESTS = [
        'espn' => ['https://site.api.espn.com/apis/site/v2/sports/football/nfl/scoreboard', 1],
        'oddsapi' => ['https://api.the-odds-api.com/v4/sports/', 0],
        'apifootball' => ['https://v3.football.api-sports.io/status', 0],
        'cfbd' => ['https://api.collegefootballdata.com/info', 0],
        'sportsdb' => ['https://www.thesportsdb.com/api/v1/json/{key}/lookupleague.php?id=4391', 1],
    ];

    public function __construct(
        private Providers $providers,
        private Budget $budget,
        private Http $http,
        private Leagues $leagues,
        private Config $config,
        private Logos $logos,
        private Importer $importer,
        private TranslatorInterface $translator,
        private Cache $cache,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $route = (string) $request->getAttribute('routeName');

        return match (true) {
            str_ends_with($route, 'providers.test') => $this->test((string) ($request->getAttribute('routeParameters')['key'] ?? '')),
            str_ends_with($route, 'teams.index') => $this->teams((string) ($request->getQueryParams()['league'] ?? '')),
            str_ends_with($route, 'teams.update') => $this->updateTeam($this->routeId($request), $this->body($request)),
            default => $this->providers(),
        };
    }

    private function providers(): ResponseInterface
    {
        $out = [];
        foreach ($this->providers->all() as $key => $provider) {
            $out[] = [
                'key' => $key,
                'label' => $provider->label(),
                'gives' => array_values(array_filter([
                    $provider instanceof \ErnestDefoe\Longshot\Feed\Schedules ? 'schedules' : null,
                    $provider instanceof \ErnestDefoe\Longshot\Feed\Prices || $key === 'espn' ? 'odds' : null,
                    $provider instanceof \ErnestDefoe\Longshot\Feed\Schedules || $provider instanceof \ErnestDefoe\Longshot\Feed\Results ? 'results' : null,
                    $provider instanceof \ErnestDefoe\Longshot\Feed\Branding || $key === 'apifootball' ? 'branding' : null,
                ])),
                'leagues' => array_values(array_map(fn ($l) => $l->name, array_filter($this->leagues->all(), fn ($l) => $provider->covers($l)))),
                'needsKey' => $provider->needsKey(),
                'hasKey' => $this->providers->apiKey($key) !== '',
                'keyFromPicks' => $this->providers->keyFromPicks($key),
                'keyUrl' => $provider->keyUrl(),
                'termsUrl' => $provider->termsUrl(),
                'enabled' => ($this->providers->setting($key, 'enabled') ?? '1') !== '0',
                'usable' => $this->providers->usable($key),
                'defaultBudget' => $provider->defaultBudget(),
                'perMinute' => $provider->perMinute(),
            ] + $this->budget->status($key);
        }

        return new JsonResponse(['data' => $out, 'meta' => ['leagues' => $this->leagues->choices(), 'followed' => $this->config->followedLeagues()]]);
    }

    private function test(string $key): ResponseInterface
    {
        $provider = $this->providers->get($key);
        if (! $provider || ! isset(self::TESTS[$key])) {
            throw new ValidationException(['provider' => $this->translator->trans('ernestdefoe-longshot.api.admin.no_provider')]);
        }

        // At most one test a minute per provider: a test is a real call.
        if (! $this->cache->add('longshot.test.'.$key, 1, 60)) {
            throw new ValidationException(['provider' => $this->translator->trans('ernestdefoe-longshot.api.admin.test_slow_down')]);
        }

        [$url, $cost] = self::TESTS[$key];
        $apiKey = $this->providers->apiKey($key);
        $sportsDbKey = preg_match('/^\d{1,20}$/', $apiKey) ? $apiKey : '123';
        $url = str_replace('{key}', $sportsDbKey, $url);

        $query = $key === 'oddsapi' ? ['apiKey' => $apiKey] : [];
        $headers = match ($key) {
            'apifootball' => ['x-apisports-key' => $apiKey],
            'cfbd' => ['Authorization' => 'Bearer '.$apiKey],
            default => [],
        };

        $data = $this->http->json($key, $url, $query, $headers, $cost);
        $ok = $data !== null && empty($data['errors']);

        return new JsonResponse(['data' => ['ok' => $ok] + $this->budget->status($key)]);
    }

    private function teams(string $league): ResponseInterface
    {
        $teams = Team::query()->when($league !== '', fn ($q) => $q->where('league_key', $league))->orderBy('league_key')->orderBy('name')->limit(500)->get();

        return new JsonResponse(['data' => $teams->map(fn (Team $t) => [
            'id' => $t->id,
            'league' => $t->league_key,
            'name' => $t->name,
            'abbreviation' => $t->abbreviation,
            'color' => $t->color,
            'altColor' => $t->alt_color,
            'logo' => $this->logos->url($t->logo_path),
            'logoSource' => $t->logo_source,
            'locked' => $t->locked,
        ])->values()->all()]);
    }

    /** @param array<string, mixed> $body */
    private function updateTeam(int $id, array $body): ResponseInterface
    {
        $team = Team::query()->findOrFail($id);
        $fail = fn (string $why) => throw new ValidationException(['team' => $this->translator->trans('ernestdefoe-longshot.api.admin.'.$why)]);

        $name = trim((string) ($body['name'] ?? $team->name));
        if ($name === '' || mb_strlen($name) > 100) {
            $fail('team_name');
        }

        // A new name is matched from now on, and the old one still is.
        $key = mb_substr(\ErnestDefoe\Longshot\Feed\Side::key($name), 0, 100);
        if ($key !== $team->name_key) {
            $taken = Team::query()->where('league_key', $team->league_key)->where('id', '!=', $team->id)->where('name_key', $key)->exists();
            if ($taken || $key === '') {
                $fail('team_name');
            }
            $team->aliases = array_values(array_unique(array_merge((array) $team->aliases, [$team->name_key])));
            $team->name_key = $key;
        }

        foreach (['color' => 'color', 'altColor' => 'alt_color'] as $in => $column) {
            if (array_key_exists($in, $body)) {
                $value = $body[$in] === null || $body[$in] === '' ? null : \ErnestDefoe\Longshot\Feed\Side::color($body[$in]);
                if ($body[$in] !== null && $body[$in] !== '' && $value === null) {
                    $fail('team_color');
                }
                $team->$column = $value;
            }
        }

        if (array_key_exists('logo', $body)) {
            $logo = trim((string) $body['logo']);
            if ($logo !== '' && (strlen($logo) > 255 || ! Logos::allowed($logo))) {
                $fail('team_logo');
            }
            // A new source is fetched by the next sync (or now, below).
            if ($logo !== (string) $team->logo_source) {
                // The old copy goes once it is no longer the team's.
                $this->logos->forget($team->logo_path);
                $team->logo_source = $logo === '' ? null : $logo;
                $team->logo_path = null;
                $team->logo_failed_at = null;
            }
        }

        $team->name = $name;
        $team->abbreviation = isset($body['abbreviation']) ? (mb_substr(trim((string) $body['abbreviation']), 0, 10) ?: null) : $team->abbreviation;
        $team->locked = (bool) ($body['locked'] ?? $team->locked);
        $team->save();

        if ($team->logo_source && ! $team->logo_path) {
            $this->logos->cacheTeam($team);
        }

        $this->importer->refreshNames($team);

        return $this->teams('');
    }
}

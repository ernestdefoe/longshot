<?php

namespace ErnestDefoe\Longshot\Feed\Providers;

use ErnestDefoe\Longshot\Feed\Branding;
use ErnestDefoe\Longshot\Feed\Http;
use ErnestDefoe\Longshot\Feed\League;
use ErnestDefoe\Longshot\Feed\Provider;
use ErnestDefoe\Longshot\Feed\Providers as Registry;
use ErnestDefoe\Longshot\Feed\Side;

/**
 * TheSportsDB (thesportsdb.com): team badges and colours, league badges.
 *
 * Works without a key on its public test key ("123"), which answers only
 * ten teams per league and refuses bursts (about 30 calls a minute); a
 * supporter key answers every team. Used after ESPN, to fill what ESPN lacks.
 */
class SportsDb extends Provider implements Branding
{
    public const BASE = 'https://www.thesportsdb.com/api/v1/json/';

    public function __construct(private Http $http, private Registry $providers)
    {
    }

    public function key(): string
    {
        return 'sportsdb';
    }

    public function label(): string
    {
        return 'TheSportsDB';
    }

    public function needsKey(): bool
    {
        return false;
    }

    public function keyUrl(): ?string
    {
        return 'https://www.thesportsdb.com/pricing';
    }

    public function termsUrl(): ?string
    {
        return 'https://www.thesportsdb.com/docs_api_guide';
    }

    public function defaultBudget(): int
    {
        return 300;
    }

    public function perMinute(): int
    {
        return 20;
    }

    private function base(): string
    {
        $key = $this->providers->apiKey('sportsdb');

        // Keys are digits on TheSportsDB; anything else is not sent in a URL path.
        return self::BASE.(preg_match('/^\d{1,20}$/', $key) ? $key : '123').'/';
    }

    public function teams(League $league): ?array
    {
        // By name: the public key ignores lookup_all_teams' league id.
        $data = $this->http->json('sportsdb', $this->base().'search_all_teams.php', ['l' => (string) $league->id('sportsdb_name')]);

        if ($data === null) {
            return null;
        }

        $out = [];
        foreach ((array) ($data['teams'] ?? []) as $t) {
            if (is_array($t) && ! empty($t['strTeam'])) {
                $out[] = new Side((string) $t['strTeam'], isset($t['strTeamShort']) ? mb_substr((string) $t['strTeamShort'], 0, 10) : null,
                    Side::color($t['strColour1'] ?? null), Side::color($t['strColour2'] ?? null),
                    is_string($t['strBadge'] ?? null) ? $t['strBadge'] : null, isset($t['idTeam']) ? (string) $t['idTeam'] : null);
            }
        }

        return $out;
    }

    public function badge(League $league): ?string
    {
        $data = $this->http->json('sportsdb', $this->base().'lookupleague.php', ['id' => (int) $league->id('sportsdb')]);
        $badge = $data['leagues'][0]['strBadge'] ?? null;

        return is_string($badge) ? $badge : null;
    }
}

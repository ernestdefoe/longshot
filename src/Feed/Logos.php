<?php

namespace ErnestDefoe\Longshot\Feed;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Model\Team;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Database\ConnectionInterface;
use Intervention\Image\ImageManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Team logos and league badges, cached on this forum.
 *
 * 🚨 Downloaded once, from known CDNs only (https, by host name, no
 * redirects, size-capped), decoded and re-encoded as a 96px WebP, and served
 * from the forum's own assets. A page never hotlinks a provider, and nothing
 * a provider sends reaches a browser as anything but a re-encoded image.
 */
class Logos
{
    /** Where providers keep their images. */
    public const HOSTS = ['a.espncdn.com', 'a1.espncdn.com', 'a2.espncdn.com', 'a3.espncdn.com', 'a4.espncdn.com', 'r2.thesportsdb.com', 'www.thesportsdb.com', 'media.api-sports.io', 'media-1.api-sports.io', 'media-2.api-sports.io', 'media-3.api-sports.io', 'media-4.api-sports.io'];

    public const SIZE = 96;

    public function __construct(
        private Http $http,
        private ImageManager $images,
        private Factory $disks,
        private ConnectionInterface $db,
        private LoggerInterface $log,
        private \Illuminate\Contracts\Cache\Repository $cache,
    ) {
    }

    public static function allowed(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && in_array(strtolower($parts['host'] ?? ''), self::HOSTS, true)
            && ! isset($parts['user']) && ! isset($parts['port']);
    }

    private function disk(): Cloud
    {
        /** @var Cloud $disk the assets disk serves public URLs */
        $disk = $this->disks->disk('flarum-assets');

        return $disk;
    }

    public function url(?string $path): ?string
    {
        return $path ? $this->disk()->url($path) : null;
    }

    /** Fetch, shrink and store; the path, or null. */
    public function store(string $source, string $name): ?string
    {
        if (! self::allowed($source)) {
            return null;
        }

        $bytes = $this->http->image($source);

        if ($bytes === null) {
            return null;
        }

        try {
            $image = $this->images->read($bytes)->scaleDown(self::SIZE, self::SIZE);
            $path = 'longshot/'.$name.'-'.substr(sha1($source), 0, 10).'.webp';
            $this->disk()->put($path, (string) $image->toWebp(85));

            return $path;
        } catch (Throwable $e) {
            $this->log->info('[longshot] logo '.$source.' could not be read: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Cache logos that are missing or whose source changed, a few per run.
     * Open events carrying the team are pointed at the cached copy.
     */
    public function cacheTeams(int $limit = 25): int
    {
        $done = 0;

        // A logo source set (by a provider or staff) and nothing cached for it yet.
        $teams = Team::query()->whereNotNull('logo_source')->whereNull('logo_path')
            ->orderBy('id')->limit($limit * 2)->get();

        foreach ($teams as $team) {
            if ($done >= $limit || $this->cache->has('longshot.logo-failed.'.$team->id)) {
                continue;
            }

            $path = $this->store((string) $team->logo_source, 'team-'.$team->id);

            if (! $path) {
                // Not again for a week: a dead link is not retried every run.
                $this->cache->put('longshot.logo-failed.'.$team->id, 1, 86400 * 7);
                continue;
            }

            $old = $team->logo_path;
            $team->logo_path = $path;
            $team->save();

            if ($old && $old !== $path) {
                $this->disk()->delete($old);
            }

            $this->point($team);
            $done++;
        }

        return $done;
    }

    /** Point open events at a team's cached logo (one update per side). */
    public function point(Team $team): void
    {
        $url = $this->url($team->logo_path);

        $this->db->table('longshot_events')->where('home_team_id', $team->id)->update(['home_logo' => $url]);
        $this->db->table('longshot_events')->where('away_team_id', $team->id)->update(['away_logo' => $url]);
    }

    /** A league's badge, cached once a month. */
    public function badge(string $league, ?string $source): ?string
    {
        $row = $this->db->table('longshot_leagues')->where('key', $league)->first();

        if ($row && $row->badge_path && $row->branded_at && Carbon::parse($row->branded_at)->gt(Carbon::now()->subMonth())) {
            return $this->url($row->badge_path);
        }

        $path = $source ? $this->store($source, 'league-'.$league) : null;

        $this->db->table('longshot_leagues')->updateOrInsert(['key' => $league], [
            'badge_path' => $path ?? ($row->badge_path ?? null),
            'badge_source' => $source,
            'branded_at' => Carbon::now(),
        ]);

        return $this->url($path ?? ($row->badge_path ?? null));
    }
}

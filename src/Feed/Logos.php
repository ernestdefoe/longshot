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

    /** Larger than any real logo; a "2 MB PNG" claiming 20000px is a decompression bomb. */
    public const MAX_PIXELS = 2048;

    public function __construct(
        private Http $http,
        private ImageManager $images,
        private Factory $disks,
        private ConnectionInterface $db,
        private LoggerInterface $log,
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

        // Dimensions from the header, before anything is decoded.
        $size = @getimagesizefromstring($bytes);
        if (! $size || $size[0] < 1 || $size[1] < 1 || $size[0] > self::MAX_PIXELS || $size[1] > self::MAX_PIXELS
            // No GIF: an animated one is decoded frame by frame.
            || ! in_array($size[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
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
     * Events carrying the team are pointed at the cached copy.
     */
    public function cacheTeams(int $limit = 25): int
    {
        $done = 0;

        // A logo source set (by a provider or staff), nothing cached for it
        // yet, and no failure this week: failures are recorded in the row,
        // so a dead or hostile image never holds up the ones behind it.
        $teams = Team::query()->whereNotNull('logo_source')->whereNull('logo_path')
            ->where(fn ($q) => $q->whereNull('logo_failed_at')->orWhere('logo_failed_at', '<', Carbon::now()->subWeek()))
            ->orderBy('id')->limit($limit)->get();

        foreach ($teams as $team) {
            $done += (int) $this->cacheTeam($team);
        }

        return $done;
    }

    public function cacheTeam(Team $team): bool
    {
        if (! $team->logo_source) {
            return false;
        }

        // Marked failed BEFORE the download: if decoding kills the process,
        // the next run skips this image instead of dying on it again.
        $team->logo_failed_at = Carbon::now();
        $team->save();

        $path = $this->store($team->logo_source, 'team-'.$team->id);

        if (! $path) {
            return false;
        }

        $old = $team->logo_path;
        $team->logo_path = $path;
        $team->logo_failed_at = null;
        $team->save();

        if ($old && $old !== $path) {
            $this->disk()->delete($old);
        }

        $this->point($team);

        return true;
    }

    /** Remove a cached file that is no longer used. */
    public function forget(?string $path): void
    {
        if ($path) {
            $this->disk()->delete($path);
        }
    }

    /** Point open events at a team's cached logo (one update per side). */
    public function point(Team $team): void
    {
        $url = $this->url($team->logo_path);

        $this->db->table('longshot_events')->where('home_team_id', $team->id)->update(['home_logo' => $url, 'home_color' => $team->color]);
        $this->db->table('longshot_events')->where('away_team_id', $team->id)->update(['away_logo' => $url, 'away_color' => $team->color]);
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

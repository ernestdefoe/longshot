<?php

namespace ErnestDefoe\Longshot\Api;

use ErnestDefoe\Longshot\Bank;
use ErnestDefoe\Longshot\Feed\EspnOdds;
use ErnestDefoe\Longshot\InsufficientBalance;
use ErnestDefoe\Longshot\Odds;
use ErnestDefoe\Longshot\PicksBridge;
use ErnestDefoe\Longshot\Settlement;
use Flarum\Foundation\ValidationException;
use Flarum\Group\Group;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET  /api/longshot/admin/limits          groups and their limits
 * POST /api/longshot/admin/limits          { limits: { groupId: { max_stake, daily_limit, allowance } } }
 * POST /api/longshot/admin/grants          { user, amount }: add or take points by hand
 * POST /api/longshot/admin/sync            import from Picks, fetch odds and settle, now
 */
class AdminToolsController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(
        private ConnectionInterface $db,
        private Bank $bank,
        private PicksBridge $picks,
        private EspnOdds $odds,
        private Settlement $settlement,
        private TranslatorInterface $translator,
        private Cache $cache,
        private \ErnestDefoe\Longshot\Feed\Sync $sync
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('longshot.manage');

        $route = (string) $request->getAttribute('routeName');
        $body = $this->body($request);

        return match (true) {
            str_ends_with($route, 'grants') => $this->grant($actor, $body),
            str_ends_with($route, 'sync') => $this->sync($actor),
            $request->getMethod() === 'POST' => $this->saveLimits($actor, $body),
            default => $this->limits(),
        };
    }

    private function limits(): ResponseInterface
    {
        $rows = $this->db->table('longshot_group_limits')->get()->keyBy('group_id');

        $groups = Group::query()->where('id', '!=', Group::GUEST_ID)->orderBy('id')->get()->map(fn (Group $g) => [
            'id' => $g->id,
            'name' => $g->name_plural,
            'color' => $g->color,
            'icon' => $g->icon,
            'maxStake' => isset($rows[$g->id]) ? $rows[$g->id]->max_stake : null,
            'dailyLimit' => isset($rows[$g->id]) ? $rows[$g->id]->daily_limit : null,
            'allowance' => isset($rows[$g->id]) ? $rows[$g->id]->allowance : null,
        ]);

        return new JsonResponse(['data' => $groups->values()->all()]);
    }

    /** @param array<string, mixed> $body */
    private function saveLimits(User $actor, array $body): ResponseInterface
    {
        $actor->assertAdmin();

        $groups = Group::query()->where('id', '!=', Group::GUEST_ID)->pluck('id')->all();
        $value = function (mixed $v): ?int {
            if ($v === null || $v === '') {
                return null;
            }
            $v = $this->int(is_int($v) ? $v : (string) $v);
            if ($v === null || $v < 0 || $v > Odds::HARD_MAX_PAYOUT) {
                throw new ValidationException(['limits' => $this->translator->trans('ernestdefoe-longshot.api.admin.limit')]);
            }

            return $v;
        };

        $this->db->transaction(function () use ($body, $groups, $value) {
            foreach ((array) ($body['limits'] ?? []) as $groupId => $limits) {
                if (! in_array((int) $groupId, $groups, true) || ! is_array($limits)) {
                    continue;
                }

                $row = [
                    'max_stake' => $value($limits['maxStake'] ?? null),
                    'daily_limit' => $value($limits['dailyLimit'] ?? null),
                    'allowance' => $value($limits['allowance'] ?? null),
                ];

                $this->db->table('longshot_group_limits')->where('group_id', (int) $groupId)->delete();

                if (array_filter($row, fn ($v) => $v !== null)) {
                    $this->db->table('longshot_group_limits')->insert($row + ['group_id' => (int) $groupId]);
                }
            }
        });

        return $this->limits();
    }

    /** @param array<string, mixed> $body */
    private function grant(User $actor, array $body): ResponseInterface
    {
        $actor->assertAdmin();

        $who = $body['user'] ?? null;
        $user = is_int($who) || (is_string($who) && ctype_digit($who))
            ? User::query()->find((int) $who)
            : (is_string($who) ? User::query()->where('username', $who)->first() : null);
        $amount = $this->int($body['amount'] ?? null);

        if (! $user) {
            throw new ValidationException(['user' => $this->translator->trans('ernestdefoe-longshot.api.admin.no_user')]);
        }

        if (! $amount || abs($amount) > Odds::HARD_MAX_PAYOUT) {
            throw new ValidationException(['amount' => $this->translator->trans('ernestdefoe-longshot.api.admin.amount')]);
        }

        $this->bank->open($user);

        try {
            $this->bank->move($user, $amount, 'grant', 'grant:'.$actor->id.':'.Str::random(24));
        } catch (InsufficientBalance) {
            throw new ValidationException(['amount' => $this->translator->trans('ernestdefoe-longshot.api.admin.amount')]);
        }

        return new JsonResponse(['data' => ['user' => $user->id, 'balance' => $this->bank->balance($user)]]);
    }

    private function sync(User $actor): ResponseInterface
    {
        // Admins only: it can spend providers' quotas.
        $actor->assertAdmin();

        // Every provider call in this request stops at 25 seconds.
        \ErnestDefoe\Longshot\Feed\Http::$deadline = microtime(true) + 25;

        // One at a time, and not more than once a minute: it calls ESPN.
        if (! $this->cache->add('longshot.admin-sync', 1, 60)) {
            throw new ValidationException(['sync' => $this->translator->trans('ernestdefoe-longshot.api.admin.slow_down')]);
        }

        $began = microtime(true);
        $imported = $this->picks->import();
        // At most ~20 seconds of ESPN calls for Picks' games here; the scheduled run does the rest.
        $odds = $this->odds->run(20);

        $leagues = [];
        foreach ($this->sync->leaguesToSync() as $league) {
            if (microtime(true) - $began > 22) {
                $leagues[] = ['league' => $league->key, 'skipped' => true];
                continue;
            }
            $leagues[] = $this->sync->league($league, true);
        }

        $settled = $this->settlement->sweep();

        return new JsonResponse(['data' => ['imported' => $imported, 'odds' => $odds, 'leagues' => $leagues, 'settled' => $settled]]);
    }
}

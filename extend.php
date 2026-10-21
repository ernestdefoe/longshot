<?php

/*
 * Longshot — a virtual-points sportsbook for Flarum.
 *
 * 🚨 Points only. There are no deposits, no withdrawals, no cash value and no
 * way to buy points through this extension. Members bet the points the forum
 * gives them (a starting balance, a weekly allowance, staff grants) or, if
 * the forum chooses, their FoF Gamification points.
 *
 * The shape of it: events carry markets, markets carry priced selections; a
 * bet takes the price and line of the moment, and settlement pays it from the
 * final score through a ledger that refuses to move the same points twice.
 * Games and scores come from Picks when it is installed; anything else is set
 * up by staff.
 */

use ErnestDefoe\Longshot\Api;
use ErnestDefoe\Longshot\Console;
use ErnestDefoe\Longshot\Notification;
use ErnestDefoe\Longshot\Settlement;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\User\User;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // The book's pages, and their styles, are one lazy chunk: nothing of
        // Longshot is in forum.css.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->route('/sportsbook', 'longshot.board')
        ->route('/sportsbook/results', 'longshot.results')
        ->route('/sportsbook/bets', 'longshot.bets')
        ->route('/sportsbook/exchange', 'longshot.exchange')
        ->route('/sportsbook/leaderboard', 'longshot.leaderboard')
        ->route('/sportsbook/wallet', 'longshot.wallet')
        ->route('/sportsbook/event/{id:\d+}', 'longshot.event'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('api'))
        ->get('/longshot', 'longshot.board', Api\BoardController::class)
        ->get('/longshot/events/{id:[0-9]+}', 'longshot.events.show', Api\EventController::class)
        ->get('/longshot/bets', 'longshot.bets.index', Api\ListBetsController::class)
        ->post('/longshot/bets', 'longshot.bets.create', Api\PlaceBetsController::class)
        ->get('/longshot/wallet', 'longshot.wallet.show', Api\WalletController::class)
        ->patch('/longshot/wallet', 'longshot.wallet.update', Api\WalletController::class)
        ->get('/longshot/leaderboard', 'longshot.leaderboard', Api\LeaderboardController::class)
        ->get('/longshot/offers', 'longshot.offers.index', Api\OffersController::class)
        ->post('/longshot/offers', 'longshot.offers.create', Api\OffersController::class)
        ->post('/longshot/offers/{id:[0-9]+}/matches', 'longshot.offers.match', Api\OffersController::class)
        ->delete('/longshot/offers/{id:[0-9]+}', 'longshot.offers.delete', Api\OffersController::class)
        ->get('/longshot/admin/events', 'longshot.admin.events.index', Api\AdminEventsController::class)
        ->post('/longshot/admin/events', 'longshot.admin.events.create', Api\AdminEventsController::class)
        ->patch('/longshot/admin/events/{id:[0-9]+}', 'longshot.admin.events.update', Api\AdminEventsController::class)
        ->delete('/longshot/admin/events/{id:[0-9]+}', 'longshot.admin.events.delete', Api\AdminEventsController::class)
        ->post('/longshot/admin/events/{id:[0-9]+}/score', 'longshot.admin.events.score', Api\AdminEventsController::class)
        ->post('/longshot/admin/events/{id:[0-9]+}/void', 'longshot.admin.events.void', Api\AdminEventsController::class)
        ->post('/longshot/admin/events/{id:[0-9]+}/hide', 'longshot.admin.events.hide', Api\AdminEventsController::class)
        ->get('/longshot/admin/providers', 'longshot.admin.providers.index', Api\AdminFeedController::class)
        ->post('/longshot/admin/providers/{key:[a-z]+}/test', 'longshot.admin.providers.test', Api\AdminFeedController::class)
        ->get('/longshot/admin/teams', 'longshot.admin.teams.index', Api\AdminFeedController::class)
        ->patch('/longshot/admin/teams/{id:[0-9]+}', 'longshot.admin.teams.update', Api\AdminFeedController::class)
        ->post('/longshot/admin/markets/{id:[0-9]+}/resolve', 'longshot.admin.markets.resolve', Api\AdminEventsController::class)
        ->post('/longshot/admin/markets/{id:[0-9]+}/void', 'longshot.admin.markets.void', Api\AdminEventsController::class)
        ->get('/longshot/admin/limits', 'longshot.admin.limits.index', Api\AdminToolsController::class)
        ->post('/longshot/admin/limits', 'longshot.admin.limits.save', Api\AdminToolsController::class)
        ->post('/longshot/admin/grants', 'longshot.admin.grants', Api\AdminToolsController::class)
        ->post('/longshot/admin/sync', 'longshot.admin.sync', Api\AdminToolsController::class),

    (new Extend\Console())
        ->command(Console\SettleCommand::class)
        ->command(Console\SyncCommand::class)
        ->schedule(Console\SettleCommand::class, function ($event) {
            $event->everyMinute()->withoutOverlapping();
        })
        ->schedule(Console\SyncCommand::class, function ($event) {
            $event->everyThirtyMinutes()->withoutOverlapping();
        }),

    (new Extend\Notification())
        ->type(Notification\BetSettledBlueprint::class, ['alert'])
        ->type(Notification\OfferMatchedBlueprint::class, ['alert'])
        ->type(Notification\ChallengeBlueprint::class, ['alert']),

    // A member's exchange business with others is unwound before the
    // database's cascade removes their rows and the other side's stake with
    // them. The model's own event, so every way of deleting a member counts
    // (Flarum's Deleting event fires for the API only).
    (new Extend\Event())
        ->listen('eloquent.deleting: '.User::class, fn (User $user) => resolve(Settlement::class)->releaseMember($user))
        ->listen(\Flarum\Settings\Event\Saved::class, \ErnestDefoe\Longshot\Listener\ProviderKeySaved::class),

    (new Extend\Settings())
        ->default('ernestdefoe-longshot.show_nav', true)
        ->default('ernestdefoe-longshot.currency', 'own')
        ->default('ernestdefoe-longshot.currency_name', '')
        ->default('ernestdefoe-longshot.starting_balance', 1000)
        ->default('ernestdefoe-longshot.allowance', 250)
        ->default('ernestdefoe-longshot.allowance_mode', 'add')
        ->default('ernestdefoe-longshot.min_stake', 1)
        ->default('ernestdefoe-longshot.max_stake', 1000)
        ->default('ernestdefoe-longshot.daily_limit', 0)
        ->default('ernestdefoe-longshot.max_payout', 1000000)
        ->default('ernestdefoe-longshot.max_legs', 8)
        ->default('ernestdefoe-longshot.accept_better_odds', false)
        ->default('ernestdefoe-longshot.picks_import', true)
        ->default('ernestdefoe-longshot.picks_leagues', '')
        ->default('ernestdefoe-longshot.feed_odds', true)
        ->default('ernestdefoe-longshot.horizon_days', 7)
        ->default('ernestdefoe-longshot.auto_void_days', 3)
        ->default('ernestdefoe-longshot.public_history', true)
        ->default('ernestdefoe-longshot.leagues', '')
        ->serializeToForum('longshotShowNav', 'ernestdefoe-longshot.show_nav', 'boolval'),

    // A permission the actor's groups were already loaded for: no query.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('canViewLongshot')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('longshot.view')),
            // What the settings page needs to know about the forum; admins only.
            Schema\Arr::make('longshotAdmin')
                ->visible(fn ($forum, Context $context) => $context->getActor()->isAdmin())
                ->get(fn () => resolve(\ErnestDefoe\Longshot\Config::class)->adminFacts()),
        ]),
];

<?php

namespace ErnestDefoe\Longshot\Tests\integration;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Model\Event;
use ErnestDefoe\Longshot\Model\Market;
use ErnestDefoe\Longshot\Model\Selection;
use ErnestDefoe\Longshot\Settlement;
use Flarum\User\User;
use Psr\Http\Message\ResponseInterface;

/** Fixtures and calls the Longshot tests share. */
trait Book
{
    /** @return list<array<string, mixed>> */
    protected function members(): array
    {
        return [
            ['id' => 3, 'username' => 'carol', 'email' => 'carol@machine.local', 'is_email_confirmed' => 1],
            ['id' => 4, 'username' => 'dave', 'email' => 'dave@machine.local', 'is_email_confirmed' => 1],
        ];
    }

    /**
     * A game with a moneyline, a spread and a total, starting in a day.
     *
     * @param array<string, mixed> $over
     * @return array{event: int, ml: int, spread: int, total: int, home: int, away: int, home_spread: int, away_spread: int, over: int, under: int}
     */
    protected function game(array $over = [], string $spread = '-3.5', string $total = '44.5'): array
    {
        $this->app();

        $event = new Event();
        $event->league = $over['league'] ?? 'NFL';
        $event->home_name = $over['home'] ?? 'Home FC';
        $event->away_name = $over['away'] ?? 'Away United';
        $event->starts_at = $over['starts_at'] ?? Carbon::now()->addDay();
        $event->status = Event::OPEN;
        $event->picks_event_id = $over['picks_event_id'] ?? null;
        $event->save();

        $ids = ['event' => $event->id];
        $sels = [
            Market::MONEYLINE => [null, ['away' => 150, 'home' => -170]],
            Market::SPREAD => [$spread, ['away' => -110, 'home' => -110]],
            Market::TOTAL => [$total, ['over' => -110, 'under' => -110]],
        ];

        foreach ($sels as $type => [$line, $prices]) {
            $market = new Market();
            $market->event_id = $event->id;
            $market->type = $type;
            $market->line = $line;
            $market->status = Market::OPEN;
            $market->feed = $event->picks_event_id !== null;
            $market->save();
            $ids[match ($type) { Market::MONEYLINE => 'ml', Market::SPREAD => 'spread', default => 'total' }] = $market->id;

            foreach ($prices as $key => $odds) {
                $s = new Selection();
                $s->market_id = $market->id;
                $s->key = $key;
                $s->odds = $odds;
                $s->save();
                $name = $type === Market::SPREAD ? $key.'_spread' : $key;
                $ids[$name] = $s->id;
            }
        }

        return $ids;
    }

    /** @param list<array<string, mixed>> $legs */
    protected function bet(int $user, array $legs, string $kind = 'single', int $stake = 0, array $extra = []): ResponseInterface
    {
        return $this->send($this->request('POST', '/api/longshot/bets', [
            'authenticatedAs' => $user,
            'json' => ['kind' => $kind, 'legs' => $legs, 'stake' => $stake] + $extra,
        ]));
    }

    protected function leg(int $selection, int $stake = 0): array
    {
        $s = Selection::query()->with('market')->findOrFail($selection);

        return ['selection' => $selection, 'odds' => $s->odds, 'line' => $s->market->hasLine() ? $s->market->line : null, 'stake' => $stake];
    }

    /** The wallet, opened (and the starting balance paid) by reading it. */
    protected function balance(int $user): int
    {
        $response = $this->send($this->request('GET', '/api/longshot/wallet', ['authenticatedAs' => $user]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true)['data']['balance'];
    }

    protected function stored(int $user): int
    {
        return (int) $this->database()->table('longshot_wallets')->where('user_id', $user)->value('balance');
    }

    protected function settlement(): Settlement
    {
        return $this->app()->getContainer()->make(Settlement::class);
    }

    protected function score(int $event, int $home, int $away): void
    {
        $this->settlement()->score(Event::query()->findOrFail($event), $home, $away);
    }

    /** @return array<string, mixed> */
    protected function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    protected function user(int $id): User
    {
        return User::query()->findOrFail($id);
    }
}

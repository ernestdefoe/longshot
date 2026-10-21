# Longshot

A sportsbook for Flarum 2 where members bet **virtual points** on real games, on anything you set up, and on each other.

> **Points only. No real money, ever.** There are no deposits, no withdrawals, no cash-outs and no way to buy points through Longshot. Points come from a starting balance, a weekly allowance and staff grants (or, if you choose, from FoF Gamification). They have no cash value and exist only on your forum.

## What members get

- **A board of games** with the three markets every book shows: moneyline (with a draw for soccer), spread and total, laid out in the familiar two-row grid.
- **Props and futures**: custom markets with any number of options ("Who wins the Heisman?", "Super Bowl MVP").
- **A bet slip** for singles (a stake each) or a parlay (one stake, up to 12 legs). It shows exactly what a bet returns, worked out the same way the server pays it.
- **The exchange**: back a pick at a price you name, and let other members lay it. Lay any part of an offer, or challenge one member directly.
- **My bets**: open and settled, with every leg's result.
- **Leaderboards** by profit (this week, this month, all time, counting bets and the exchange) or by balance.
- **A wallet** with the full history of every point in and out, the weekly allowance, and the member's limits.
- **Alerts** when a bet is settled, an offer is taken or someone challenges you.
- American or decimal odds, the viewer's choice.
- A profile tab with a member's settled bets, unless they keep them private.

## What staff get

- **Games from [Picks](https://github.com/ernestdefoe/picks)**, when it is installed: every league Picks follows (college football, NFL, NBA, college basketball, MLB, NHL, MLS, Premier League, Champions League) is offered automatically a few days ahead, priced from ESPN's odds and **settled from Picks' own final scores**. Longshot never fetches a score itself, so it and your pick'em can never disagree about who won.
- **Events by hand** for any sport, or anything else: a game between two sides, or a titled event with custom markets.
- **Results**: enter a final score and every moneyline, spread and total is graded; name the winner of a custom market; void anything to return every stake.
- **Limits**: smallest and largest stake, a daily staking limit, the largest return on one bet, and per-group overrides (the most generous group wins).
- **Weekly allowance**, added each week or topping the balance up to a set amount, with per-group amounts.
- **Points by hand**, for prizes and corrections, recorded in the member's history.
- Permissions to view, bet, use the exchange and manage the book.

## How settlement stays exact

- Every point that moves writes a **ledger row with a unique reference** (`bet:12:payout`, `allowance:7:2026-W43`). A retried job, two settlement runs at once or a double-clicked button finds the reference already written and moves nothing. The database enforces it, not just the code.
- A bet's stake is taken by **one conditional update** (`balance >= stake`), under a lock on the member's wallet, so simultaneous bets cannot spend the same points twice.
- Prices and lines are **read from the database** when a bet is placed. A price that moved since the member saw it refuses the slip (and shows the new one) rather than placing it at a price nobody agreed to.
- A bet is **graded at the line it was placed at**, not today's line. Lines are compared as whole tenths, never as floats, so a push on a whole number is a push.
- Pushes and voids return the stake; a parlay drops pushed and voided legs and pays on the rest.
- All arithmetic is in **whole points, rounded down**, the same on every server.
- Every step (scoring an event, grading a market, paying a bet or a match) is its own transaction that locks its row and checks it is still undecided. A run that dies halfway is finished by the next minute's sweep.

## Install

```sh
composer require ernestdefoe/longshot
```

Then enable it in the admin panel.

Longshot settles games and closes exchange offers from Flarum's scheduler, so make sure your forum's scheduler runs (`php flarum schedule:run` every minute). It runs:

- `longshot:settle` every minute: results from Picks, offers closed at kickoff, stalled games voided after a wait you choose, and anything an interrupted run left half done;
- `longshot:sync` every 30 minutes: upcoming Picks games offered, and their prices refreshed from ESPN (at most one request per league per game day, and only while a game is open for bets).

### Optional

- **Picks** (`ernestdefoe/picks`) for real games and automatic settlement.
- **FoF Gamification** to bet members' forum points instead of Longshot's own balance. This needs a Gamification version with point grants (FriendsOfFlarum/gamification#180); until it is installed, the option stays off and Longshot keeps its own balance.

### Update

```sh
composer update ernestdefoe/longshot
php flarum migrate
php flarum cache:clear
```

## Privacy

Open bets are never shown to anyone but the member and staff. Settled bets are shown on profiles when the forum allows it, and every member can keep theirs private and stay off the leaderboards.

## Support

Bug reports and ideas: [GitHub issues](https://github.com/ernestdefoe/longshot/issues).

☕ If Longshot is useful to you, you can [buy me a coffee](https://buymeacoffee.com/ernestdefoe).

## License

MIT

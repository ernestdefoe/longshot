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

- **A book that runs itself.** Pick the leagues to follow (NFL, college football, NBA, WNBA, college basketball, MLB, NHL, Premier League, LaLiga, Bundesliga, Serie A, Ligue 1, MLS, Champions League) and Longshot:
  - imports their games a few days ahead, with a moneyline (and a draw for soccer), a spread and a total;
  - prices them from the data providers and refreshes the prices until kickoff;
  - closes each game when it starts, even if it starts early;
  - settles it from the final score (soccer on 90 minutes, as books do);
  - voids a game that never finishes (postponed, cancelled) after a wait you choose, returning every stake;
  - brands teams with their logos and colours, and leagues with their badges. Logos are downloaded once, resized and served from your forum, never hotlinked.
- **Games from [Picks](https://github.com/ernestdefoe/picks)**, when it is installed: the leagues it plays are taken from Picks (its own fixtures and final scores), so Longshot and your pick'em never disagree about a result.
- **Your word is final.** Lock an event and no sync touches its sides, kickoff or status, nor settles it (you enter its score). Hide one and it stays gone. Take a market off the feed and price it yourself. Edit a team's name, colours or logo and it stays that way.
- **Events by hand** for any sport, or anything else: a game between two sides, or a titled event with custom markets.
- **Results**: enter a final score and every moneyline, spread and total is graded; name the winner of a custom market; void anything to return every stake.
- **Limits**: smallest and largest stake, a daily staking limit, the largest return on one bet, and per-group overrides (the most generous group wins).
- **Weekly allowance**, added each week or topping the balance up to a set amount, with per-group amounts.
- **Points by hand**, for prizes and corrections, recorded in the member's history.
- Permissions to view, bet, use the exchange and manage the book.

## Data providers

Teams are matched across providers exactly (by each provider's own id, then by name or a name the same provider gave), never by a part of a name, so "Texas" is never "Texas Tech"; a game that can't be matched is left for you. Providers are tried in order for each job. One that fails three times in a row, is rate-limited, or has its key refused is paused for a while and the next is used. Every call is counted against that provider's budget in the database before it is made, so no number of workers or retries can spend past it. Keys are entered in the admin panel, next to a link to the page where each is made, and are never sent to the forum.

| Provider | Gives | Key | Free tier | Default budget |
|---|---|---|---|---|
| **ESPN** public scoreboards | Schedules, live status, results, one sportsbook's prices, team logos and colours, league badges | None | Unofficial: no published limits or terms for this use | 2,000 calls a day |
| **[API-Football](https://www.api-football.com/)** | Soccer: fixtures, 90-minute results, Bet365 prices, team crests | [Free key](https://dashboard.api-football.com/register) | 100 requests a day | 90 a day |
| **[The Odds API](https://the-odds-api.com/)** | Prices from many bookmakers for every league; final scores as a fallback | [Free key](https://the-odds-api.com/#get-access) | 500 credits a month (3 per price check, 2 per scores check) | 450 credits a month |
| **[CollegeFootballData](https://collegefootballdata.com/)** | College football lines for games ESPN doesn't price | [Free key](https://collegefootballdata.com/key), or Picks' key if set | 1,000 calls a month (shared with Picks' key) | 60 a month |
| **[TheSportsDB](https://www.thesportsdb.com/)** | Team badges and colours, league badges | Optional ([supporter key](https://www.thesportsdb.com/pricing)) | Public key: 10 teams per league, about 30 calls a minute | 300 a day, 20 a minute |

Check each provider's terms before you rely on it: they decide what you may display and how. SportsDataIO is not supported: its free trial serves scrambled data, and every real plan is paid.

How often each job runs (each league, each job throttled on its own, so running the scheduler more often costs nothing): the full schedule every 6 hours, the next two days' games and ESPN's prices every 30 minutes, API-Football's prices every 3 hours, The Odds API's every 6 hours and CollegeFootballData's daily (each only for games still unpriced), results every 5 minutes while a game is due one (only for the days that have one; a postponed game is left alone for 6 hours), and branding weekly. A sync run downloads at most 25 logos, each checked to be a real image under 2048 pixels before it is decoded.

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

- `longshot:settle` every minute: results from Picks and the providers, offers closed at kickoff, stalled games voided after a wait you choose, and anything an interrupted run left half done;
- `longshot:sync` every 30 minutes: upcoming Picks games offered and priced, a queued sync for each league you follow (most runs ask no provider anything; see the timings above), and up to 25 waiting logos cached.

Run a queue worker (`php flarum queue:work`) if you can: the league syncs are queued jobs. Without one they run inside the scheduler, which is fine too.

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

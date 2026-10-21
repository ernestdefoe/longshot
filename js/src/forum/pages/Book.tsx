import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import UserPage from 'flarum/forum/components/UserPage';
import LinkButton from 'flarum/common/components/LinkButton';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Placeholder from 'flarum/common/components/Placeholder';
import Switch from 'flarum/common/components/Switch';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import type { Bet, BookEvent, Offer, Person, Wallet } from '../../common/types';
import { t } from '../../common/t';
import { get, send, wallet } from '../api';
import { day, marketLabel, points, when } from '../labels';
import { prefs } from '../prefs';
import BetSlip from '../components/BetSlip';
import BetCard from '../components/BetCard';
import EventCard from '../components/EventCard';
import OddsButton from '../components/OddsButton';
import OfferCard from '../components/OfferCard';
import OfferForm from '../components/OfferForm';
import person from '../components/Avatar';
import useStyles from '../styles';

useStyles();

/*
 * Every page of the book, in one chunk: none of it loads anywhere else.
 */

const TABS = ['board', 'results', 'bets', 'exchange', 'leaderboard', 'wallet'] as const;

/** The frame every book page shares: title, balance, tabs, content and the slip. */
abstract class BookPage extends Page {
  abstract tab: string;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    app.setTitle(extractText(t('forum.title')));
    app.setTitleCount(0);

    // Pages whose own request carries no wallet still show the balance.
    if (app.session.user && !wallet.current && this.tab !== 'wallet') get('/wallet').then(() => m.redraw());
  }

  view(): Mithril.Children {
    const w = wallet.current;

    return (
      <PageStructure className="LongshotPage" sidebar={() => <IndexSidebar />}>
        <header className="Longshot-hero">
          <div>
            <h2 className="Longshot-hero-title">{t('forum.title')}</h2>
            <p className="Longshot-note">{t('forum.points_only')}</p>
          </div>
          {w ? (
            <LinkButton className="Button Longshot-balance" href={app.route('longshot.wallet')} icon="fas fa-coins">
              {points(w.balance)}
            </LinkButton>
          ) : null}
        </header>

        {w && w.collected > 0 ? <p className="Longshot-collected">{t('forum.wallet.collected', { value: points(w.collected) })}</p> : null}

        <nav className="Longshot-tabs" aria-label={extractText(t('forum.title'))}>
          {TABS.filter((tab) => app.session.user || !['bets', 'wallet'].includes(tab)).map((tab) => (
            <LinkButton className={classList('Longshot-tab', { active: this.tab === tab })} href={app.route('longshot.' + tab)}>
              {t('forum.tabs.' + tab)}
            </LinkButton>
          ))}
          <button
            type="button"
            className="Longshot-oddsformat Button Button--link"
            onclick={() => {
              prefs.odds = prefs.odds === 'american' ? 'decimal' : 'american';
            }}
          >
            {t('forum.odds_format.' + prefs.odds)}
          </button>
        </nav>

        <div className="Longshot-layout">
          <div className="Longshot-main">{this.content()}</div>
          <BetSlip />
        </div>
      </PageStructure>
    );
  }

  abstract content(): Mithril.Children;
}

/** /sportsbook and /sportsbook/results — games taking bets, or recent results. */
export class BoardPage extends BookPage {
  tab = 'board';
  events: BookEvent[] | null = null;
  leagues: string[] = [];
  badges: Record<string, string> = {};
  league = '';
  more = false;
  loading = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.tab = m.route.get().includes('/results') ? 'results' : 'board';
    this.league = String(m.route.param('league') || '');
    this.load(true);
  }

  load(reset = false) {
    this.loading = true;
    get('', {
      results: this.tab === 'results' ? 1 : undefined,
      league: this.league || undefined,
      offset: reset ? 0 : (this.events ?? []).length,
    }).then((r) => {
      this.events = reset ? r.data : [...(this.events ?? []), ...r.data];
      this.leagues = r.meta.leagues;
      this.badges = { ...this.badges, ...(r.meta.badges ?? {}) };
      this.more = r.meta.more;
      this.loading = false;
      m.redraw();
    });
  }

  content(): Mithril.Children {
    if (this.events === null) return <LoadingIndicator />;

    let lastDay = '';

    return (
      <div>
        {this.leagues.length > 1 ? (
          <div className="Longshot-chips" role="group" aria-label={extractText(t('forum.board.leagues'))}>
            {['', ...this.leagues].map((league) => (
              <button
                type="button"
                className={classList('Longshot-chip', { active: this.league === league })}
                aria-pressed={this.league === league ? 'true' : 'false'}
                onclick={() => {
                  this.league = league;
                  this.load(true);
                }}
              >
                {league || t('forum.board.all')}
              </button>
            ))}
          </div>
        ) : null}

        {!this.events.length ? <Placeholder text={t(this.tab === 'results' ? 'forum.board.no_results' : 'forum.board.empty')} /> : null}

        {this.events.map((event) => {
          const d = day(event.startsAt);
          const heading = d !== lastDay ? <h3 className="Longshot-day">{d}</h3> : null;
          lastDay = d;

          return (
            <div key={event.id}>
              {heading}
              <EventCard event={event} badge={event.leagueKey ? this.badges[event.leagueKey] : null} />
            </div>
          );
        })}

        {this.more ? (
          <Button className="Button Button--block" loading={this.loading} onclick={() => this.load()}>
            {t('forum.load_more')}
          </Button>
        ) : null}
      </div>
    );
  }
}

/** /sportsbook/event/:id — every market, and the event's exchange. */
export class EventPage extends BookPage {
  tab = 'board';
  event: BookEvent | null = null;
  offers: Offer[] = [];

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.load();
  }

  load() {
    get(`/events/${m.route.param('id')}`).then((r) => {
      this.event = r.data;
      this.offers = r.meta.offers;
      app.setTitle(r.data.name);
      m.redraw();
    });
  }

  content(): Mithril.Children {
    const event = this.event;
    if (!event) return <LoadingIndicator />;

    const w = wallet.current;

    return (
      <div className="Longshot-eventpage">
        <LinkButton className="Button Button--link" icon="fas fa-arrow-left" href={app.route('longshot.board')}>
          {t('forum.event.back')}
        </LinkButton>
        <EventCard event={event} standalone />

        {(event.markets ?? []).map((market) => (
          <section className="Longshot-market">
            <h3>
              {marketLabel(market.type, market.label)}
              {market.status === 'suspended' ? <span className="Longshot-pill">{t('forum.market.suspended')}</span> : null}
              {market.status === 'void' ? <span className="Longshot-pill is-void">{t('forum.event.void')}</span> : null}
            </h3>
            <div className="Longshot-options">
              {market.selections.map((s) => (
                <OddsButton event={event} market={market} selection={s} named />
              ))}
            </div>
            {market.oddsProvider ? <p className="Longshot-muted">{t('forum.market.priced_by', { provider: market.oddsProvider })}</p> : null}
          </section>
        ))}

        <section className="Longshot-exchange">
          <h3>{t('forum.exchange.title')}</h3>
          <p className="Longshot-intro">{t('forum.exchange.explain')}</p>
          {this.offers.length ? (
            this.offers.map((offer) => <OfferCard key={offer.id} offer={offer} onchange={() => this.load()} />)
          ) : (
            <Placeholder text={t('forum.exchange.none_here')} />
          )}
          {event.bettable && w?.canExchange ? <OfferForm event={event} onchange={() => this.load()} /> : null}
        </section>
      </div>
    );
  }
}

/** /sportsbook/bets — the member's own bets, open and settled. */
export class BetsPage extends BookPage {
  tab = 'bets';
  status: 'open' | 'settled' = 'open';
  bets: Bet[] | null = null;
  more = false;
  loading = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.load(true);
  }

  load(reset = false) {
    this.loading = true;
    get('/bets', { status: this.status, offset: reset ? 0 : (this.bets ?? []).length }).then((r) => {
      this.bets = reset ? r.data : [...(this.bets ?? []), ...r.data];
      this.more = r.meta.more;
      this.loading = false;
      m.redraw();
    });
  }

  content(): Mithril.Children {
    return (
      <div>
        <div className="Longshot-segmented" role="tablist">
          {(['open', 'settled'] as const).map((s) => (
            <button
              type="button"
              role="tab"
              aria-selected={this.status === s ? 'true' : 'false'}
              className={classList({ active: this.status === s })}
              onclick={() => {
                this.status = s;
                this.bets = null;
                this.load(true);
              }}
            >
              {t('forum.bets.' + s)}
            </button>
          ))}
        </div>
        {betList(this.bets, this.more, this.loading, () => this.load())}
      </div>
    );
  }
}

function betList(bets: Bet[] | null, more: boolean, loading: boolean, next: () => void): Mithril.Children {
  if (bets === null) return <LoadingIndicator />;
  if (!bets.length) return <Placeholder text={t('forum.bets.none')} />;

  return [
    bets.map((bet) => <BetCard key={bet.id} bet={bet} />),
    more ? (
      <Button className="Button Button--block" loading={loading} onclick={next}>
        {t('forum.load_more')}
      </Button>
    ) : null,
  ];
}

/** /sportsbook/exchange — offers to take, the member's own, and challenges. */
export class ExchangePage extends BookPage {
  tab = 'exchange';
  scope: 'open' | 'mine' | 'challenges' = 'open';
  offers: Offer[] | null = null;
  more = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.load();
  }

  load(append = false) {
    get('/offers', { scope: this.scope, offset: append ? (this.offers ?? []).length : 0 }).then((r) => {
      this.offers = append ? [...(this.offers ?? []), ...r.data] : r.data;
      this.more = r.meta.more;
      m.redraw();
    });
  }

  content(): Mithril.Children {
    const scopes = app.session.user ? (['open', 'mine', 'challenges'] as const) : (['open'] as const);

    return (
      <div>
        <p className="Longshot-intro">{t('forum.exchange.explain')}</p>
        <div className="Longshot-segmented" role="tablist">
          {scopes.map((s) => (
            <button
              type="button"
              role="tab"
              aria-selected={this.scope === s ? 'true' : 'false'}
              className={classList({ active: this.scope === s })}
              onclick={() => {
                this.scope = s;
                this.offers = null;
                this.load();
              }}
            >
              {t('forum.exchange.' + s)}
            </button>
          ))}
        </div>
        {this.offers === null ? (
          <LoadingIndicator />
        ) : this.offers.length ? (
          this.offers.map((offer) => <OfferCard key={offer.id} offer={offer} onchange={() => this.load()} />)
        ) : (
          <Placeholder text={t('forum.exchange.none')} />
        )}
        {this.more ? (
          <Button className="Button Button--block" onclick={() => this.load(true)}>
            {t('forum.load_more')}
          </Button>
        ) : null}
        <p className="Longshot-muted">{t('forum.exchange.how_to_offer')}</p>
      </div>
    );
  }
}

interface Row {
  rank: number;
  user: Person;
  profit?: number;
  staked?: number;
  settled?: number;
  winRate?: number;
  roi?: number;
  balance?: number;
}

/** /sportsbook/leaderboard */
export class LeaderboardPage extends BookPage {
  tab = 'leaderboard';
  period: 'week' | 'month' | 'all' = 'week';
  by: 'profit' | 'balance' = 'profit';
  rows: Row[] | null = null;
  me: Row | null = null;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.load();
  }

  load() {
    this.rows = null;
    get('/leaderboard', { period: this.period, by: this.by }).then((r) => {
      this.rows = r.data;
      this.me = r.meta.me;
      this.by = r.meta.by;
      m.redraw();
    });
  }

  content(): Mithril.Children {
    const balance = this.by === 'balance';
    const own = wallet.current?.currency !== 'points';

    return (
      <div>
        <div className="Longshot-chips">
          {(['week', 'month', 'all'] as const).map((p) => (
            <button
              type="button"
              className={classList('Longshot-chip', { active: !balance && this.period === p })}
              onclick={() => {
                this.period = p;
                this.by = 'profit';
                this.load();
              }}
            >
              {t('forum.leaderboard.' + p)}
            </button>
          ))}
          {own ? (
            <button
              type="button"
              className={classList('Longshot-chip', { active: balance })}
              onclick={() => {
                this.by = 'balance';
                this.load();
              }}
            >
              {t('forum.leaderboard.balance')}
            </button>
          ) : null}
        </div>

        {this.rows === null ? (
          <LoadingIndicator />
        ) : !this.rows.length ? (
          <Placeholder text={t('forum.leaderboard.empty')} />
        ) : (
          <table className="Longshot-table">
            <thead>
              <tr>
                <th>#</th>
                <th>{t('forum.leaderboard.member')}</th>
                {balance ? (
                  <th>{t('forum.leaderboard.balance')}</th>
                ) : (
                  [
                    <th>{t('forum.leaderboard.profit')}</th>,
                    <th className="Longshot-hide-phone">{t('forum.leaderboard.settled')}</th>,
                    <th className="Longshot-hide-phone">{t('forum.leaderboard.win_rate')}</th>,
                    <th>{t('forum.leaderboard.roi')}</th>,
                  ]
                )}
              </tr>
            </thead>
            <tbody>
              {this.rows.map((row) => (
                <tr className={classList({ 'is-me': row.user.id === Number(app.session.user?.id()) })}>
                  <td>{row.rank}</td>
                  <td>{person(row.user)}</td>
                  {balance ? (
                    <td>{points(row.balance)}</td>
                  ) : (
                    [
                      <td className={classList({ 'is-up': (row.profit ?? 0) > 0, 'is-down': (row.profit ?? 0) < 0 })}>{signed(row.profit ?? 0)}</td>,
                      <td className="Longshot-hide-phone">{row.settled}</td>,
                      <td className="Longshot-hide-phone">{row.winRate}%</td>,
                      <td>{row.roi}%</td>,
                    ]
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        )}

        {this.me ? (
          <p className="Longshot-me">
            {balance
              ? t('forum.leaderboard.you_balance', { rank: this.me.rank, value: points(this.me.balance) })
              : t('forum.leaderboard.you', { rank: this.me.rank, value: signed(this.me.profit ?? 0) })}
          </p>
        ) : null}
      </div>
    );
  }
}

function signed(n: number): string {
  return (n > 0 ? '+' : '') + n.toLocaleString();
}

interface LedgerLine {
  id: number;
  amount: number;
  kind: string;
  ref: string;
  createdAt: string;
}

/** /sportsbook/wallet — balance, limits, the ledger, privacy. */
export class WalletPage extends BookPage {
  tab = 'wallet';
  data: Wallet | null = null;
  ledger: LedgerLine[] = [];
  more = false;
  saving = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.load();
  }

  load(append = false) {
    get('/wallet', { offset: append ? this.ledger.length : 0 }).then((r) => {
      this.data = r.data;
      this.ledger = append ? [...this.ledger, ...r.meta.ledger] : r.meta.ledger;
      this.more = r.meta.more;
      m.redraw();
    });
  }

  content(): Mithril.Children {
    const w = this.data;
    if (!w) return <LoadingIndicator />;

    return (
      <div className="Longshot-wallet">
        <dl className="Longshot-facts">
          <dt>{t('forum.wallet.balance')}</dt>
          <dd>
            <strong>{points(w.balance)}</strong>
            {w.currency === 'points' ? <span className="Longshot-muted"> {t('forum.wallet.gamification')}</span> : null}
          </dd>
          <dt>{t('forum.wallet.allowance')}</dt>
          <dd>
            {w.allowance > 0
              ? t(w.allowanceMode === 'topup' ? 'forum.wallet.allowance_topup' : 'forum.wallet.allowance_add', {
                  value: points(w.allowance),
                  next: when(w.nextAllowance),
                })
              : t('forum.wallet.no_allowance')}
          </dd>
          <dt>{t('forum.wallet.max_stake')}</dt>
          <dd>{points(w.maxStake)}</dd>
          {w.dailyLimit > 0
            ? [
                <dt>{t('forum.wallet.daily_limit')}</dt>,
                <dd>{t('forum.wallet.daily_used', { used: points(w.stakedToday ?? 0), limit: points(w.dailyLimit) })}</dd>,
              ]
            : null}
        </dl>

        <Switch
          state={w.private}
          loading={this.saving}
          onchange={(value: boolean) => {
            this.saving = true;
            send('PATCH', '/wallet', { private: value })
              .then((r) => (this.data = r.data))
              .finally(() => {
                this.saving = false;
                m.redraw();
              });
          }}
        >
          {t('forum.wallet.private')}
        </Switch>

        <h3>{t('forum.wallet.ledger')}</h3>
        {this.ledger.length ? (
          <table className="Longshot-table Longshot-ledger">
            <tbody>
              {this.ledger.map((line) => (
                <tr>
                  <td>{t('forum.wallet.kind.' + line.kind)}</td>
                  <td className={classList({ 'is-up': line.amount > 0, 'is-down': line.amount < 0 })}>{signed(line.amount)}</td>
                  <td className="Longshot-muted">{when(line.createdAt)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <Placeholder text={t('forum.wallet.no_ledger')} />
        )}
        {this.more ? (
          <Button className="Button Button--block" onclick={() => this.load(true)}>
            {t('forum.load_more')}
          </Button>
        ) : null}
        <p className="Longshot-note">{t('forum.points_only_long')}</p>
      </div>
    );
  }
}

/** /u/:username/bets — a member's settled bets, when they share them. */
export class UserBetsPage extends UserPage {
  bets: Bet[] | null = null;
  hidden = false;
  more = false;
  loading = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.loadUser(m.route.param('username'));
  }

  show(user: any) {
    super.show(user);
    if (this.bets === null && !this.loading) this.load(true);
  }

  load(reset = false) {
    if (!this.user) return;
    this.loading = true;
    get('/bets', { user: this.user.id(), status: 'settled', offset: reset ? 0 : (this.bets ?? []).length }).then((r) => {
      this.bets = reset ? r.data : [...(this.bets ?? []), ...r.data];
      this.hidden = !!r.meta.hidden;
      this.more = r.meta.more;
      this.loading = false;
      m.redraw();
    });
  }

  content(): Mithril.Children {
    if (this.hidden) return <Placeholder text={t('forum.bets.private')} />;

    return <div className="LongshotUserBets">{betList(this.bets, this.more, this.loading, () => this.load())}</div>;
  }
}

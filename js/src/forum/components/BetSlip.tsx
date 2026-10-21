import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { formatOdds, parlay, payout } from '../../common/odds';
import { t } from '../../common/t';
import { refusal, send, wallet } from '../api';
import { points } from '../labels';
import { prefs } from '../prefs';
import { slip } from '../state/slip';

/**
 * The bet slip: singles (a stake each) or one parlay (one stake). What it
 * says a bet returns is worked out exactly as the server will pay it.
 *
 * On a phone it is a bar along the bottom that opens into a sheet.
 */
export default class BetSlip extends Component {
  loading = false;
  error: string | null = null;
  placed: number | null = null;

  view(): Mithril.Children {
    const legs = slip.legs;
    const w = wallet.current;
    const parlayMode = slip.mode === 'parlay';
    const total = this.totalStake();
    const returns = this.returns();

    return (
      <aside
        className={classList('Longshot-slip', { 'is-open': slip.open, 'is-empty': !legs.length })}
        aria-label={extractText(t('forum.slip.title'))}
      >
        <button type="button" className="Longshot-slip-bar" onclick={() => (slip.open = !slip.open)} aria-expanded={slip.open ? 'true' : 'false'}>
          <span>{t('forum.slip.title')}</span>
          {legs.length ? <span className="Longshot-badge">{legs.length}</span> : null}
          {returns ? <span className="Longshot-slip-bar-returns">{t('forum.slip.returns_short', { value: points(returns) })}</span> : null}
        </button>

        <div className="Longshot-slip-body">
          <header className="Longshot-slip-head">
            <h3>{t('forum.slip.title')}</h3>
            {legs.length ? (
              <Button className="Button Button--link" onclick={() => this.clear()}>
                {t('forum.slip.clear')}
              </Button>
            ) : null}
          </header>

          {this.placed !== null ? <p className="Longshot-slip-placed">{t('forum.slip.placed', { count: this.placed })}</p> : null}

          {!legs.length ? <p className="Longshot-slip-empty">{t('forum.slip.empty')}</p> : null}

          {legs.length > 1 ? (
            <div className="Longshot-segmented" role="tablist">
              {(['single', 'parlay'] as const).map((mode) => (
                <button
                  type="button"
                  role="tab"
                  aria-selected={slip.mode === mode ? 'true' : 'false'}
                  className={classList({ active: slip.mode === mode })}
                  onclick={() => {
                    slip.mode = mode;
                    slip.save();
                  }}
                >
                  {t('forum.slip.' + mode)}
                </button>
              ))}
            </div>
          ) : null}

          <ul className="Longshot-slip-legs">
            {legs.map((leg) => (
              <li key={leg.selection} className="Longshot-slip-leg">
                <div className="Longshot-slip-leg-text">
                  <strong>{leg.label}</strong>
                  <span>
                    {leg.market} · {leg.event.name}
                  </span>
                </div>
                <span className="Longshot-slip-leg-odds">{formatOdds(leg.odds, prefs.odds)}</span>
                <Button
                  className="Button Button--icon Button--link"
                  icon="fas fa-times"
                  aria-label={extractText(t('forum.slip.remove'))}
                  onclick={() => slip.remove(leg.selection)}
                />
                {!parlayMode ? this.stakeInput(leg.stake, (v) => (leg.stake = v), leg.odds) : null}
              </li>
            ))}
          </ul>

          {parlayMode ? (
            <div className="Longshot-slip-parlay">
              {slip.sameEvent() ? <p className="Longshot-slip-error">{t('forum.slip.same_event')}</p> : null}
              {this.stakeInput(slip.parlayStake, (v) => (slip.parlayStake = v))}
            </div>
          ) : null}

          {this.error ? (
            <p className="Longshot-slip-error" role="alert">
              {this.error}
            </p>
          ) : null}

          {legs.length ? (
            <footer className="Longshot-slip-foot">
              <dl>
                <dt>{t('forum.slip.total_stake')}</dt>
                <dd>{points(total)}</dd>
                <dt>{t('forum.slip.returns')}</dt>
                <dd>
                  <strong>{points(returns)}</strong>
                </dd>
              </dl>
              {this.button(total)}
            </footer>
          ) : null}

          <p className="Longshot-note">{t('forum.points_only')}</p>
        </div>
      </aside>
    );
  }

  stakeInput(value: string, set: (v: string) => void, odds?: number): Mithril.Children {
    const stake = parseInt(value, 10);

    return (
      <label className="Longshot-stake">
        <span>{t('forum.slip.stake')}</span>
        <input
          className="FormControl"
          type="number"
          inputmode="numeric"
          min={wallet.current?.minStake ?? 1}
          max={wallet.current?.maxStake}
          step="1"
          value={value}
          placeholder="0"
          oninput={(e: InputEvent) => {
            set((e.target as HTMLInputElement).value.replace(/[^0-9]/g, ''));
            this.error = null;
            slip.save();
          }}
        />
        {odds !== undefined && stake > 0 ? (
          <span className="Longshot-stake-returns">{t('forum.slip.returns_short', { value: points(payout(stake, odds)) })}</span>
        ) : null}
      </label>
    );
  }

  button(total: number): Mithril.Children {
    const w = wallet.current;

    if (!app.session.user) {
      return (
        <Button className="Button Button--primary Button--block" onclick={() => app.modal.show(() => import('flarum/forum/components/LogInModal'))}>
          {t('forum.slip.log_in')}
        </Button>
      );
    }

    if (w && !w.canBet) return <p className="Longshot-note">{t('forum.slip.no_permission')}</p>;

    const short = w ? total > w.balance : false;
    const ready = total > 0 && !short && (slip.mode === 'single' ? slip.legs.every((l) => parseInt(l.stake, 10) > 0) : !slip.sameEvent());

    return (
      <Button className="Button Button--primary Button--block" loading={this.loading} disabled={!ready} onclick={() => this.place()}>
        {short ? t('forum.slip.not_enough') : t('forum.slip.place', { count: slip.mode === 'parlay' ? 1 : slip.legs.length })}
      </Button>
    );
  }

  totalStake(): number {
    if (slip.mode === 'parlay') return parseInt(slip.parlayStake, 10) || 0;
    return slip.legs.reduce((sum, l) => sum + (parseInt(l.stake, 10) || 0), 0);
  }

  returns(): number {
    if (slip.mode === 'parlay') {
      const stake = parseInt(slip.parlayStake, 10) || 0;
      return stake && slip.legs.length > 1
        ? parlay(
            stake,
            slip.legs.map((l) => l.odds)
          )
        : 0;
    }

    return slip.legs.reduce((sum, l) => sum + (parseInt(l.stake, 10) > 0 ? payout(parseInt(l.stake, 10), l.odds) : 0), 0);
  }

  clear() {
    slip.clear();
    this.error = null;
  }

  place() {
    this.loading = true;
    this.error = null;
    this.placed = null;

    const parlayMode = slip.mode === 'parlay';
    const nonce = Math.random().toString(36).slice(2) + Date.now().toString(36);

    send('POST', '/bets', {
      kind: slip.mode,
      stake: parlayMode ? parseInt(slip.parlayStake, 10) || 0 : 0,
      nonce,
      legs: slip.legs.map((l) => ({ selection: l.selection, odds: l.odds, line: l.line, stake: parlayMode ? 0 : parseInt(l.stake, 10) || 0 })),
    })
      .then((response) => {
        this.placed = response.data.length;
        slip.clear();
      })
      .catch((error) => {
        const { message, prices } = refusal(error);
        this.error = message;
        if (prices.length) slip.reprice(prices);
      })
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}

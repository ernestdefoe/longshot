import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Link from 'flarum/common/components/Link';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import type { Bet } from '../../common/types';
import { formatOdds } from '../../common/odds';
import { t } from '../../common/t';
import { marketLabel, points, selectionLabel, when } from '../labels';
import { prefs } from '../prefs';

export interface BetCardAttrs extends ComponentAttrs {
  bet: Bet;
}

/** A bet in a member's history: its legs, what it cost and what it paid. */
export default class BetCard extends Component<BetCardAttrs> {
  view(): Mithril.Children {
    const bet = this.attrs.bet;

    return (
      <article className={classList('Longshot-bet', 'is-' + bet.status)}>
        <header className="Longshot-bet-head">
          <span className="Longshot-bet-kind">
            {bet.kind === 'parlay' ? t('forum.bet.parlay', { count: bet.legs.length }) : t('forum.bet.single')}
          </span>
          <span className={classList('Longshot-pill', 'is-' + bet.status)}>{t('forum.bet.status.' + bet.status)}</span>
        </header>

        <ul className="Longshot-bet-legs">
          {bet.legs.map((leg) => (
            <li className={classList('Longshot-bet-leg', leg.result ? 'is-' + leg.result : '')}>
              <span className="Longshot-bet-leg-pick">
                <strong>{selectionLabel(leg.event, leg.market.type, leg.selection.key, leg.selection.label, leg.line)}</strong>{' '}
                <span className="Longshot-muted">{formatOdds(leg.odds, prefs.odds)}</span>
              </span>
              <span className="Longshot-bet-leg-event">
                {marketLabel(leg.market.type, leg.market.label)} ·{' '}
                <Link href={app.route('longshot.event', { id: leg.event.id })}>{leg.event.name}</Link>
                {leg.event.score ? ` · ${leg.event.score.away}–${leg.event.score.home}` : null}
              </span>
            </li>
          ))}
        </ul>

        <footer className="Longshot-bet-foot">
          <span>
            {t('forum.bet.stake')} <strong>{points(bet.stake)}</strong>
          </span>
          {bet.status === 'open' ? (
            <span>
              {t('forum.bet.to_return')} <strong>{points(bet.potential)}</strong>
            </span>
          ) : (
            <span>
              {t('forum.bet.returned')} <strong>{points(bet.payout ?? 0)}</strong>
            </span>
          )}
          <time className="Longshot-muted" dateTime={bet.placedAt}>
            {when(bet.placedAt)}
          </time>
        </footer>
      </article>
    );
  }
}

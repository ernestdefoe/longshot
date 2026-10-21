import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import type { Offer } from '../../common/types';
import { formatOdds, profit } from '../../common/odds';
import { t } from '../../common/t';
import { refusal, send, wallet } from '../api';
import { marketLabel, points, selectionLabel, when } from '../labels';
import { prefs } from '../prefs';
import person from './Avatar';

export interface OfferCardAttrs extends ComponentAttrs {
  offer: Offer;
  onchange: () => void;
}

/**
 * An offer on the exchange. A member who lays it takes the other side: they
 * put up the backer's profit, and win the backer's stake if the pick loses.
 */
export default class OfferCard extends Component<OfferCardAttrs> {
  amount = '';
  loading = false;
  error: string | null = null;

  view(): Mithril.Children {
    const offer = this.attrs.offer;
    const back = parseInt(this.amount, 10) || 0;
    const risk = back > 0 ? profit(back, offer.odds) : 0;
    const pick = selectionLabel(offer.event, offer.market.type, offer.selection.key, offer.selection.label, offer.line);
    const w = wallet.current;
    const canTake = offer.status === 'open' && !offer.mine && offer.event.bettable && !!app.session.user && !!w?.canExchange;

    return (
      <article className={classList('Longshot-offer', 'is-' + offer.status, { 'is-challenge': offer.target })}>
        <header className="Longshot-offer-head">
          {person(offer.user)}
          <span className="Longshot-muted">
            {offer.target ? t('forum.offer.challenges', { name: offer.target.displayName }) : t('forum.offer.backs')}
          </span>
          <time className="Longshot-muted" dateTime={offer.createdAt}>
            {when(offer.createdAt)}
          </time>
        </header>

        <p className="Longshot-offer-pick">
          <strong>{pick}</strong> <span className="Longshot-odds-price">{formatOdds(offer.odds, prefs.odds)}</span>
          <span className="Longshot-muted">
            {' '}
            · {marketLabel(offer.market.type, offer.market.label)} ·{' '}
            <Link href={app.route('longshot.event', { id: offer.event.id })}>{offer.event.name}</Link>
          </span>
        </p>

        <p className="Longshot-offer-figures">
          {t('forum.offer.figures', { stake: points(offer.stake), matched: points(offer.matched), remaining: points(offer.remaining) })}
        </p>

        {offer.matches.length ? (
          <ul className="Longshot-offer-matches">
            {offer.matches.map((match) => (
              <li>
                {person(match.user)} {t('forum.offer.laid', { back: points(match.back), lay: points(match.lay) })}
                {match.result ? (
                  <span className={classList('Longshot-pill', 'is-' + match.result)}>{t('forum.offer.result.' + match.result)}</span>
                ) : null}
              </li>
            ))}
          </ul>
        ) : null}

        {canTake ? (
          <div className="Longshot-offer-take">
            <label className="Longshot-stake">
              <span>{t('forum.offer.match_label')}</span>
              <input
                className="FormControl"
                type="number"
                inputmode="numeric"
                min="1"
                max={offer.remaining}
                value={this.amount}
                placeholder={String(offer.remaining)}
                oninput={(e: InputEvent) => {
                  this.amount = (e.target as HTMLInputElement).value.replace(/[^0-9]/g, '');
                  this.error = null;
                }}
              />
            </label>
            <p className="Longshot-muted">{back ? t('forum.offer.you_risk', { risk: points(risk), win: points(back) }) : t('forum.offer.explain')}</p>
            <Button
              className="Button Button--primary"
              loading={this.loading}
              disabled={!back || back > offer.remaining}
              onclick={() => this.take(back)}
            >
              {t('forum.offer.lay')}
            </Button>
            {this.error ? (
              <p className="Longshot-slip-error" role="alert">
                {this.error}
              </p>
            ) : null}
          </div>
        ) : null}

        {offer.mine && offer.status === 'open' ? (
          <Button className="Button" loading={this.loading} onclick={() => this.cancel()}>
            {offer.matched ? t('forum.offer.cancel_rest') : t('forum.offer.cancel')}
          </Button>
        ) : null}
      </article>
    );
  }

  take(back: number) {
    this.loading = true;
    send('POST', `/offers/${this.attrs.offer.id}/matches`, { back })
      .then(() => {
        this.amount = '';
        this.attrs.onchange();
      })
      .catch((e) => (this.error = refusal(e).message))
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  cancel() {
    if (!confirm(extractText(t('forum.offer.cancel_confirm')))) return;

    this.loading = true;
    send('DELETE', `/offers/${this.attrs.offer.id}`)
      .then(() => this.attrs.onchange())
      .catch((e) => (this.error = refusal(e).message))
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}

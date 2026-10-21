import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import type { BookEvent } from '../../common/types';
import { formatOdds, profit } from '../../common/odds';
import { t } from '../../common/t';
import { refusal, send } from '../api';
import { marketLabel, points, selectionLabel } from '../labels';

export interface OfferFormAttrs extends ComponentAttrs {
  event: BookEvent;
  onchange: () => void;
}

/**
 * Back a pick at a price you name, for anyone to take, or as a challenge to
 * one member. Your stake is held until it is matched, cancelled or the game
 * starts.
 */
export default class OfferForm extends Component<OfferFormAttrs> {
  selection = '';
  odds = '';
  stake = '';
  target = '';
  loading = false;
  error: string | null = null;
  done = false;

  oninit(vnode: Mithril.Vnode<OfferFormAttrs, this>) {
    super.oninit(vnode);
    const target = m.route.param('challenge');
    if (target) this.target = String(target);
  }

  view(): Mithril.Children {
    const event = this.attrs.event;
    const choices = (event.markets ?? [])
      .filter((mk) => mk.status === 'open')
      .flatMap((mk) => mk.selections.map((s) => ({ market: mk, selection: s })));

    const odds = parseInt(this.odds, 10);
    const stake = parseInt(this.stake, 10) || 0;
    const valid = Number.isInteger(odds) && Math.abs(odds) >= 100 && Math.abs(odds) <= 10000;
    const chosen = choices.find((c) => String(c.selection.id) === this.selection);

    return (
      <form className="Longshot-offer-form" onsubmit={(e: Event) => this.submit(e, chosen?.market.line ?? null)}>
        <h3>{t('forum.offer.make_title')}</h3>
        <p className="Longshot-muted">{t('forum.offer.make_help')}</p>

        <label>
          <span>{t('forum.offer.pick')}</span>
          <select
            className="FormControl"
            value={this.selection}
            onchange={(e: Event) => {
              this.selection = (e.target as HTMLSelectElement).value;
              const c = choices.find((x) => String(x.selection.id) === this.selection);
              if (c?.selection.odds && !this.odds) this.odds = String(c.selection.odds);
            }}
          >
            <option value="">{t('forum.offer.pick_placeholder')}</option>
            {choices.map((c) => (
              <option value={String(c.selection.id)}>
                {marketLabel(c.market.type, c.market.label)}:{' '}
                {selectionLabel(event, c.market.type, c.selection.key, c.selection.label, c.market.line)}
                {c.selection.odds !== null ? ` (${formatOdds(c.selection.odds)})` : ''}
              </option>
            ))}
          </select>
        </label>

        <div className="Longshot-form-row">
          <label>
            <span>{t('forum.offer.your_odds')}</span>
            <input
              className="FormControl"
              type="text"
              inputmode="numeric"
              placeholder="+150"
              value={this.odds}
              oninput={(e: InputEvent) => (this.odds = (e.target as HTMLInputElement).value.replace(/[^0-9+-]/g, ''))}
            />
          </label>
          <label>
            <span>{t('forum.offer.your_stake')}</span>
            <input
              className="FormControl"
              type="number"
              inputmode="numeric"
              min="1"
              value={this.stake}
              oninput={(e: InputEvent) => (this.stake = (e.target as HTMLInputElement).value.replace(/[^0-9]/g, ''))}
            />
          </label>
        </div>

        <label>
          <span>{t('forum.offer.challenge')}</span>
          <input
            className="FormControl"
            type="text"
            placeholder={extractText(t('forum.offer.challenge_placeholder'))}
            value={this.target}
            oninput={(e: InputEvent) => (this.target = (e.target as HTMLInputElement).value)}
          />
        </label>

        {valid && stake ? (
          <p className="Longshot-muted">{t('forum.offer.preview', { stake: points(stake), win: points(profit(stake, odds)) })}</p>
        ) : null}
        {this.error ? (
          <p className="Longshot-slip-error" role="alert">
            {this.error}
          </p>
        ) : null}
        {this.done ? <p className="Longshot-slip-placed">{t('forum.offer.made')}</p> : null}

        <Button type="submit" className="Button Button--primary" loading={this.loading} disabled={!chosen || !valid || !stake || !app.session.user}>
          {this.target.trim() ? t('forum.offer.send_challenge') : t('forum.offer.make')}
        </Button>
      </form>
    );
  }

  submit(e: Event, line: string | null) {
    e.preventDefault();
    this.loading = true;
    this.error = null;
    this.done = false;

    send('POST', '/offers', {
      selection: parseInt(this.selection, 10),
      odds: parseInt(this.odds, 10),
      stake: parseInt(this.stake, 10),
      line,
      target: this.target.trim() || null,
    })
      .then(() => {
        this.done = true;
        this.stake = '';
        this.attrs.onchange();
      })
      .catch((err) => (this.error = refusal(err).message))
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}

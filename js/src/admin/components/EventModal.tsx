import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import extractText from 'flarum/common/utils/extractText';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import Switch from 'flarum/common/components/Switch';
import type Mithril from 'mithril';
import type { BookEvent, MarketType } from '../../common/types';
import { t } from '../../common/t';
import { adminSend } from '../api';

interface Attrs extends IFormModalAttrs {
  event?: BookEvent;
  onsave: () => void;
}

interface EditSelection {
  id?: number;
  key: string;
  label: string;
  odds: string;
}

interface EditMarket {
  id?: number;
  type: MarketType;
  label: string;
  line: string;
  status: 'open' | 'suspended';
  feed: boolean;
  locked: boolean;
  selections: EditSelection[];
}

const KEYS: Record<string, string[]> = { moneyline: ['away', 'home'], spread: ['away', 'home'], total: ['over', 'under'] };

/** Create or edit an event and its markets. */
export default class EventModal extends FormModal<Attrs> {
  matchup = true;
  title_ = '';
  home = '';
  away = '';
  league = '';
  startsAt = '';
  markets: EditMarket[] = [];
  linked = false;
  imported = false;
  locked = false;

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    const e = this.attrs.event;

    if (!e) {
      const soon = new Date(Date.now() + 86400000);
      soon.setMinutes(0, 0, 0);
      this.startsAt = local(soon.toISOString());
      this.markets = [this.blank('moneyline')];
      return;
    }

    this.matchup = !!e.home;
    this.title_ = e.title ?? '';
    this.home = e.home?.name ?? '';
    this.away = e.away?.name ?? '';
    this.league = e.league;
    this.startsAt = local(e.startsAt);
    this.linked = e.linked;
    this.imported = !!e.source && e.source !== 'manual';
    this.locked = !!e.locked;
    this.markets = (e.markets ?? []).map((mk) => ({
      id: mk.id,
      type: mk.type,
      label: mk.label ?? '',
      line: mk.line ?? '',
      status: mk.status === 'suspended' ? 'suspended' : 'open',
      feed: mk.feed,
      locked: mk.status === 'settled' || mk.status === 'void',
      selections: mk.selections.map((s) => ({ id: s.id, key: s.key, label: s.label ?? '', odds: s.odds === null ? '' : String(s.odds) })),
    }));
  }

  className() {
    return 'Modal--large LongshotEventModal';
  }

  title() {
    return this.attrs.event ? t('admin.event.edit_title') : t('admin.event.new_title');
  }

  blank(type: MarketType): EditMarket {
    const keys = KEYS[type] ?? [];

    return {
      type,
      label: '',
      line: type === 'spread' ? '-3.5' : type === 'total' ? '44.5' : '',
      status: 'open',
      feed: false,
      locked: false,
      selections:
        type === 'custom'
          ? [
              { key: '', label: '', odds: '' },
              { key: '', label: '', odds: '' },
            ]
          : keys.map((key) => ({ key, label: '', odds: '-110' })),
    };
  }

  content(): Mithril.Children {
    return (
      <div className="Modal-body">
        {!this.attrs.event ? (
          <div className="Form-group">
            <Switch state={this.matchup} onchange={(v: boolean) => (this.matchup = v)}>
              {t('admin.event.matchup')}
            </Switch>
          </div>
        ) : null}

        {this.imported ? (
          <div className="Form-group">
            <Switch state={this.locked} onchange={(v: boolean) => (this.locked = v)}>
              {t('admin.event.lock')}
            </Switch>
            <p className="helpText">{t(this.locked ? 'admin.event.locked_help' : 'admin.event.linked_help', { source: this.attrs.event?.source })}</p>
          </div>
        ) : null}

        {this.matchup ? (
          <div className="Longshot-form-row">
            {this.input('away', t('admin.event.away'), this.imported && !this.locked)}
            {this.input('home', t('admin.event.home'), this.imported && !this.locked)}
          </div>
        ) : (
          this.input('title_', t('admin.event.title'))
        )}

        <div className="Longshot-form-row">
          {this.input('league', t('admin.event.league'))}
          <div className="Form-group">
            <label>{t('admin.event.starts')}</label>
            <input
              className="FormControl"
              type="datetime-local"
              disabled={this.linked}
              value={this.startsAt}
              oninput={(e: InputEvent) => (this.startsAt = (e.target as HTMLInputElement).value)}
            />
            <p className="helpText">{t('admin.event.starts_help')}</p>
          </div>
        </div>

        <h4>{t('admin.event.markets')}</h4>
        {this.markets.map((mk, i) => this.market(mk, i))}

        <div className="Longshot-manager-bar">
          {(this.matchup ? (['moneyline', 'spread', 'total', 'custom'] as const) : (['custom'] as const)).map((type) => (
            <Button className="Button" icon="fas fa-plus" onclick={() => this.markets.push(this.blank(type))}>
              {t('admin.event.add_' + type)}
            </Button>
          ))}
        </div>

        <Button className="Button Button--primary" type="submit" loading={this.loading}>
          {t('admin.event.save')}
        </Button>
      </div>
    );
  }

  input(field: 'title_' | 'home' | 'away' | 'league', label: Mithril.Children, disabled = false): Mithril.Children {
    return (
      <div className="Form-group">
        <label>{label}</label>
        <input
          className="FormControl"
          maxlength={field === 'league' ? 60 : 200}
          disabled={disabled}
          value={this[field]}
          oninput={(e: InputEvent) => (this[field] = (e.target as HTMLInputElement).value)}
        />
      </div>
    );
  }

  market(mk: EditMarket, index: number): Mithril.Children {
    const fixed = mk.type !== 'custom';
    const hasDraw = mk.type === 'moneyline' && mk.selections.some((s) => s.key === 'draw');

    return (
      <fieldset className="Longshot-editMarket" disabled={mk.locked}>
        <legend>
          {mk.type === 'custom' ? t('admin.event.custom') : t('admin.event.type_' + mk.type)}
          {mk.locked ? ` · ${t('admin.event.finished')}` : ''}
        </legend>

        <div className="Longshot-form-row">
          {mk.type === 'custom' ? (
            <div className="Form-group">
              <label>{t('admin.event.market_name')}</label>
              <input
                className="FormControl"
                maxlength="150"
                value={mk.label}
                oninput={(e: InputEvent) => (mk.label = (e.target as HTMLInputElement).value)}
              />
            </div>
          ) : null}
          {mk.type === 'spread' || mk.type === 'total' ? (
            <div className="Form-group">
              <label>{mk.type === 'spread' ? t('admin.event.home_line') : t('admin.event.total_line')}</label>
              <input
                className="FormControl"
                inputmode="decimal"
                value={mk.line}
                oninput={(e: InputEvent) => (mk.line = (e.target as HTMLInputElement).value)}
              />
            </div>
          ) : null}
          <div className="Form-group">
            <label>{t('admin.event.status')}</label>
            <Select
              options={{ open: t('admin.event.open'), suspended: t('admin.event.suspended') }}
              value={mk.status}
              onchange={(v: 'open' | 'suspended') => (mk.status = v)}
            />
          </div>
          {this.imported && fixed ? (
            <div className="Form-group">
              <Switch state={mk.feed} onchange={(v: boolean) => (mk.feed = v)}>
                {t('admin.event.feed')}
              </Switch>
            </div>
          ) : null}
        </div>

        <table className="Longshot-adminTable">
          <tbody>
            {mk.selections.map((s, i) => (
              <tr>
                <td>
                  {fixed ? (
                    t('admin.event.key_' + s.key)
                  ) : (
                    <input
                      className="FormControl"
                      maxlength="150"
                      placeholder={extractText(t('admin.event.option'))}
                      value={s.label}
                      oninput={(e: InputEvent) => (s.label = (e.target as HTMLInputElement).value)}
                    />
                  )}
                </td>
                <td>
                  <input
                    className="FormControl"
                    inputmode="numeric"
                    placeholder="-110"
                    value={s.odds}
                    oninput={(e: InputEvent) => (s.odds = (e.target as HTMLInputElement).value.replace(/[^0-9+-]/g, ''))}
                  />
                </td>
                <td>
                  {!fixed || s.key === 'draw' ? (
                    <Button
                      className="Button Button--icon"
                      icon="fas fa-times"
                      aria-label={extractText(t('admin.event.remove_option'))}
                      onclick={() => mk.selections.splice(i, 1)}
                    />
                  ) : null}
                </td>
              </tr>
            ))}
          </tbody>
        </table>

        <div className="Longshot-manager-bar">
          {mk.type === 'custom' ? (
            <Button className="Button" icon="fas fa-plus" onclick={() => mk.selections.push({ key: '', label: '', odds: '' })}>
              {t('admin.event.add_option')}
            </Button>
          ) : null}
          {mk.type === 'moneyline' && !hasDraw ? (
            <Button className="Button" icon="fas fa-plus" onclick={() => mk.selections.push({ key: 'draw', label: '', odds: '+250' })}>
              {t('admin.event.add_draw')}
            </Button>
          ) : null}
          {!mk.locked ? (
            <Button className="Button Button--link" icon="fas fa-trash" onclick={() => this.markets.splice(index, 1)}>
              {t('admin.event.remove_market')}
            </Button>
          ) : null}
        </div>
      </fieldset>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;

    const body = {
      locked: this.locked,
      title: this.matchup ? null : this.title_,
      home: this.matchup ? this.home : null,
      away: this.matchup ? this.away : null,
      league: this.league,
      startsAt: new Date(this.startsAt).toISOString(),
      markets: this.markets.map((mk) => ({
        id: mk.id,
        type: mk.type,
        label: mk.label || null,
        line: mk.line || null,
        status: mk.status,
        feed: mk.feed,
        selections: mk.selections.map((s) => ({
          id: s.id,
          key: s.key,
          label: s.label || null,
          odds: s.odds === '' || s.odds === '-' ? null : parseInt(s.odds, 10),
        })),
      })),
    };

    const e2 = this.attrs.event;
    adminSend(e2 ? 'PATCH' : 'POST', e2 ? `/events/${e2.id}` : '/events', body)
      .then(() => {
        this.attrs.onsave();
        this.hide();
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}

/** An ISO time as a datetime-local value, in the browser's own zone. */
function local(iso: string): string {
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, '0');

  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

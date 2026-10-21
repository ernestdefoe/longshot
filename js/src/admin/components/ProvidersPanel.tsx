import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Switch from 'flarum/common/components/Switch';
import saveSettings from 'flarum/admin/utils/saveSettings';
import extractText from 'flarum/common/utils/extractText';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { t } from '../../common/t';
import { adminGet, adminSend } from '../api';

interface Provider {
  key: string;
  label: string;
  gives: string[];
  leagues: string[];
  needsKey: boolean;
  hasKey: boolean;
  keyFromPicks: boolean;
  keyUrl: string | null;
  termsUrl: string | null;
  enabled: boolean;
  usable: boolean;
  defaultBudget: number;
  perMinute: number;
  used: number;
  budget: number;
  period: 'day' | 'month';
  paused: string | null;
  error: { message: string; at: string } | null;
}

const P = 'ernestdefoe-longshot.provider_';

/**
 * The data providers: which are on, their keys (never shown back once
 * saved), budgets and how much of them is spent, and whether each is
 * healthy. Plus the leagues the forum follows through them.
 */
export default class ProvidersPanel extends Component {
  providers: Provider[] | null = null;
  leagues: Record<string, string> = {};
  followed: string[] = [];
  keys: Record<string, string> = {};
  budgets: Record<string, string> = {};
  saving: string | null = null;
  tested: Record<string, boolean | null> = {};

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.load();
  }

  load() {
    adminGet('/providers').then((r: any) => {
      this.providers = r.data;
      this.leagues = r.meta.leagues;
      this.followed = r.meta.followed;
      m.redraw();
    });
  }

  view(): Mithril.Children {
    if (!this.providers) return <LoadingIndicator />;

    return (
      <div className="Longshot-providers">
        <h3 className="Longshot-adminHeading">{t('admin.feed.title')}</h3>
        <p className="helpText">{t('admin.feed.help')}</p>

        <div className="Form-group">
          <label>{t('admin.feed.follow')}</label>
          <div className="helpText">{t('admin.feed.follow_help')}</div>
          <div className="Longshot-leagues">
            {Object.entries(this.leagues).map(([key, name]) => (
              <label className="checkbox">
                <input
                  type="checkbox"
                  disabled={this.saving === 'leagues'}
                  checked={this.followed.includes(key)}
                  onchange={(e: Event) => this.follow(key, (e.target as HTMLInputElement).checked)}
                />
                {name}
              </label>
            ))}
          </div>
        </div>

        {this.providers.map((p) => this.provider(p))}
      </div>
    );
  }

  provider(p: Provider): Mithril.Children {
    const state = !p.enabled ? 'off' : p.paused ? 'paused' : p.usable ? 'ready' : 'needs_key';

    return (
      <section className={classList('Longshot-provider', 'is-' + state)} key={p.key}>
        <header>
          <strong>{p.label}</strong>
          <span className={classList('Longshot-providerState', 'is-' + state)}>{t('admin.feed.state_' + state)}</span>
          <Switch
            state={p.enabled}
            loading={this.saving === p.key + 'enabled'}
            onchange={(v: boolean) => this.save(p.key + 'enabled', { [P + p.key + '_enabled']: v ? '1' : '0' })}
          >
            {t('admin.feed.enabled')}
          </Switch>
        </header>

        <p className="helpText">
          {t('admin.feed.gives', { what: p.gives.map((g) => extractText(t('admin.feed.give_' + g))).join(', ') })}{' '}
          {t('admin.feed.covers', { leagues: p.leagues.join(', ') })}
        </p>
        <p className="helpText">{t('admin.feed.about_' + p.key)}</p>

        <div className="Longshot-form-row">
          {p.key !== 'espn' ? (
            <div className="Form-group">
              <label>
                {t(p.needsKey ? 'admin.feed.key' : 'admin.feed.key_optional')}{' '}
                {p.keyUrl ? (
                  <a href={p.keyUrl} target="_blank" rel="noopener noreferrer">
                    {t('admin.feed.get_key')}
                  </a>
                ) : null}
              </label>
              <input
                className="FormControl"
                type="password"
                autocomplete="off"
                placeholder={extractText(
                  p.hasKey ? t(p.keyFromPicks ? 'admin.feed.key_from_picks' : 'admin.feed.key_saved') : t('admin.feed.key_placeholder')
                )}
                value={this.keys[p.key] ?? ''}
                oninput={(e: InputEvent) => (this.keys[p.key] = (e.target as HTMLInputElement).value)}
              />
            </div>
          ) : null}
          <div className="Form-group">
            <label>{t(p.key === 'oddsapi' ? 'admin.feed.budget_credits' : 'admin.feed.budget_' + p.period)}</label>
            <input
              className="FormControl"
              type="number"
              min="0"
              placeholder={String(p.defaultBudget)}
              value={this.budgets[p.key] ?? String(p.budget)}
              oninput={(e: InputEvent) => (this.budgets[p.key] = (e.target as HTMLInputElement).value)}
            />
            <p className="helpText">
              {t('admin.feed.used', { used: p.used, budget: p.budget, period: extractText(t('admin.feed.per_' + p.period)) })}
              {p.perMinute ? ' ' + extractText(t('admin.feed.per_minute', { count: p.perMinute })) : ''}
            </p>
          </div>
        </div>

        {p.paused ? <p className="Longshot-providerError">{t('admin.feed.paused', { until: new Date(p.paused).toLocaleTimeString() })}</p> : null}
        {p.error ? (
          <p className="Longshot-providerError">
            {t('admin.feed.last_error', { message: p.error.message, at: new Date(p.error.at).toLocaleString() })}
          </p>
        ) : null}

        <div className="Longshot-manager-bar">
          <Button className="Button" loading={this.saving === p.key} onclick={() => this.saveProvider(p)}>
            {t('admin.feed.save')}
          </Button>
          {p.key !== 'espn' && p.hasKey ? (
            <Button className="Button Button--link" onclick={() => this.save(p.key + 'clear', { [P + p.key + '_key']: '' })}>
              {t('admin.feed.clear_key')}
            </Button>
          ) : null}
          <Button className="Button Button--link" onclick={() => this.test(p)}>
            {t('admin.feed.test')}
          </Button>
          {this.tested[p.key] === true ? <span className="Longshot-ok">{t('admin.feed.test_ok')}</span> : null}
          {this.tested[p.key] === false ? <span className="Longshot-providerError">{t('admin.feed.test_failed')}</span> : null}
          {p.termsUrl ? (
            <a className="Button Button--link" href={p.termsUrl} target="_blank" rel="noopener noreferrer">
              {t('admin.feed.terms')}
            </a>
          ) : null}
        </div>
      </section>
    );
  }

  saveProvider(p: Provider) {
    const settings: Record<string, string> = {};
    const key = (this.keys[p.key] ?? '').trim();
    if (key) settings[P + p.key + '_key'] = key;
    if (this.budgets[p.key] !== undefined) settings[P + p.key + '_budget'] = String(Math.max(0, parseInt(this.budgets[p.key], 10) || 0));
    if (Object.keys(settings).length) this.save(p.key, settings);
  }

  save(what: string, settings: Record<string, string>) {
    this.saving = what;
    saveSettings(settings)
      .then(() => {
        this.keys = {};
        this.budgets = {};
        this.load();
      })
      .finally(() => {
        this.saving = null;
        m.redraw();
      });
  }

  follow(key: string, on: boolean) {
    const next = on ? [...this.followed, key] : this.followed.filter((k) => k !== key);
    this.save('leagues', { 'ernestdefoe-longshot.leagues': next.join(',') });
  }

  test(p: Provider) {
    this.tested[p.key] = null;
    adminSend('POST', `/providers/${p.key}/test`)
      .then((r: any) => (this.tested[p.key] = !!r.data.ok))
      .catch(() => (this.tested[p.key] = false))
      .finally(() => this.load());
  }
}

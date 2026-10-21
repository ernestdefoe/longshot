import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Select from 'flarum/common/components/Select';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { t } from '../../common/t';
import { adminGet, adminSend } from '../api';

interface Team {
  id: number;
  league: string;
  name: string;
  abbreviation: string | null;
  color: string | null;
  altColor: string | null;
  logo: string | null;
  logoSource: string | null;
  locked: boolean;
}

/**
 * The teams the providers brought in, with their names, colours and logos.
 * Editing one locks it, so the next sync does not put the feed's back.
 */
export default class TeamsEditor extends Component<{ leagues: Record<string, string> }> {
  league = '';
  teams: Team[] | null = null;
  editing: number | null = null;
  draft: Partial<Team> = {};
  saving = false;
  error: string | null = null;

  view(): Mithril.Children {
    const options: Record<string, string> = { '': extractText(t('admin.teams.pick_league')), ...this.attrs.leagues };

    return (
      <div className="Longshot-teams">
        <h3 className="Longshot-adminHeading">{t('admin.teams.title')}</h3>
        <p className="helpText">{t('admin.teams.help')}</p>
        <Select
          options={options}
          value={this.league}
          onchange={(v: string) => {
            this.league = v;
            this.load();
          }}
        />
        {this.league && this.teams === null ? <LoadingIndicator /> : null}
        {this.teams && !this.teams.length ? <p className="helpText">{t('admin.teams.none')}</p> : null}
        {this.teams && this.teams.length ? (
          <table className="Longshot-adminTable">
            <tbody>{this.teams.map((team) => (this.editing === team.id ? this.form(team) : this.row(team)))}</tbody>
          </table>
        ) : null}
      </div>
    );
  }

  load() {
    this.teams = null;
    if (!this.league) return;
    adminGet('/teams', { league: this.league }).then((r: any) => {
      this.teams = r.data;
      m.redraw();
    });
  }

  row(team: Team): Mithril.Children {
    return (
      <tr key={team.id}>
        <td>{team.logo ? <img src={team.logo} alt="" width="28" height="28" loading="lazy" /> : null}</td>
        <td>
          <strong>{team.name}</strong> {team.abbreviation ? <span className="helpText">{team.abbreviation}</span> : null}
          {team.locked ? <span className="helpText"> · {t('admin.teams.locked')}</span> : null}
        </td>
        <td>{[team.color, team.altColor].map((c) => (c ? <span className="Longshot-swatch" style={{ background: c }} title={c} /> : null))}</td>
        <td>
          <Button
            className="Button Button--icon"
            icon="fas fa-pen"
            aria-label={extractText(t('admin.teams.edit'))}
            onclick={() => {
              this.editing = team.id;
              this.draft = { ...team, logo: team.logoSource };
              this.error = null;
            }}
          />
        </td>
      </tr>
    );
  }

  form(team: Team): Mithril.Children {
    const field = (key: 'name' | 'abbreviation' | 'color' | 'altColor' | 'logo', label: string, type = 'text') => (
      <label>
        <span>{t('admin.teams.' + label)}</span>
        <input
          className="FormControl"
          type={type}
          value={(this.draft[key] as string) ?? ''}
          oninput={(e: InputEvent) => ((this.draft as any)[key] = (e.target as HTMLInputElement).value)}
        />
      </label>
    );

    return (
      <tr key={team.id}>
        <td colspan="4">
          <div className="Longshot-form-row">
            {field('name', 'name')}
            {field('abbreviation', 'abbreviation')}
            {field('color', 'color', 'color')}
            {field('altColor', 'alt_color', 'color')}
          </div>
          {field('logo', 'logo')}
          <p className="helpText">{t('admin.teams.logo_help')}</p>
          {this.error ? <p className="Longshot-providerError">{this.error}</p> : null}
          <div className="Longshot-manager-bar">
            <Button className="Button Button--primary" loading={this.saving} onclick={() => this.save(team, true)}>
              {t('admin.teams.save')}
            </Button>
            {team.locked ? (
              <Button className="Button" onclick={() => this.save(team, false)}>
                {t('admin.teams.unlock')}
              </Button>
            ) : null}
            <Button className="Button Button--link" onclick={() => (this.editing = null)}>
              {t('admin.teams.cancel')}
            </Button>
          </div>
        </td>
      </tr>
    );
  }

  save(team: Team, lock: boolean) {
    this.saving = true;
    this.error = null;
    adminSend('PATCH', `/teams/${team.id}`, { ...this.draft, locked: lock })
      .then(() => {
        this.editing = null;
        this.load();
      })
      .catch((e: any) => (this.error = e?.response?.errors?.[0]?.detail ?? extractText(t('admin.teams.failed'))))
      .finally(() => {
        this.saving = false;
        m.redraw();
      });
  }
}

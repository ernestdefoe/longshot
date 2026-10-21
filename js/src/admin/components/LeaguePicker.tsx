import app from 'flarum/admin/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import saveSettings from 'flarum/admin/utils/saveSettings';
import type Mithril from 'mithril';
import { t } from '../../common/t';

export interface LeaguePickerAttrs extends ComponentAttrs {
  leagues: Record<string, string>;
}

const KEY = 'ernestdefoe-longshot.picks_leagues';

/**
 * Which of Picks' leagues to offer; none ticked means all of them. Saved as
 * soon as a box is ticked, like a permission.
 */
export default class LeaguePicker extends Component<LeaguePickerAttrs> {
  saving = false;

  view(): Mithril.Children {
    const current = String(app.data.settings[KEY] || '')
      .split(',')
      .filter(Boolean);

    return (
      <div className="Form-group">
        <label>{t('admin.settings.picks_leagues')}</label>
        <div className="helpText">{t('admin.settings.picks_leagues_help')}</div>
        <div className="Longshot-leagues">
          {Object.entries(this.attrs.leagues).map(([league, name]) => (
            <label className="checkbox">
              <input
                type="checkbox"
                disabled={this.saving}
                checked={current.includes(league)}
                onchange={(e: Event) =>
                  this.save((e.target as HTMLInputElement).checked ? [...current, league] : current.filter((l) => l !== league))
                }
              />
              {name}
            </label>
          ))}
        </div>
      </div>
    );
  }

  save(leagues: string[]) {
    this.saving = true;
    saveSettings({ [KEY]: leagues.join(',') }).finally(() => {
      this.saving = false;
      m.redraw();
    });
  }
}
